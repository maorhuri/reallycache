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
 *
 * Storage is a persistent object cache (Redis) when present, otherwise a
 * small JSON file under the cache tree — so the optimization works on
 * every server, with or without Redis.
 */
class CartFragments {

	const GROUP = 'reallycache_fragments';

	public static function register() {
		add_action( 'init', [ __CLASS__, 'maybe_serve' ], 0 );
		// Capture a freshly computed response on the way out and store it.
		add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'capture' ], PHP_INT_MAX );
	}

	/** True when a persistent object cache is available (Redis drop-in). */
	private static function has_object_cache() {
		return is_file( WP_CONTENT_DIR . '/object-cache.php' ) && function_exists( 'wp_cache_get' );
	}

	private static function file_for( $key ) {
		$dir = WP_CONTENT_DIR . '/cache/reallycache-fragments';

		return $dir . '/' . $key . '.json';
	}

	/** @return string|false */
	private static function store_get( $key, $ttl ) {
		if ( self::has_object_cache() ) {
			$val = wp_cache_get( $key, self::GROUP );

			return is_string( $val ) && '' !== $val ? $val : false;
		}

		$file = self::file_for( $key );
		if ( ! is_file( $file ) ) {
			return false;
		}
		if ( ( time() - (int) filemtime( $file ) ) > $ttl ) {
			@unlink( $file );

			return false;
		}

		$val = @file_get_contents( $file );

		return is_string( $val ) && '' !== $val ? $val : false;
	}

	private static function store_set( $key, $payload, $ttl ) {
		if ( self::has_object_cache() ) {
			wp_cache_set( $key, $payload, self::GROUP, $ttl );

			return;
		}

		$dir = WP_CONTENT_DIR . '/cache/reallycache-fragments';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}
		if ( ! is_file( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}

		// Hard bound on entries a cookie-rotating client can mint; real
		// stores stay far below it (one file per active cart per visitor).
		if ( ! is_file( self::file_for( $key ) ) && count( glob( $dir . '/*.json' ) ?: [] ) >= 1000 ) {
			return;
		}

		$tmp = self::file_for( $key ) . '.' . uniqid( '', true ) . '.tmp';
		if ( false !== @file_put_contents( $tmp, $payload ) ) {
			@rename( $tmp, self::file_for( $key ) );
		} else {
			@unlink( $tmp );
		}
	}

	/** Called hourly from GC: drop expired fragment files. */
	public static function gc() {
		$dir = WP_CONTENT_DIR . '/cache/reallycache-fragments';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$cutoff = time() - max( 300, (int) apply_filters( 'reallycache_fragments_ttl', 300 ) ) * 4;
		foreach ( glob( $dir . '/*.json' ) ?: [] as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}
		// Orphaned atomic-write leftovers (a crash between write and rename).
		foreach ( glob( $dir . '/*.tmp' ) ?: [] as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				@unlink( $file );
			}
		}
	}

	private static function enabled() {
		$config = Config::load();

		// The cart hash is always a 32-hex md5 minted by WooCommerce; any
		// other shape is a forged cookie that would mint a junk store entry
		// per made-up value.
		return ! empty( $config['cart_fragments_cache'] )
			&& preg_match( '/^[a-f0-9]{32}$/', (string) ( $_COOKIE['woocommerce_cart_hash'] ?? '' ) )
			&& ! self::is_logged_in();
	}

	private static function ttl() {
		return max( 30, (int) apply_filters( 'reallycache_fragments_ttl', 300 ) );
	}

	private static function is_logged_in() {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Per visitor + exact cart state, computed only from request cookies so
	 * the serve side (WooCommerce not booted) and the capture side agree
	 * byte-for-byte.
	 *
	 * woocommerce_cart_hash is md5( cart contents + get_total() ), so it
	 * already changes on add, remove, quantity (1→10) and coupon — every
	 * mutation that alters the mini-cart. items_in_cart (the quantity
	 * count) is folded in as belt-and-suspenders. Both cookies are present
	 * and identical on the serve and capture halves of the same request.
	 */
	private static function key() {
		$session = '';
		foreach ( $_COOKIE as $name => $value ) {
			if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) ) {
				$session = (string) $value;
				break;
			}
		}

		$state  = (string) ( $_COOKIE['woocommerce_cart_hash'] ?? '' );
		$state .= '|' . (string) ( $_COOKIE['woocommerce_items_in_cart'] ?? '' );

		// The host keeps two sites sharing one wp-content (subdomain
		// multisite) from resolving the same visitor to the same entry.
		$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );

		return 'frag_' . md5( $host . '|' . $session . '|' . $state );
	}

	/**
	 * Runs before WooCommerce processes the AJAX request. On a cache hit it
	 * prints the stored JSON and exits, so WooCommerce never boots.
	 */
	public static function maybe_serve() {
		if ( 'get_refreshed_fragments' !== ( $_GET['wc-ajax'] ?? '' ) || ! self::enabled() ) {
			return;
		}

		$cached = self::store_get( self::key(), self::ttl() );
		if ( false !== $cached ) {
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
			$hash    = ( function_exists( 'WC' ) && isset( WC()->cart ) ) ? WC()->cart->get_cart_hash() : '';
			$payload = wp_json_encode( [
				'fragments' => $fragments,
				'cart_hash' => $hash,
			] );
			if ( is_string( $payload ) ) {
				self::store_set( self::key(), $payload, self::ttl() );
			}
		}

		return $fragments;
	}
}
