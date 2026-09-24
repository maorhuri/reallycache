<?php
namespace ReallyCache\Compat;

use ReallyCache\Config;

/**
 * Empirical cart-compatibility probe. Answers one question with a real
 * experiment instead of guesswork: does this theme render identical HTML
 * for a visitor with items in the cart (i.e., the mini-cart lives purely
 * in WooCommerce's JS fragments)?
 *
 *   1. Fetch the homepage as a cookie-less visitor  → A
 *   2. Add a product to a fresh probe cart, keep its cookies
 *   3. Fetch the homepage WITH the cart cookies      → B  (bypasses cache)
 *   4. Normalize known noise (nonces, hashes) and compare.
 *
 * A === B  → cart_cache_ok: visitors with a cart are served cached pages
 * too — the Rocket-style slowdown disappears. Anything else (difference,
 * fetch failure, no products) keeps today's safe behavior. The probe
 * re-runs weekly and after a theme switch.
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

		$ids = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		if ( empty( $ids ) ) {
			self::conclude( false, false ); // No products — nothing to optimize.
			return;
		}

		$args = [
			'timeout'     => 12,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'  => 'Mozilla/5.0 (X11; Linux x86_64) ReallyCache-CartProbe',
		];
		$home = home_url( '/' );

		$response_a = wp_remote_get( $home, $args );
		$html_a     = wp_remote_retrieve_body( $response_a );
		if ( is_wp_error( $response_a ) || false === stripos( $html_a, '</html>' ) ) {
			self::conclude( false, true );
			return;
		}

		// A fresh probe cart: GET add-to-cart works for simple products and
		// hands back the session + items_in_cart cookies on the redirect.
		$add      = add_query_arg( 'add-to-cart', (int) $ids[0], get_permalink( (int) $ids[0] ) );
		$response = wp_remote_get( $add, $args );
		$cookies  = is_wp_error( $response ) ? [] : wp_remote_retrieve_cookies( $response );

		$has_cart = false;
		foreach ( $cookies as $cookie ) {
			if ( 0 === strpos( $cookie->name, 'woocommerce_items_in_cart' ) ) {
				$has_cart = true;
				break;
			}
		}
		if ( ! $has_cart ) {
			// Variable-only catalog or add-to-cart blocked — inconclusive.
			self::conclude( false, true );
			return;
		}

		$response_b = wp_remote_get( $home, $args + [ 'cookies' => $cookies ] );
		$html_b     = wp_remote_retrieve_body( $response_b );
		if ( is_wp_error( $response_b ) || false === stripos( $html_b, '</html>' ) ) {
			self::conclude( false, true );
			return;
		}

		self::conclude( self::normalize( $html_a ) === self::normalize( $html_b ), false );
	}

	/** @param bool $retry_soon True re-runs tomorrow instead of next week. */
	private static function conclude( $ok, $retry_soon ) {
		Config::save( [
			'cart_cache_ok' => (bool) $ok,
			'cart_probe_at' => $retry_soon ? time() - ( WEEK_IN_SECONDS - DAY_IN_SECONDS ) : time(),
		] );
	}

	/**
	 * Removes per-request noise that legitimately differs between renders:
	 * nonces and hex tokens. Everything else must match byte-for-byte —
	 * any real difference keeps the safe behavior.
	 */
	private static function normalize( $html ) {
		$html = preg_replace( '/<!-- Cached by ReallyCache on [^>]+-->/', '', $html );
		$html = preg_replace( '/([?&;]_wpnonce=)[a-f0-9]{6,}/i', '$1X', $html );
		$html = preg_replace( '/"[a-f0-9]{10}"/', '"X"', $html );
		$html = preg_replace( "/'[a-f0-9]{10}'/", "'X'", $html );
		$html = preg_replace( '/"[a-f0-9]{32}"/', '"X"', $html );

		return is_string( $html ) ? $html : '';
	}
}
