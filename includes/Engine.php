<?php
namespace ReallyCache;

use ReallyCache\Drivers\DriverInterface;
use ReallyCache\Drivers\FileDriver;

/**
 * The one object shared by the drop-in (pre-WordPress) and the plugin
 * (full WordPress). It wires config + gate + driver, serves hits, starts
 * the output buffer on misses, and exposes purging to the rest of the code.
 */
class Engine {

	/** @var Engine|null */
	private static $instance;

	private $config;
	private $gate;
	private $driver;
	private $buffering = false;
	private $flushed = false;

	public static function boot( array $config, $content_dir ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $config, $content_dir );
		}

		return self::$instance;
	}

	/** @return Engine|null Null when the drop-in never booted (cache disabled). */
	public static function instance() {
		return self::$instance;
	}

	private function __construct( array $config, $content_dir ) {
		$this->config = $config;
		$this->gate   = new Gate( $config );
		$this->driver = new FileDriver( $content_dir, $config );
	}

	public function config() {
		return $this->config;
	}

	/** @return DriverInterface */
	public function driver() {
		return $this->driver;
	}

	public function gate() {
		return $this->gate;
	}

	/** Drop-in entry point: deliver a cached page and exit, or fall through. */
	public function maybe_serve() {
		if ( empty( $this->config['cache_enabled'] ) || ! $this->gate->request_ok() ) {
			return;
		}

		$key = CacheKey::from_request( $this->config );
		if ( null === $key ) {
			return;
		}

		$this->driver->serve( $key, (int) $this->config['ttl'] );
	}

	/** Called from wp_cache_postload() on every request that wasn't a hit. */
	public function start_buffer() {
		if ( $this->buffering || empty( $this->config['cache_enabled'] ) ) {
			return;
		}

		if ( ! $this->gate->request_ok() ) {
			// Request-level bypass — the reason names the exact rule.
			@header( 'X-ReallyCache: SKIP-' . ( $this->gate->reject_reason ?: 'unknown' ) );
			return;
		}

		$key = CacheKey::from_request( $this->config );
		if ( null === $key ) {
			// No cache key possible: host not allowed / odd port /
			// meaningful query string / unsafe path.
			@header( 'X-ReallyCache: NO-KEY' );
			return;
		}

		// Tracking-only query strings share the base cache entry, so the
		// rendered page must never see the parameters (poisoning guard).
		CacheKey::strip_tracking_from_request( $this->config );

		$this->buffering = true;
		ob_start( function ( $buffer, $phase ) use ( $key ) {
			// A mid-render flush means this callback only holds the tail of
			// the page; storing it would cache a truncated document.
			if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
				$this->flushed = true;
				return $buffer;
			}
			if ( $this->flushed ) {
				return $buffer;
			}

			return $this->maybe_store( $key, $buffer );
		} );
	}

	private function maybe_store( CacheKey $key, $buffer ) {
		$cart_entry = false !== strpos( $key->variant, '-c' );

		if ( strlen( $buffer ) < 255 || ! $this->gate->response_ok( $buffer, $cart_entry ) ) {
			$this->driver->mark_uncacheable();
			// The reason is gold when debugging "why is nothing cached":
			// curl -I and the header says exactly which check refused.
			header( 'X-ReallyCache: BYPASS-' . ( $this->gate->reject_reason ?: 'too-small' ) );
			return $buffer;
		}

		$html = apply_filters( 'reallycache_before_store', $buffer, $key );
		// A broken optimization filter must never poison the cache: fall
		// back to the unmodified page when the result is not a full document.
		if ( ! is_string( $html ) || false === stripos( $html, '</html>' ) ) {
			$html = $buffer;
		}
		$html .= "\n<!-- Cached by ReallyCache on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->";

		// A page carrying a WordPress nonce must not outlive the nonce
		// (12–24h), or its AJAX features (infinite scroll, forms) die with
		// an expired token. Such pages get a capped lifetime even when the
		// site-wide setting is "permanent".
		$ttl    = (int) $this->config['ttl'];
		$capped = false;
		if ( self::contains_nonce( $html ) ) {
			$cap = max( HOUR_IN_SECONDS, (int) apply_filters( 'reallycache_nonce_ttl', 10 * HOUR_IN_SECONDS ) );
			if ( $ttl <= 0 || $ttl > $cap ) {
				$ttl    = $cap;
				$capped = true;
			}
		}
		// Private per-cart entries are short-lived: the cart hash keys the
		// contents, but totals can shift (coupons, stock) — keep them fresh.
		if ( $cart_entry ) {
			$cap = max( MINUTE_IN_SECONDS, (int) apply_filters( 'reallycache_cart_ttl', 15 * MINUTE_IN_SECONDS ) );
			if ( $ttl <= 0 || $ttl > $cap ) {
				$ttl    = $cap;
				$capped = true;
			}
		}

		$this->driver->store( $key, $html, $ttl, $this->security_headers(), $capped );

		// Verify the write actually landed — a silent mkdir/permission
		// failure otherwise looks like caching works while nothing is saved.
		if ( $this->driver->has( $key, 0 ) ) {
			header( 'X-ReallyCache: MISS' );
		} else {
			header( 'X-ReallyCache: STORE-FAILED' );
		}

		return $html;
	}

	/**
	 * Detects the usual shapes a WordPress nonce takes inside a page:
	 * a _wpnonce URL parameter or form field, a localized-script value
	 * ("nonce"/"security"/"_ajax_nonce": "hex"), or a data-nonce attribute.
	 */
	public static function contains_nonce( $html ) {
		return (bool) preg_match(
			'/[?&;]_wpnonce=[a-f0-9]{8,12}\b'
			. '|name=["\']_wpnonce["\']'
			. '|["\'](?:nonce|_ajax_nonce|_wpnonce|security)["\']\s*[:=]\s*["\'][a-f0-9]{8,12}["\']'
			. '|data-nonce=["\'][a-f0-9]{8,12}["\']/i',
			$html
		);
	}

	/**
	 * Security headers set during render (by security plugins or the theme)
	 * that must survive onto cache hits, where PHP never runs them again.
	 */
	private function security_headers() {
		$allow = [ 'content-security-policy', 'x-frame-options', 'strict-transport-security', 'referrer-policy', 'x-content-type-options', 'permissions-policy', 'cross-origin-opener-policy' ];
		$keep  = [];

		foreach ( headers_list() as $header ) {
			$name = strtolower( trim( strtok( $header, ':' ) ) );
			if ( in_array( $name, $allow, true ) ) {
				$keep[] = $header;
			}
		}

		return $keep;
	}

	/**
	 * True when every variant this site serves is already fresh in cache —
	 * used by the warmer to skip pages a real visitor already warmed.
	 */
	public function url_is_cached( $url ) {
		$ttl = (int) $this->config['ttl'];

		$desktop = CacheKey::from_url( $url, $this->config );
		if ( null === $desktop || ! $this->driver->has( $desktop, $ttl ) ) {
			return false;
		}

		if ( ! empty( $this->config['separate_mobile'] ) ) {
			$mobile = CacheKey::from_url( $url, $this->config, '-mobile' );
			if ( null === $mobile || ! $this->driver->has( $mobile, $ttl ) ) {
				return false;
			}
		}

		return true;
	}

	public function purge_url( $url, $warm = false ) {
		$this->purge_urls( [ $url ], $warm );
	}

	public function purge_urls( array $urls, $warm = false ) {
		$urls = array_unique( array_filter( $urls ) );

		foreach ( $urls as $url ) {
			$this->driver->purge_url( $url );
			do_action( 'reallycache_purged_url', $url );
		}

		// Smart cache: what was just purged gets re-warmed in the background,
		// so the next real visitor still lands on a hot page.
		if ( $warm && $urls && ! empty( $this->config['smart_cache'] ) && function_exists( 'get_option' ) ) {
			Warmer::enqueue( $urls );
		}
	}

	public function purge_all() {
		$this->driver->purge_all();
		do_action( 'reallycache_purged_all' );
	}
}
