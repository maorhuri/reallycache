<?php
namespace ReallyCache\Compat;

use ReallyCache\Config;

/**
 * Caches WooCommerce's cart-fragments AJAX response.
 *
 * With items in the cart, WooCommerce fires ?wc-ajax=get_refreshed_fragments
 * on page loads to repaint the mini-cart. That request boots full
 * WooCommerce and is slow (measured ~0.4–1.0s on real stores), which is
 * the actual "slow with a cart" feeling — the HTML page itself is served
 * fast from cache. The fragments depend only on the cart's contents, so
 * they are identical for a given cart hash: caching them per hash+session
 * turns the repeat cost into a few milliseconds.
 *
 * Short TTL keeps totals honest against coupons/stock, the entry is keyed
 * per visitor (session) so nothing leaks, and any change to the cart makes
 * a new hash → a fresh compute exactly once.
 */
class CartFragments {

	const GROUP = 'reallycache_fragments';

	public static function register() {
		add_action( 'init', [ __CLASS__, 'maybe_serve' ], 0 );
		// Capture a freshly computed response on the way out and store it.
		add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'capture' ], PHP_INT_MAX );
	}

	private static function enabled() {
		$config = Config::load();

		return ! empty( $config['cart_fragments_cache'] )
			&& ! empty( $_COOKIE['woocommerce_cart_hash'] )
			&& ! self::is_logged_in();
	}

	private static function is_logged_in() {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}

		return false;
	}

	/** Per visitor + cart state, so a stored response is never shared wrongly. */
	private static function key() {
		$session = '';
		foreach ( $_COOKIE as $name => $value ) {
			if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) ) {
				$session = (string) $value;
				break;
			}
		}

		return 'frag_' . md5( $_COOKIE['woocommerce_cart_hash'] . '|' . $session );
	}

	/**
	 * Runs before WooCommerce processes the AJAX request. On a cache hit it
	 * prints the stored JSON and exits, so WooCommerce never boots.
	 */
	public static function maybe_serve() {
		if ( 'get_refreshed_fragments' !== ( $_GET['wc-ajax'] ?? '' ) || ! self::enabled() ) {
			return;
		}

		$cached = wp_cache_get( self::key(), self::GROUP );
		if ( is_string( $cached ) && '' !== $cached ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'X-ReallyCache-Fragments: HIT' );
			nocache_headers();
			echo $cached; // phpcs:ignore — stored JSON we produced.
			exit;
		}

		// Miss: fall through — WooCommerce computes, capture() stores it.
	}

	/**
	 * WooCommerce passes the assembled fragments array through this filter
	 * right before it echoes the JSON. We serialize the same envelope it
	 * would send and store it. Returns the array unchanged.
	 */
	public static function capture( $fragments ) {
		if ( 'get_refreshed_fragments' === ( $_GET['wc-ajax'] ?? '' ) && self::enabled() && is_array( $fragments ) ) {
			$hash    = isset( WC()->cart ) ? WC()->cart->get_cart_hash() : '';
			$payload = wp_json_encode( [
				'fragments' => $fragments,
				'cart_hash' => $hash,
			] );
			if ( is_string( $payload ) ) {
				$ttl = max( 30, (int) apply_filters( 'reallycache_fragments_ttl', 300 ) );
				wp_cache_set( self::key(), $payload, self::GROUP, $ttl );
			}
		}

		return $fragments;
	}
}
