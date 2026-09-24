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

	/**
	 * A connected client: phpredis when the extension exists, otherwise
	 * the plugin's pure-PHP socket client (CloudPanel-style stacks run the
	 * Redis service without the PHP extension).
	 *
	 * @return \Redis|SocketClient|null
	 */
	public static function client() {
		try {
			$p     = self::params();
			$redis = class_exists( '\Redis' ) ? new \Redis() : new SocketClient();
			$ok    = $p['path'] ? $redis->connect( $p['path'] ) : $redis->connect( $p['host'], $p['port'], 0.5 );
			if ( $ok && $redis instanceof \Redis ) {
				$redis->setOption( \Redis::OPT_READ_TIMEOUT, 1.0 );
			}
			if ( $ok && $p['password'] ) {
				$ok = $redis->auth( $p['password'] );
			}
			if ( $ok && $p['database'] ) {
				$ok = $redis->select( $p['database'] );
			}

			return $ok ? $redis : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public static function available() {
		static $available = null;
		if ( null !== $available ) {
			return $available;
		}

		$available = false;
		try {
			$redis = self::client();
			if ( $redis ) {
				$available = (bool) $redis->ping();
				$redis->close();
			}
		} catch ( \Throwable $e ) {
			$available = false;
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
		self::maybe_refresh_dropin();

		$status = [
			'available' => self::available(),
			'active'    => self::active(),
			'foreign'   => self::foreign(),
			'client'    => class_exists( '\Redis' ) ? 'phpredis' : 'socket',
		];
		if ( $status['active'] ) {
			$status['insights'] = self::insights();
		}

		return $status;
	}

	/** Redeploy our drop-in when the plugin ships a newer version of it. */
	private static function maybe_refresh_dropin() {
		if ( self::active() && false === strpos( (string) file_get_contents( self::dropin_path() ), 'Drop-in version: 4' ) ) {
			copy( REALLYCACHE_DIR . '/includes/Redis/object-cache-dropin.php', self::dropin_path() );
		}
	}

	/** Must mirror the drop-in's prefix derivation exactly. */
	public static function key_prefix() {
		global $table_prefix;
		$db     = defined( 'DB_NAME' ) ? DB_NAME : 'wp';
		$secret = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
		$salt   = ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' ) . $secret;

		return 'rc:' . substr( md5( $db . '|' . (string) $table_prefix . '|' . $salt ), 0, 16 ) . ':';
	}

	/**
	 * What actually lives in Redis: our key count grouped by cache group,
	 * accumulated hit/miss counters and the instance's memory usage.
	 * Cached briefly — the admin app polls every couple of seconds.
	 */
	public static function insights() {
		$cached = get_transient( 'reallycache_redis_insights' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		try {
			$redis = self::client();
			if ( ! $redis ) {
				return null;
			}

			$prefix = self::key_prefix();

			$info   = (array) $redis->info( 'memory' );
			$hits   = (int) $redis->get( $prefix . 'stats:hits' );
			$misses = (int) $redis->get( $prefix . 'stats:misses' );

			$keys   = 0;
			$groups = [];
			$cursor = null;
			$rounds = 0;
			do {
				$batch = $redis->scan( $cursor, $prefix . '*', 1000 );
				if ( is_array( $batch ) ) {
					foreach ( $batch as $key ) {
						$parts = explode( ':', $key, 5 );
						if ( 'stats' === ( $parts[2] ?? '' ) ) {
							continue;
						}
						$keys ++;
						$group            = $parts[3] ?? '?';
						$groups[ $group ] = ( $groups[ $group ] ?? 0 ) + 1;
					}
				}
			} while ( $cursor > 0 && ++ $rounds < 50 );

			arsort( $groups );

			$insights = [
				'hits'     => $hits,
				'misses'   => $misses,
				'hit_rate' => ( $hits + $misses ) > 0 ? (int) round( $hits * 100 / ( $hits + $misses ) ) : 0,
				'keys'     => $keys,
				'memory'   => (string) ( $info['used_memory_human'] ?? '' ),
				'groups'   => array_slice( $groups, 0, 10, true ),
			];
			$redis->close();

			set_transient( 'reallycache_redis_insights', $insights, 10 );

			return $insights;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
