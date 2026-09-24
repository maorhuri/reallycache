<?php
namespace ReallyCache\Compat;

use ReallyCache\Config;

/**
 * Empirical cart-compatibility probe, verified end-to-end against live
 * stores. Question: with items in the cart, is the rendered page the same
 * page — once you ignore the regions WooCommerce fragments own anyway?
 *
 *   1. Live-render the homepage twice (cache bypassed via a probe param):
 *      once cookie-less, once carrying a real probe cart built through
 *      wc-ajax add_to_cart on an in-stock simple product (5 candidates).
 *   2. Ask ?wc-ajax=get_refreshed_fragments (both states) which page
 *      regions belong to the fragments JS, and blank those regions in
 *      both renders (balanced-tag, nesting-safe) — the JS overwrites
 *      them on every page load, cached or not.
 *   3. Normalize per-request noise (nonces, hex tokens, analytics
 *      scripts that embed cart state) and compare byte-for-byte.
 *
 * Equal → cart_cache_ok: visitors with a cart get cached pages, and the
 * fragments JS paints their real cart on load — ezCache's speed with an
 * actual proof behind it. Any residue, fetch failure or unpurchasable
 * catalog keeps the safe mode. Re-runs weekly and on theme switch; the
 * dashboard's status polling self-heals a probe that never ran.
 */
class CartProbe {

	const HOOK = 'reallycache_cart_probe';

