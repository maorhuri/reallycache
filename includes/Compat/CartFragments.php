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
 * Storage is a small JSON file under the cache tree — always a file, never
 * the object cache, because the fast path serves it from the drop-in
 * BEFORE WordPress loads (see early_serve), where only the filesystem
 * exists. A hit costs milliseconds; even a warm Redis hit at init time
 * would still pay the full plugin boot first.
 */
class CartFragments {

	/**
	 * Rotated on every cart mutation and folded into every cart cache key
	 * (fragments AND page variants). WooCommerce's own cart_hash cookie
	 * often fails to update on AJAX mutations — it is set on 'shutdown',
	 * after the response is already out — leaving the browser with stale
	 * cookies that would keep hitting stale cache entries, and a pre-WP
	 * hit never boots the code that would heal them. This cookie is set
	 * mid-request, while headers are still open, so a mutation always
	 * moves the visitor to fresh keys even when WooCommerce's cookie lags.
	 */
	const EPOCH_COOKIE = 'rc_cart_epoch';

	public static function register() {
		add_action( 'init', [ __CLASS__, 'maybe_serve' ], 0 );
		// Capture a freshly computed response on the way out and store it.
		add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'capture' ], PHP_INT_MAX );

		// Real cart changes only — never the page-load hooks like
		// woocommerce_cart_loaded_from_session, or every view would
		// invalidate the visitor's entry and kill the hit rate.
		foreach ( [
			'woocommerce_add_to_cart',
			'woocommerce_cart_item_set_quantity',
			'woocommerce_after_cart_item_quantity_update', // Drawer +/- steppers.
			'woocommerce_cart_item_removed',
			'woocommerce_cart_item_restored',
			'woocommerce_applied_coupon',
			'woocommerce_removed_coupon',
			'woocommerce_cart_emptied',
		] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_cart_mutation' ], 0, 0 );
		}

		// The drop-in cannot run apply_filters, so the effective TTL is
		// resolved here and persisted into the config file it reads —
		// same pattern as the allowed-hosts sync.
		add_action( 'admin_init', function () {
			$config = Config::load();
			if ( (int) ( $config['cart_fragments_ttl'] ?? 0 ) !== self::ttl() ) {
				Config::save( [ 'cart_fragments_ttl' => self::ttl() ] );
			}
		} );
	}

	/**
	 * A cart mutation makes the visitor's current cache entries lies.
	 * Server side: drop the fragments entry their CURRENT cookies point
	 * at (the exact entry a stale browser would keep hitting). Client
	 * side: rotate the epoch cookie so every later request carries new
	 * cache keys — fragments and page variants both.
	 */
	public static function on_cart_mutation() {
		@unlink( self::file_for( self::key() ) );

		if ( headers_sent() ) {
			return; // Too late to reach the browser; the unlink above still helps.
		}
		$epoch = (string) time();
		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::EPOCH_COOKIE, $epoch, time() + 2 * DAY_IN_SECONDS, is_ssl(), true );
		} else {
			setcookie( self::EPOCH_COOKIE, $epoch, time() + 2 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
		}
		// Same-request consistency: a capture() later in this request must
		// key on the epoch the browser is about to receive.
		$_COOKIE[ self::EPOCH_COOKIE ] = $epoch;
	}

	private static function file_for( $key ) {
		$dir = WP_CONTENT_DIR . '/cache/reallycache-fragments';

		return $dir . '/' . $key . '.json';
	}

	/** @return string|false */
	private static function store_get( $key, $ttl ) {
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

	/** Full wipe, part of the site-wide "clear all cache" action. */
	public static function purge_all() {
		$dir = WP_CONTENT_DIR . '/cache/reallycache-fragments';
		foreach ( glob( $dir . '/*.json' ) ?: [] as $file ) {
			@unlink( $file );
		}
		foreach ( glob( $dir . '/*.tmp' ) ?: [] as $file ) {
			@unlink( $file );
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
		// per made-up value. The session cookie is REQUIRED: it is the only
		// per-visitor secret in the key — without it, every session-less
		// visitor with the same (computable) cart hash would share one
		// entry, and one visitor's fragments could reach another.
		return ! empty( $config['cart_fragments_cache'] )
			&& is_string( $_COOKIE['woocommerce_cart_hash'] ?? null )
			&& preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['woocommerce_cart_hash'] )
			&& self::session_cookie_ok()
			&& ! self::is_logged_in();
	}

	/**
	 * True only when a shape-plausible WooCommerce session cookie is
	 * present — pipe-delimited fields after the customer id ("||" classic,
	 * "|" on newer stacks, possibly %7C-encoded). Pure PHP: runs pre-WP.
	 */
	private static function session_cookie_ok() {
		foreach ( $_COOKIE as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'wp_woocommerce_session_' ) ) {
				return is_string( $value ) && preg_match( '/^[A-Za-z0-9_\-]+(?:\||%7[Cc])/', $value );
			}
		}

		return false;
	}

	private static function ttl() {
		return max( 30, (int) apply_filters( 'reallycache_fragments_ttl', 300 ) );
	}

	private static function is_logged_in() {
		// Honors a renamed auth cookie when the constant exists (WP time);
		// pre-WP the constant is not defined yet and the default prefix is
		// the best signal available — same tradeoff Gate::has_login_cookie
		// makes.
		$prefix = defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : 'wordpress_logged_in_';

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, $prefix ) || 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
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
			if ( is_string( $value ) && 0 === strpos( (string) $name, 'wp_woocommerce_session_' ) ) {
				$session = $value;
				break;
			}
		}

		// Forged array-shaped cookies (name[]=1) must not warn or fatal —
		// treat any non-string as absent.
		$str = function ( $v ) {
			return is_string( $v ) ? $v : '';
		};

		$state  = $str( $_COOKIE['woocommerce_cart_hash'] ?? null );
		$state .= '|' . $str( $_COOKIE['woocommerce_items_in_cart'] ?? null );
		$state .= '|' . $str( $_COOKIE[ self::EPOCH_COOKIE ] ?? null );

		// The host keeps two sites sharing one wp-content (subdomain
		// multisite) from resolving the same visitor to the same entry.
		$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );

		return 'frag_' . md5( $host . '|' . $session . '|' . $state );
	}

	/**
	 * The fast path, called from the drop-in via Engine::maybe_serve()
	 * BEFORE WordPress loads. Pure PHP only: no options, no filters, no
	 * WP functions — the request never boots WordPress on a hit.
	 *
	 * @param array $config The already-loaded drop-in config array.
	 */
	public static function early_serve( array $config ) {
		if ( empty( $config['cart_fragments_cache'] )
			|| 'get_refreshed_fragments' !== ( $_GET['wc-ajax'] ?? '' )
			|| isset( $_GET['nocache'] )
			|| ( ! empty( $config['dev_mode_until'] ) && (int) $config['dev_mode_until'] > time() )
			|| ! is_string( $_COOKIE['woocommerce_cart_hash'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{32}$/', $_COOKIE['woocommerce_cart_hash'] )
			|| ! self::session_cookie_ok()
			|| self::is_logged_in() ) {
			return;
		}

		// The effective TTL is resolved at WP time (where the filter can
		// run) and synced into the config file — both halves of the cache
		// must agree, or the fast path would destroy entries a raised
		// filter TTL still considers fresh.
		$cached = self::store_get( self::key(), max( 30, (int) ( $config['cart_fragments_ttl'] ?? 300 ) ) );
		if ( false === $cached ) {
			// The MISS marker turns "is this feature even running?" into a
			// one-curl question — the exact debugging that pays for itself.
			header( 'X-ReallyCache-Fragments: MISS' );

			return; // WordPress boots, WooCommerce computes, capture() stores.
		}

		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'X-ReallyCache-Fragments: HIT' );
		// nocache_headers() by hand — WordPress is not loaded yet.
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
		header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
		echo $cached; // phpcs:ignore — stored JSON we produced.
		exit;
	}

	/**
	 * Second chance at init, for sites whose drop-in is missing or stale:
	 * WordPress and its plugins have loaded (the expensive part), but the
	 * WooCommerce fragments compute is still skipped on a hit.
	 */
	public static function maybe_serve() {
		if ( 'get_refreshed_fragments' !== ( $_GET['wc-ajax'] ?? '' ) || ! self::enabled() || isset( $_GET['nocache'] ) ) {
			return;
		}
		$config = Config::load();
		if ( ! empty( $config['dev_mode_until'] ) && (int) $config['dev_mode_until'] > time() ) {
			return; // Dev mode: fragments live, like everything else.
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
				if ( ! headers_sent() ) {
					header( 'X-ReallyCache-Fragments: ' . ( is_file( self::file_for( self::key() ) ) ? 'STORED' : 'STORE-FAILED' ) );
				}
			}
		}

		return $fragments;
	}
}
