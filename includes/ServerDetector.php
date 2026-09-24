<?php
namespace ReallyCache;

/**
 * Identifies the web server so the status screen can say which cache tier
 * is available, and so future drivers can auto-select. Detection is
 * best-effort: behind a proxy SERVER_SOFTWARE lies, so the answer is a
 * hint for the UI, never a hard switch.
 */
class ServerDetector {

	const SERVER_LITESPEED = 'litespeed';
	const SERVER_NGINX     = 'nginx';
	const SERVER_APACHE    = 'apache';
	const SERVER_UNKNOWN   = 'unknown';

	public static function server() {
		$software = strtolower( $_SERVER['SERVER_SOFTWARE'] ?? '' );

		// Only server-populated signals — HTTP_* vars are client-controlled.
		if ( isset( $_SERVER['X-LSCACHE'] ) || false !== strpos( $software, 'litespeed' ) ) {
			return self::SERVER_LITESPEED;
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return self::SERVER_NGINX;
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return self::SERVER_APACHE;
		}

		return self::SERVER_UNKNOWN;
	}

	public static function label() {
		switch ( self::server() ) {
			case self::SERVER_LITESPEED:
				return 'LiteSpeed';
			case self::SERVER_NGINX:
				return 'Nginx';
			case self::SERVER_APACHE:
				return 'Apache';
			default:
				return __( 'Unknown', 'reallycache' );
		}
	}

	/** The best cache tier available here, for the status screen. */
	public static function best_available_tier() {
		switch ( self::server() ) {
			case self::SERVER_LITESPEED:
				return __( 'LiteSpeed server cache (coming soon — file cache active)', 'reallycache' );
			case self::SERVER_NGINX:
				return __( 'Nginx FastCGI cache (coming soon — file cache active)', 'reallycache' );
			default:
				return __( 'File cache', 'reallycache' );
		}
	}
}
