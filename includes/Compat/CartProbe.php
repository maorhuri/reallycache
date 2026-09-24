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

	/** Bumped when the algorithm changes — stale verdicts re-judge themselves. */
	const ALGO_VERSION = 5;

	public static function register() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );

		add_action( 'switch_theme', function () {
			Config::save( [ 'cart_cache_ok' => null, 'cart_probe_at' => 0, 'cart_probe_diff' => '', 'cart_probe_note' => '' ] );
		} );

		add_action( 'admin_init', function () {
			if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) || ! class_exists( 'WooCommerce' ) ) {
				return;
			}
			$config = Config::load();
			if ( (int) ( $config['cart_probe_ver'] ?? 0 ) !== self::ALGO_VERSION ) {
				Config::save( [ 'cart_cache_ok' => null, 'cart_probe_at' => 0, 'cart_probe_ver' => self::ALGO_VERSION, 'cart_probe_diff' => '', 'cart_probe_note' => '' ] );
				$config = Config::load();
			}
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

		// One probe at a time, and not more than one per few minutes: the
		// worker endpoint is public (token-gated), and each run costs the
		// server several blocking self-requests — without this, a replayed
		// token could stack concurrent runs into a self-inflicted DoS.
		if ( ! add_option( 'reallycache_cartprobe_lock', (string) time(), '', 'no' ) ) {
			$held = (int) get_option( 'reallycache_cartprobe_lock' );
			if ( $held > time() - 3 * MINUTE_IN_SECONDS ) {
				return;
			}
			update_option( 'reallycache_cartprobe_lock', (string) time(), false );
		}

		// The lock row is never deleted: its timestamp doubles as the
		// cooldown, and an expired value reopens the gate above even if a
		// run fatals mid-way.
		self::probe();
	}

	private static function probe() {
		// Both renders live and identical in treatment: the probe param is
		// a meaningful query string, so the cache never serves or stores
		// these requests — raw PHP output on both sides.
		$page = add_query_arg( 'rc_probe', '1', home_url( '/' ) );

		$html_a = self::fetch( $page );
		if ( null === $html_a ) {
			self::conclude( false, true, '', 'fetch-fail' );
			return;
		}

		$cookies = self::build_probe_cart();
		if ( null === $cookies ) {
			// Couldn't build a cart — the theme was never actually tested;
			// the UI must say so instead of blaming server-side rendering.
			self::conclude( false, true, '', 'no-cart' );
			return;
		}

		$html_b = self::fetch( $page, $cookies );
		if ( null === $html_b ) {
			self::conclude( false, true, '', 'fetch-fail' );
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

		$na = self::normalize( $html_a );
		$nb = self::normalize( $html_b );

		self::conclude( $na === $nb, false, $na === $nb ? '' : self::diff_snippet( $na, $nb ), $na === $nb ? '' : 'diff' );
	}

	/**
	 * Up to three distinct differing regions between the two normalized
	 * renders — for the settings screen, so all blockers show at once
	 * instead of peeling one per probe run.
	 */
	private static function diff_snippet( $a, $b ) {
		$squish = function ( $s ) {
			return trim( preg_replace( '/\s+/', ' ', (string) $s ) );
		};

		$out   = [];
		$ctx   = 80;
		$guard = 0;

		while ( count( $out ) < 3 && $guard ++ < 6 ) {
			$la  = strlen( $a );
			$lb  = strlen( $b );
			$min = min( $la, $lb );
			if ( $min < 0 || ( 0 === $min && $la === $lb ) ) {
				break;
			}

			// First difference.
			for ( $i = 0; $i < $min && $a[ $i ] === $b[ $i ]; $i ++ );
			if ( $i >= $min && $la === $lb ) {
				break; // No further differences.
			}
			$pos = $i;

			// Resync: the next 40-char run from A that also occurs in B
			// after the difference — everything before it is this hunk.
			// Runs with few distinct characters (whitespace, repeated
			// markup) are skipped as anchors: they match at the wrong
			// offset and misalign everything after them.
			$a_end = min( $la, $pos + 4000 );
			$sync  = false;
			for ( $s = $pos + 1; $s < $a_end - 40; $s += 20 ) {
				$needle = substr( $a, $s, 40 );
				if ( count( array_unique( str_split( $needle ) ) ) < 5 ) {
					continue;
				}
				$found = strpos( $b, $needle, $pos );
				if ( false !== $found ) {
					$sync = [ $s, $found ];
					break;
				}
			}

			$a_hunk_end = $sync ? $sync[0] : min( $la, $pos + 300 );
			$b_hunk_end = $sync ? $sync[1] : min( $lb, $pos + 300 );

			$side_a = mb_substr( $squish( substr( $a, max( 0, $pos - $ctx ), ( $a_hunk_end - $pos ) + 2 * $ctx ) ), 0, 300 );
			$side_b = mb_substr( $squish( substr( $b, max( 0, $pos - $ctx ), ( $b_hunk_end - $pos ) + 2 * $ctx ) ), 0, 300 );

			// A hunk whose two sides read identically is a resync artifact,
			// not something a human can act on — drop it.
			if ( $side_a !== $side_b ) {
				$out[] = 'DIFF ' . ( count( $out ) + 1 ) . "\nNO CART: …" . $side_a . "…\nWITH CART: …" . $side_b . '…';
			}

			if ( ! $sync ) {
				break;
			}
			// Continue after the resync point: both strings trimmed to
			// their own sync position, so offsets stay aligned.
			$a = substr( $a, $a_hunk_end );
			$b = substr( $b, $b_hunk_end );
		}

		return implode( "\n\n", $out );
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
	 * endpoint store buttons use. Simple products first; on
	 * variations-only catalogs (where a plain add would fail and the
	 * probe used to give up), in-stock variation IDs are tried too —
	 * WC_Cart::add_to_cart() accepts a variation ID directly and
	 * resolves its parent and its explicitly-defined attributes.
	 * Variations using "Any …" attributes are skipped: WooCommerce
	 * rejects them without a concrete posted value ("%s is a required
	 * field"), so they can never produce a cart cookie.
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
			// Sampled wider than simple products because the "Any" filter
			// below discards some of them before any HTTP attempt.
			$candidates = array_merge( $candidates, wc_get_products( [
				'status'       => 'publish',
				'type'         => 'variation',
				'stock_status' => 'instock',
				'limit'        => 15,
				'return'       => 'ids',
			] ) );
			// Only purchasable candidates are worth an HTTP attempt.
			$candidates = array_values( array_filter( $candidates, function ( $id ) {
				$product = wc_get_product( $id );
				if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
					return false;
				}
				if ( $product->is_type( 'variation' ) ) {
					// An "Any" attribute comes back as '' here.
					$attributes = (array) $product->get_attributes();
					if ( count( array_filter( $attributes, 'strlen' ) ) !== count( $attributes ) ) {
						return false;
					}
				}

				return true;
			} ) );
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

		// The first candidate almost always takes; the cap only bounds the
		// worst case so a misbehaving catalog can't stretch the worker.
		foreach ( array_slice( $candidates, 0, 8 ) as $product_id ) {
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
	private static function conclude( $ok, $retry_soon, $diff = '', $note = '' ) {
		Config::save( [
			'cart_cache_ok'   => (bool) $ok,
			'cart_probe_at'   => $retry_soon ? time() - ( WEEK_IN_SECONDS - DAY_IN_SECONDS ) : time(),
			'cart_probe_diff' => (string) $diff,
			'cart_probe_note' => (string) $note,
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
		$html = preg_replace( '/"[a-f0-9]{10}"/i', '"X"', $html );
		$html = preg_replace( "/'[a-f0-9]{10}'/i", "'X'", $html );
		$html = preg_replace( '/"[a-f0-9]{32}"/i', '"X"', $html );
		$html = preg_replace( "/'[a-f0-9]{32}'/i", "'X'", $html );
		// Wordfence live-traffic beacon: a random per-request hex id inside
		// its inline script URL — pure noise, and the exact thing that
		// wrongly failed an otherwise cart-identical theme (ordman).
		$html = preg_replace( '/([?&;]hid=)[a-f0-9]{8,64}/i', '$1X', $html );
		$html = preg_replace( '/<script\b[^>]*>(?:(?!<\/script>)[\s\S])*_wca\.push[\s\S]*?<\/script>/', '~S~', $html );
		// GTM4WP embeds the full cart (cartContent) into its dataLayer
		// script for analytics. On every cached store on earth this script
		// freezes at the anonymous render and everyone lives with it —
		// conversion events fire client-side and on uncached checkout
		// pages regardless. Analytics payload, not page content.
		$html = preg_replace( '/<script\b[^>]*>(?:(?!<\/script>)[\s\S])*dataLayer_content[\s\S]*?<\/script>/', '~S~', $html );

		$html = apply_filters( 'reallycache_cart_probe_normalize', $html );

		return is_string( $html ) ? $html : '';
	}
}
