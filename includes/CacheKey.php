<?php
namespace ReallyCache;

/**
 * Turns the current request into a filesystem-safe cache location that
 * mirrors the URL structure: host/path/index[-variant].html. A predictable
 * layout (unlike a hashed one) is what lets a web server serve hits directly
 * later, and lets a purge of one URL leave its sub-pages untouched.
 *
 * Runs before WordPress loads — no WP functions allowed here.
 */
class CacheKey {

	const MOBILE_UA_REGEX = '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/i';

	public $host;
	/** @var string URL path, always with leading + trailing slash semantics resolved */
	public $path;
	/** @var string ''|'-mobile' */
	public $variant;

	private function __construct( $host, $path, $variant ) {
		$this->host    = $host;
		$this->path    = $path;
		$this->variant = $variant;
	}

	/**
	 * @return CacheKey|null Null means the request has no safe cache location
	 *                       (bad host, unsafe path, meaningful query string).
	 */
	public static function from_request( array $config ) {
		$host = self::sanitize_host( $_SERVER['HTTP_HOST'] ?? '' );
		if ( '' === $host || ! self::host_allowed( $host, $config ) ) {
			return null;
		}

		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$qs  = '';
		if ( false !== ( $pos = strpos( $uri, '?' ) ) ) {
			$qs  = substr( $uri, $pos + 1 );
			$uri = substr( $uri, 0, $pos );
		}

		if ( ! self::query_string_is_ignorable( $qs, $config['tracking_params'] ?? [] ) ) {
			return null;
		}

		$path = self::sanitize_path( $uri );
		if ( null === $path ) {
			return null;
		}

		$variant = '';
		if ( ! empty( $config['separate_mobile'] ) && self::is_mobile() ) {
			$variant = '-mobile';
		}
		$variant .= self::cart_variant( $config );

		return new self( $host, $path, $variant );
	}

