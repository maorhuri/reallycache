<?php
namespace ReallyCache;

/**
 * Activation and deactivation: writes the config file, installs the
 * advanced-cache.php drop-in and toggles WP_CACHE in wp-config.php.
 */
class Setup {

	public static function activate() {
		$config = Config::save( [ 'cache_enabled' => true ] );

		copy( REALLYCACHE_DIR . '/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );

		self::set_wp_cache_constant( true );
		BrowserCache::sync( $config );

		if ( ! wp_next_scheduled( 'reallycache_gc' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'reallycache_gc' );
		}
	}

	public static function deactivate() {
		// Only remove the drop-in if it is ours — another cache plugin may
		// have installed its own since.
		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
		if ( is_file( $dropin ) && false !== strpos( (string) file_get_contents( $dropin ), 'ReallyCache' ) ) {
			unlink( $dropin );
		}

		self::set_wp_cache_constant( false );
		wp_clear_scheduled_hook( 'reallycache_gc' );
		Warmer::reset();
		Media\Webp::reset();
		BrowserCache::remove();
		Redis\Manager::disable();

		$engine = Engine::instance();
		if ( $engine ) {
			$engine->purge_all();
		}
	}

	/**
	 * Rewrites the WP_CACHE define in wp-config.php. Conservative on
	 * purpose: only a plain boolean literal is ever replaced — any other
	 * form (expression, getenv, constant) is left alone rather than risk
	 * corrupting the file. The write is atomic (temp file + rename), so a
	 * request dying mid-write can never leave a torn wp-config, and no
	 * plaintext backup with DB credentials is ever parked in the web root.
	 */
	private static function set_wp_cache_constant( $value ) {
		$path = self::wp_config_path();
		if ( ! $path || ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) {
			return;
		}

		$original = file_get_contents( $path );
		if ( false === $original || '' === trim( $original ) ) {
			return;
		}

		$contents = preg_replace( "/\r\n|\r|\n/", "\n", $original );
		$value    = $value ? 'true' : 'false';
		$literal  = '/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(?:true|false)\s*\)\s*;/';

		if ( preg_match( $literal, $contents ) ) {
			$contents = preg_replace( $literal, "define( 'WP_CACHE', {$value} );", $contents, 1 );
		} elseif ( false !== strpos( $contents, 'WP_CACHE' ) ) {
			return; // Defined in a non-literal way — never touch it.
		} else {
			$contents = preg_replace( '/(<\?php)/', "$1\ndefine( 'WP_CACHE', {$value} );", $contents, 1 );
		}

		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return;
		}

		if ( "\n" !== PHP_EOL ) {
			$contents = str_replace( "\n", PHP_EOL, $contents );
		}

		$tmp = $path . '.' . uniqid( 'rc', true ) . '.tmp';
		if ( false === file_put_contents( $tmp, trim( $contents ) ) ) {
			@unlink( $tmp );
			return;
		}
		if ( ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
		}
	}

	private static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return dirname( ABSPATH ) . '/wp-config.php';
		}

		return '';
	}
}
