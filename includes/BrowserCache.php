<?php
namespace ReallyCache;

/**
 * Long browser-cache headers for static assets — the PageSpeed
 * "use efficient cache lifetimes" audit.
 *
 * On Apache the rules are written into .htaccess between markers. Nginx
 * ignores .htaccess, so for it the same rules ship as a ready snippet in
 * docs/nginx-browser-cache.conf to paste into the server config.
 */
class BrowserCache {

	const MARKER = 'ReallyCache Browser Cache';

	/** Bring .htaccess in line with the setting. Safe to call repeatedly. */
	public static function sync( array $config ) {
		if ( ServerDetector::SERVER_APACHE !== ServerDetector::server() ) {
			return;
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$htaccess = get_home_path() . '.htaccess';
		if ( ! is_file( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
			return;
		}
		if ( is_file( $htaccess ) && ! is_writable( $htaccess ) ) {
			return;
		}

		$lines = empty( $config['browser_cache'] ) ? [] : self::rules();
		insert_with_markers( $htaccess, self::MARKER, $lines );
	}

	public static function remove() {
		self::sync( [ 'browser_cache' => false ] );
	}

	/** @return string[] .htaccess lines. Immutable year-long caching for static assets. */
	private static function rules() {
		return [
			'<IfModule mod_expires.c>',
			'ExpiresActive On',
			'ExpiresByType image/jpeg "access plus 1 year"',
			'ExpiresByType image/png "access plus 1 year"',
			'ExpiresByType image/gif "access plus 1 year"',
			'ExpiresByType image/webp "access plus 1 year"',
			'ExpiresByType image/avif "access plus 1 year"',
			'ExpiresByType image/svg+xml "access plus 1 year"',
			'ExpiresByType image/x-icon "access plus 1 year"',
			'ExpiresByType font/woff2 "access plus 1 year"',
			'ExpiresByType font/woff "access plus 1 year"',
			'ExpiresByType font/ttf "access plus 1 year"',
			'ExpiresByType text/css "access plus 1 year"',
			'ExpiresByType application/javascript "access plus 1 year"',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'<FilesMatch "\.(css|js|mjs|woff2?|ttf|otf|svg|jpe?g|png|gif|webp|avif|ico)$">',
			'Header set Cache-Control "public, max-age=31536000, immutable"',
			'</FilesMatch>',
			'</IfModule>',
		];
	}
}
