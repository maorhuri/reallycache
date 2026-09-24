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
		if ( '' === $host ) {
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

		return new self( $host, $path, $variant );
	}

	/** @return CacheKey|null Key for an arbitrary URL of this site (purge use). */
	public static function from_url( $url, array $config ) {
		$parts = parse_url( $url );
		$host  = self::sanitize_host( $parts['host'] ?? '' );
		$path  = self::sanitize_path( $parts['path'] ?? '/' );
		if ( '' === $host || null === $path ) {
			return null;
		}

		return new self( $host, $path, '' );
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
	 */
	private static function sanitize_host( $host ) {
		$host = strtolower( trim( $host ) );
		$host = preg_replace( '/:\d+$/', '', $host );

		if ( '' === $host || strlen( $host ) > 253 || ! preg_match( '/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $host ) || false !== strpos( $host, '..' ) ) {
			return '';
		}

		return $host;
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
