<?php
namespace ReallyCache;

/**
 * Activation and deactivation: writes the config file, installs the
 * advanced-cache.php drop-in and toggles WP_CACHE in wp-config.php.
 */
class Setup {

	public static function activate() {
		Config::save( [ 'cache_enabled' => true ] );

		copy( REALLYCACHE_DIR . '/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );

		self::set_wp_cache_constant( true );

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

		$engine = Engine::instance();
		if ( $engine ) {
			$engine->purge_all();
		}
	}

	/**
	 * Rewrites the WP_CACHE define in wp-config.php, with a backup and a
	 * revert if the write produced an empty file.
	 */
	private static function set_wp_cache_constant( $value ) {
		$path = self::wp_config_path();
		if ( ! $path || ! is_writable( $path ) ) {
			return;
		}

		copy( $path, $path . '.reallycache-backup' );

		$contents = file_get_contents( $path );
		$contents = preg_replace( "/\r\n|\r|\n/", "\n", $contents );
		$value    = $value ? 'true' : 'false';

		if ( preg_match( '/define\s*\(\s*[\'"]WP_CACHE[\'"]/', $contents ) ) {
			$contents = preg_replace(
				'/define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*[^)]+\)/',
				"define( 'WP_CACHE', {$value} )",
				$contents
			);
		} else {
			$contents = preg_replace( '/(<\?php)/', "$1\ndefine( 'WP_CACHE', {$value} );", $contents, 1 );
		}

		if ( "\n" !== PHP_EOL ) {
			$contents = str_replace( "\n", PHP_EOL, $contents );
		}

		file_put_contents( $path, trim( $contents ) );

		if ( filesize( $path ) <= 0 ) {
			copy( $path . '.reallycache-backup', $path );
		}

		unlink( $path . '.reallycache-backup' );
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
