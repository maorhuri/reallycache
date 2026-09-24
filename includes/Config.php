<?php
namespace ReallyCache;

/**
 * Settings live in wp-content/reallycache/config.php as a returned PHP array,
 * not in wp_options: the drop-in needs them before the database is loaded,
 * and a .php file that only returns a value outputs nothing when requested
 * over HTTP (unlike a world-readable JSON file).
 */
class Config {

	const DIR_NAME = 'reallycache';

	public static function defaults() {
		return [
			'version'               => REALLYCACHE_VERSION,
			'plugin_dir'            => REALLYCACHE_DIR,
			'cache_enabled'         => true,
			// Seconds a cached page stays fresh; 0 = permanent, purged only
			// by the smart-cache hooks or a manual clear.
			'ttl'                   => 43200,
			'separate_mobile'       => false,
			'dev_mode_until'        => 0,
			// Smart cache: content changes purge exactly the affected URLs
			// (page, home, archives) and re-warm them in the background.
			'smart_cache'           => true,
			// Pages a full warm-up covers; 0 = the whole site, gradually.
			'warm_max'              => 500,
			// Nightly full refresh: purge everything and rebuild, so every
			// page starts the day with a fresh TTL. Hour is site-timezone.
			'daily_warm'            => true,
			'daily_warm_hour'       => 0,
			// Query parameters that never change page content. A request whose
			// entire query string consists of these is served the base page.
			'tracking_params'       => [
				'utm_*', 'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid',
				'fb_action_ids', 'fb_action_types', 'fb_source', 'msclkid',
				'mc_cid', 'mc_eid', '_ga', 'igshid', 'ttclid', 'twclid',
			],
			// A visitor carrying any of these cookie name prefixes gets a
			// personalized page and must bypass the cache. WooCommerce's own
			// cookies are handled by the woocommerce_protection toggle.
			'bypass_cookies'        => [
				'wp-postpass_',
				'comment_author_',
				'edd_items_in_cart',
			],
			// WooCommerce protection: cart/checkout/account URLs are synced
			// from WooCommerce's page settings so the pre-WP gate knows them.
			'woocommerce_protection' => true,
			'woo_excluded_paths'     => [],
			// Delivery pack.
			'webp_enabled'           => true,
			'webp_on_upload'         => true,
			'lcp_boost'              => true,
			'font_display_swap'      => true,
			// Extract huge base64-inlined images from cached HTML into
			// static files (the Figma-export-SVG megabyte problem).
			'datauri_extract'        => true,
			'browser_cache'          => true,
			'redis_object_cache'     => false,
			'redis_excluded_groups'  => [],
			'redis_max_ttl'          => 0,
			// One pattern per entry, * is a wildcard. Matched against the URI path.
			'excluded_uris'         => [],
			'excluded_user_agents'  => [],
			// Hosts this site answers for; synced from home_url. Requests
			// with any other Host header are never cached.
			'allowed_hosts'         => [],
		];
	}

	public static function config_dir() {
		return WP_CONTENT_DIR . '/' . self::DIR_NAME;
	}

	public static function config_file() {
		return self::config_dir() . '/config.php';
	}

	/**
	 * @return array Full settings, defaults merged with the saved file.
	 */
	public static function load() {
		$saved = [];
		if ( is_readable( self::config_file() ) ) {
			try {
				$saved = include self::config_file();
			} catch ( \Throwable $e ) {
				$saved = [];
			}
			if ( ! is_array( $saved ) ) {
				$saved = [];
			}
		}

		$config = array_merge( self::defaults(), $saved );
		// Always trust the running installation over a stale saved path.
		$config['plugin_dir'] = REALLYCACHE_DIR;

		return $config;
	}

	/**
	 * @param array $changes Keys to overwrite; unknown keys are dropped.
	 * @return array The saved config.
	 */
	public static function save( array $changes ) {
		$config = array_merge( self::load(), array_intersect_key( $changes, self::defaults() ) );

		if ( ! is_dir( self::config_dir() ) ) {
			mkdir( self::config_dir(), 0755, true );
		}

		$contents = "<?php\nif ( ! defined( 'ABSPATH' ) && php_sapi_name() !== 'cli' && ! isset( \$reallycache_config_file ) ) { exit; }\nreturn " . var_export( $config, true ) . ";\n";

		// Unique temp name: a fixed one let two concurrent writers share an
		// inode, publishing a half-written file that fatals every request.
		$tmp = self::config_file() . '.' . uniqid( '', true ) . '.tmp';
		if ( false !== file_put_contents( $tmp, $contents, LOCK_EX ) ) {
			rename( $tmp, self::config_file() );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( self::config_file(), true );
			}
		}

		return $config;
	}

	public static function delete() {
		if ( is_file( self::config_file() ) ) {
			unlink( self::config_file() );
		}
		if ( is_dir( self::config_dir() ) ) {
			@rmdir( self::config_dir() );
		}
	}
}
