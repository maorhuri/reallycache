<?php
namespace ReallyCache\Redis;

/**
 * Detects Redis on the server and manages the object-cache.php drop-in.
 * Opt-in: the toggle only appears in the UI when Redis is actually
 * reachable, and a drop-in owned by another plugin is never touched.
 *
 * Connection overrides via wp-config constants:
 * WP_REDIS_HOST, WP_REDIS_PORT, WP_REDIS_PATH (unix socket),
 * WP_REDIS_PASSWORD, WP_REDIS_DATABASE.
 */
class Manager {

	public static function params() {
		return [
			'host'     => defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1',
			'port'     => defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379,
			'path'     => defined( 'WP_REDIS_PATH' ) ? WP_REDIS_PATH : '',
			'password' => defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '',
			'database' => defined( 'WP_REDIS_DATABASE' ) ? (int) WP_REDIS_DATABASE : 0,
		];
	}

	public static function available() {
		static $available = null;
		if ( null !== $available ) {
			return $available;
		}

		$available = false;
		if ( extension_loaded( 'redis' ) ) {
			try {
				$p     = self::params();
				$redis = new \Redis();
				$ok    = $p['path'] ? $redis->connect( $p['path'] ) : $redis->connect( $p['host'], $p['port'], 0.5 );
				if ( $ok && $p['password'] ) {
					$ok = $redis->auth( $p['password'] );
				}
				$available = $ok && $redis->ping();
				$redis->close();
			} catch ( \Throwable $e ) {
				$available = false;
			}
		}

		return $available;
	}

	public static function dropin_path() {
		return WP_CONTENT_DIR . '/object-cache.php';
	}

	public static function active() {
		return is_file( self::dropin_path() )
			&& false !== strpos( (string) file_get_contents( self::dropin_path() ), 'ReallyCache' );
	}

	/** True when some other plugin owns the drop-in — never overwrite it. */
	public static function foreign() {
		return is_file( self::dropin_path() ) && ! self::active();
	}

	public static function sync( array $config ) {
		$want = ! empty( $config['redis_object_cache'] );

		if ( $want && ! self::active() && ! self::foreign() && self::available() ) {
			copy( REALLYCACHE_DIR . '/includes/Redis/object-cache-dropin.php', self::dropin_path() );
		} elseif ( ! $want && self::active() ) {
			unlink( self::dropin_path() );
		}
	}

	public static function disable() {
		if ( self::active() ) {
			unlink( self::dropin_path() );
		}
	}

	public static function status() {
		return [
			'available' => self::available(),
			'active'    => self::active(),
			'foreign'   => self::foreign(),
		];
	}
}