	/**
	 * On themes that render cart data server-side (probe verdict: not
	 * shared-cacheable), visitors with a cart get their own cache entry
	 * instead of no cache at all. Keyed on the WooCommerce cart hash —
	 * which changes exactly when cart contents change — plus the session
	 * cookie, so entries are per-visitor and never leak personalization.
	 *
	 * @return string ''|-c<hash>
	 */
	public static function cart_variant( array $config ) {
		if ( empty( $config['cart_variant_cache'] )
			|| ! empty( $config['cart_cache_ok'] )
			|| empty( $config['woocommerce_protection'] )
			|| empty( $_COOKIE['woocommerce_items_in_cart'] )
			|| empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
			return '';
		}

		// Only cookies WooCommerce itself could have minted earn a variant:
		// the cart hash is always a 32-hex md5, and the session cookie is
		// customer||expiry||expiring||hash. Anything else is a forgery that
		// would otherwise mint one disk entry per made-up cookie value —
		// forged shapes fall through to the live-page bypass instead.
		if ( ! preg_match( '/^[a-f0-9]{32}$/', (string) $_COOKIE['woocommerce_cart_hash'] ) ) {
			return '';
		}

		$session = '';
		foreach ( $_COOKIE as $name => $value ) {
			if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) ) {
				$session = (string) $value;
				break;
			}
		}
		// Guest ids look like "t_<hex>", logged-in ids are numeric — then
		// "||expiry||expiring||hash" (arrives url-encoded on some stacks).
		if ( '' !== $session && ! preg_match( '/^[A-Za-z0-9_\-]+(?:\|\||%7C%7C)/', $session ) ) {
			return '';
		}

		return '-c' . substr( md5( $_COOKIE['woocommerce_cart_hash'] . '|' . $session ), 0, 12 );
	}

	/** @return CacheKey|null Key for an arbitrary URL of this site (purge/lookup use). */
	public static function from_url( $url, array $config, $variant = '' ) {
		$parts = parse_url( $url );
		$host  = self::sanitize_host( $parts['host'] ?? '' );
		$path  = self::sanitize_path( $parts['path'] ?? '/' );
		if ( '' === $host || null === $path ) {
			return null;
		}

		return new self( $host, $path, $variant );
	}

	/** @return string Directory of this URL, relative to the cache root. */
	public function dir() {
		return $this->host . $this->path;
	}

	public function filename() {
		return 'index' . $this->variant . '.html';
	}

	public static function is_mobile() {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			&& preg_match( self::MOBILE_UA_REGEX, $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Only a strictly valid hostname is accepted — HTTP_HOST is client input
	 * and becomes a directory name, so anything unusual is rejected outright.
	 * The default ports (:80/:443) are stripped — behind reverse proxies
	 * HTTP_HOST often carries them and the rendered page is identical.
	 * Any other port makes the request uncacheable rather than stripped:
	 * stripping would store a page rendered for host:port under the
	 * canonical host's key (cache poisoning).
	 */
	public static function sanitize_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/:(?:80|443)$/', '', $host );

		if ( '' === $host || strlen( $host ) > 253 || ! preg_match( '/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $host ) || false !== strpos( $host, '..' ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Requests for hosts the site does not own are uncacheable. Without
	 * this, arbitrary Host headers would grow the cache tree without bound
	 * (entries no purge ever reaches). The list is synced from home_url by
	 * the plugin; an empty list (fresh config) falls back to allowing.
	 */
	private static function host_allowed( $host, array $config ) {
		$allowed = array_filter( (array) ( $config['allowed_hosts'] ?? [] ) );

		return empty( $allowed ) || in_array( $host, $allowed, true );
	}

	/**
	 * The URL path becomes real directories, so every segment is validated
	 * after decoding: traversal, control bytes or exotic characters mean the
	 * request simply isn't cacheable. Unicode letters stay allowed — Hebrew
	 * and other non-ASCII slugs are first-class citizens.
	 *
	 * @return string|null Normalized path with leading and trailing slash.
	 */
	private static function sanitize_path( $path ) {
		$path = rawurldecode( $path );

		if ( strlen( $path ) > 1024 || false !== strpos( $path, "\0" ) ) {
			return null;
		}

		// Doubled slashes would make the normalized key differ from the raw
		// URI the exclusion rules saw — an exclusion-bypass class. Refuse.
		if ( false !== strpos( $path, '//' ) ) {
			return null;
		}

		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		foreach ( $segments as $segment ) {
			if ( '.' === $segment || '..' === $segment || strlen( $segment ) > 255 ) {
				return null;
			}
			if ( ! preg_match( '/^[\p{L}\p{N}\p{M}_\-.~@+%() ]+$/u', $segment ) ) {
				return null;
			}
		}

		return '/' . ( $segments ? implode( '/', $segments ) . '/' : '' );
	}

	/**
	 * True when the query string is empty or contains nothing but tracking
	 * parameters, which never change the rendered page. Anything else means
	 * the request must not be served from (or written to) the base cache.
	 */
	private static function query_string_is_ignorable( $qs, array $tracking_params ) {
		if ( '' === $qs ) {
			return true;
		}

		parse_str( $qs, $params );
		foreach ( array_keys( $params ) as $name ) {
			if ( ! self::is_tracking_param( (string) $name, $tracking_params ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * When the query string is tracking-only, remove those parameters from
	 * the request environment before WordPress parses it. Otherwise a page
	 * rendered with ?utm_x=<payload> — where themes and plugins reflect the
	 * current URL into links and forms — would be stored as the base page
	 * and served to everyone (unkeyed-input cache poisoning).
	 */
	public static function strip_tracking_from_request( array $config ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '/';
		$pos = strpos( $uri, '?' );
		if ( false === $pos ) {
			return;
		}

		if ( ! self::query_string_is_ignorable( substr( $uri, $pos + 1 ), $config['tracking_params'] ?? [] ) ) {
			return;
		}

		foreach ( array_keys( $_GET ) as $name ) {
			unset( $_GET[ $name ], $_REQUEST[ $name ] );
		}
		$_SERVER['REQUEST_URI']  = substr( $uri, 0, $pos );
		$_SERVER['QUERY_STRING'] = '';
	}

	private static function is_tracking_param( $name, array $tracking_params ) {
		foreach ( $tracking_params as $pattern ) {
			if ( '' === $pattern ) {
				continue;
			}
			if ( '*' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $name, rtrim( $pattern, '*' ) ) ) {
					return true;
				}
			} elseif ( $name === $pattern ) {
				return true;
			}
		}

		return false;
	}
}