	public static function register() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );

		add_action( 'switch_theme', function () {
			Config::save( [ 'cart_cache_ok' => null, 'cart_probe_at' => 0 ] );
		} );

		add_action( 'admin_init', function () {
			if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! class_exists( 'WooCommerce' ) ) {
				return;
			}
			$config = Config::load();
			if ( (int) ( $config['cart_probe_at'] ?? 0 ) > time() - WEEK_IN_SECONDS ) {
				return;
			}
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_single_event( time() + 15, self::HOOK );
			}
		} );
	}

	/** Fire the probe through a loopback worker — WP-Cron is not trusted. */
	public static function kick() {
		wp_remote_post( rest_url( 'reallycache/v1/cart-probe-worker' ), [
			'blocking'  => false,
			'timeout'   => 0.02,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => [ 'key' => \ReallyCache\Warmer::token() ],
		] );
	}

	/** Called from /state polling: self-heals a probe that never ran. */
	public static function maybe_pump() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$config = Config::load();
		if ( null !== ( $config['cart_cache_ok'] ?? null ) || (int) ( $config['cart_probe_at'] ?? 0 ) > 0 ) {
			return;
		}
		if ( get_transient( 'reallycache_cartprobe_kick' ) ) {
			return;
		}
		set_transient( 'reallycache_cartprobe_kick', 1, 2 * MINUTE_IN_SECONDS );
		self::kick();
	}

	public static function run() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Both renders live and identical in treatment: the probe param is
		// a meaningful query string, so the cache never serves or stores
		// these requests — raw PHP output on both sides.
		$page = add_query_arg( 'rc_probe', '1', home_url( '/' ) );

		$html_a = self::fetch( $page );
		if ( null === $html_a ) {
			self::conclude( false, true );
			return;
		}

		$cookies = self::build_probe_cart();
		if ( null === $cookies ) {
			self::conclude( false, true ); // Couldn't build a cart — retry tomorrow.
			return;
		}

		$html_b = self::fetch( $page, $cookies );
		if ( null === $html_b ) {
			self::conclude( false, true );
			return;
		}

		// Blank every region the fragments JS owns — in both renders.
		$selectors = array_merge( self::fragment_selectors( [] ), self::fragment_selectors( $cookies ) );
		foreach ( array_unique( $selectors ) as $selector ) {
			$parsed = self::parse_selector( $selector );
			if ( $parsed ) {
				$html_a = self::blank_elements( $html_a, $parsed[0], $parsed[1], $parsed[2] );
				$html_b = self::blank_elements( $html_b, $parsed[0], $parsed[1], $parsed[2] );
			}
		}

		self::conclude( self::normalize( $html_a ) === self::normalize( $html_b ), false );
	}

	/** @return string|null Full HTML, or null when the fetch is unusable. */
	private static function fetch( $url, $cookies = [] ) {
		$response = wp_remote_get( $url, [
			'timeout'     => 12,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'  => 'Mozilla/5.0 (X11; Linux x86_64) ReallyCache-CartProbe',
			'cookies'     => $cookies,
		] );
		$html = wp_remote_retrieve_body( $response );

		return ( ! is_wp_error( $response ) && false !== stripos( $html, '</html>' ) ) ? $html : null;
	}

	/**
	 * Builds a real probe cart through ?wc-ajax=add_to_cart — the same
	 * endpoint store buttons use — trying up to five in-stock simple
	 * products (variable products need chosen options and would fail).
	 *
	 * @return array|null The cart cookies, or null when no product took.
	 */
	private static function build_probe_cart() {
		$candidates = [];
		if ( function_exists( 'wc_get_products' ) ) {
			$candidates = wc_get_products( [
				'status'       => 'publish',
				'type'         => 'simple',
				'stock_status' => 'instock',
				'limit'        => 5,
				'return'       => 'ids',
			] );
		}
		if ( empty( $candidates ) ) {
			$candidates = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );
		}

		foreach ( $candidates as $product_id ) {
			$response = wp_remote_post( add_query_arg( 'wc-ajax', 'add_to_cart', home_url( '/' ) ), [
				'timeout'   => 12,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => [ 'product_id' => (int) $product_id, 'quantity' => 1 ],
			] );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$cookies = wp_remote_retrieve_cookies( $response );
			foreach ( $cookies as $cookie ) {
				if ( 0 === strpos( $cookie->name, 'woocommerce_items_in_cart' ) ) {
					return $cookies;
				}
			}
		}

		return null;
	}

	/** @return string[] Selector keys from ?wc-ajax=get_refreshed_fragments. */
	private static function fragment_selectors( $cookies ) {
		$response = wp_remote_post( add_query_arg( 'wc-ajax', 'get_refreshed_fragments', home_url( '/' ) ), [
			'timeout'   => 12,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'cookies'   => $cookies,
		] );
		if ( is_wp_error( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && ! empty( $data['fragments'] ) ? array_keys( $data['fragments'] ) : [];
	}

	/**
	 * The last simple segment of a fragments selector decides what to
	 * blank: "div.widget_shopping_cart_content", ".cross-sells",
	 * "span#thing" and the like.
	 *
	 * @return array{0:string,1:string,2:string}|null [tag, attr, value]
	 */
	private static function parse_selector( $selector ) {
		$parts = preg_split( '/\s+/', trim( (string) $selector ) );
		$last  = end( $parts );

		if ( preg_match( '/^([a-z][a-z0-9]*)?[.#]([A-Za-z0-9_\-]+)$/i', $last, $m ) ) {
			return [ strtolower( $m[1] ), false !== strpos( $last, '#' ) ? 'id' : 'class', $m[2] ];
		}

		return null;
	}

	/**
	 * Blanks whole elements matching tag+class/id, counting open/close
	 * tags of the same name so nested markup is removed intact.
	 */
	private static function blank_elements( $html, $tag, $attr, $value ) {
		$open   = $tag ? '<' . $tag . '\\b' : '<[a-z][a-z0-9]*\\b';
		$offset = 0;
		$rounds = 0;

		while ( $rounds ++ < 50 && preg_match(
			'/' . $open . '[^>]*' . $attr . '="[^"]*' . preg_quote( $value, '/' ) . '[^"]*"[^>]*>/i',
			$html, $m, PREG_OFFSET_CAPTURE, $offset
		) ) {
			$start = $m[0][1];
			preg_match( '/^<([a-z][a-z0-9]*)/i', $m[0][0], $tm );
			$name  = strtolower( $tm[1] );
			$pos   = $start + strlen( $m[0][0] );
			$depth = 1;

			while ( $depth > 0 && preg_match( '/<\\/?' . $name . '\\b[^>]*>/i', $html, $mm, PREG_OFFSET_CAPTURE, $pos ) ) {
				$depth += ( '/' === $mm[0][0][1] ) ? -1 : 1;
				$pos    = $mm[0][1] + strlen( $mm[0][0] );
			}

			$html   = substr_replace( $html, '~RC~', $start, $pos - $start );
			$offset = $start + 4;
		}

		return $html;
	}

	/** @param bool $retry_soon True re-runs tomorrow instead of next week. */
	private static function conclude( $ok, $retry_soon ) {
		Config::save( [
			'cart_cache_ok' => (bool) $ok,
			'cart_probe_at' => $retry_soon ? time() - ( WEEK_IN_SECONDS - DAY_IN_SECONDS ) : time(),
		] );
	}

	/**
	 * Per-request noise that legitimately differs between renders: nonces,
	 * hex tokens, and analytics scripts that embed cart state (visitors
	 * served from cache emit the anonymous payload — like every cache
	 * plugin). Everything else must match byte-for-byte.
	 */
	private static function normalize( $html ) {
		$html = preg_replace( '/([?&;]_wpnonce=)[a-f0-9]{6,}/i', '$1X', $html );
		$html = preg_replace( '/"[a-f0-9]{10}"/', '"X"', $html );
		$html = preg_replace( "/'[a-f0-9]{10}'/", "'X'", $html );
		$html = preg_replace( '/"[a-f0-9]{32}"/', '"X"', $html );
		$html = preg_replace( '/<script\b[^>]*>(?:(?!<\/script>)[\s\S])*_wca\.push[\s\S]*?<\/script>/', '~S~', $html );

		$html = apply_filters( 'reallycache_cart_probe_normalize', $html );

		return is_string( $html ) ? $html : '';
	}
}
