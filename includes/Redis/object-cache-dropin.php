<?php
/**
 * ReallyCache Redis object cache drop-in. Drop-in version: 3
 *
 * Fail-open by design: if Redis is unreachable at any point, the cache
 * degrades to a per-request in-memory cache and the site keeps working.
 * Keys are namespaced per site (DB name + table prefix), so flushing one
 * site never touches another site sharing the same Redis instance.
 *
 * Reads wp-content/reallycache/config.php for tuning: excluded groups
 * (redis_excluded_groups) and a default expiry cap (redis_max_ttl).
 * Persists hit/miss counters to Redis on shutdown for the stats screen.
 */

defined( 'ABSPATH' ) || exit;

// phpredis missing (removed after activation?) — fall back to core's default
// behavior by simply not defining a persistent backend.
if ( ! class_exists( 'Redis' ) ) {
	require_once ABSPATH . WPINC . '/cache.php';
	return;
}

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	$results = [];
	foreach ( $data as $key => $value ) {
		$results[ $key ] = wp_cache_set( $key, $value, $group, $expire );
	}
	return $results;
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	$results = [];
	foreach ( $data as $key => $value ) {
		$results[ $key ] = wp_cache_add( $key, $value, $group, $expire );
	}
	return $results;
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_delete( $key, $group = '' ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	$results = [];
	foreach ( $keys as $key ) {
		$results[ $key ] = wp_cache_delete( $key, $group );
	}
	return $results;
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr_decr( $key, (int) $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr_decr( $key, - (int) $offset, $group );
}

function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush();
}

function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	return $GLOBALS['wp_object_cache']->flush_group( $group );
}

function wp_cache_close() {
	return true;
}

function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( (array) $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( (array) $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	$GLOBALS['wp_object_cache']->switch_to_blog( (int) $blog_id );
}

function wp_cache_supports( $feature ) {
	return in_array( $feature, [ 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ], true );
}

class WP_Object_Cache {

	/** @var Redis|null Null after a connection failure: run degraded, never fatal. */
	private $redis = null;

	private $cache = [];
	private $global_groups = [];
	private $non_persistent = [];
	private $blog_id = 1;
	private $multisite = false;
	private $prefix = '';
	private $max_ttl = 0;

	public $cache_hits = 0;
	public $cache_misses = 0;
	private $redis_hits = 0;
	private $redis_misses = 0;

	/** @var string HMAC secret; empty disables persistence (never fail open on auth). */
	private $secret = '';

	public function __construct() {
		// Site-owner tuning from the ReallyCache config file.
		$config_file = WP_CONTENT_DIR . '/reallycache/config.php';
		if ( is_readable( $config_file ) ) {
			try {
				$reallycache_config_file = $config_file; // Satisfies the config file's access guard.
				$config                  = include $config_file;
			} catch ( Throwable $e ) {
				$config = null;
			}
			if ( is_array( $config ) ) {
				$this->non_persistent = array_values( array_filter( (array) ( $config['redis_excluded_groups'] ?? [] ) ) );
				$this->max_ttl        = max( 0, (int) ( $config['redis_max_ttl'] ?? 0 ) );
			}
		}

		// Values are HMAC-signed before unserialize(): on shared Redis a
		// neighbor must not be able to plant serialized payloads or forge
		// entries. The wp-config auth keys are defined by the time the
		// drop-in loads; without them persistence stays off.
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$this->secret = AUTH_KEY . SECURE_AUTH_KEY;
		}

		$this->multisite = function_exists( 'is_multisite' ) && is_multisite();
		$this->blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

		global $table_prefix;
		$db   = defined( 'DB_NAME' ) ? DB_NAME : 'wp';
		$salt = ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' ) . $this->secret;
		// The secret in the prefix makes it unguessable by other tenants
		// on a shared Redis — they cannot even locate this site's keys.
		$this->prefix = 'rc:' . substr( md5( $db . '|' . (string) $table_prefix . '|' . $salt ), 0, 16 ) . ':';

		if ( '' === $this->secret ) {
			return; // No auth keys — run as a per-request memory cache only.
		}

		try {
			$host     = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : '127.0.0.1';
			$port     = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;
			$path     = defined( 'WP_REDIS_PATH' ) ? WP_REDIS_PATH : '';
			$password = defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : '';
			$database = defined( 'WP_REDIS_DATABASE' ) ? (int) WP_REDIS_DATABASE : 0;

			$redis = new Redis();
			$ok    = $path ? $redis->connect( $path ) : $redis->connect( $host, $port, 1.0 );
			if ( $ok ) {
				$redis->setOption( Redis::OPT_READ_TIMEOUT, 1.0 );
			}
			if ( $ok && $password ) {
				$ok = $redis->auth( $password );
			}
			if ( $ok && $database ) {
				$ok = $redis->select( $database );
			}
			$this->redis = $ok ? $redis : null;
		} catch ( Throwable $e ) {
			$this->redis = null;
		}

		register_shutdown_function( [ $this, 'persist_stats' ] );
	}

	/** Accumulate hit/miss counters in Redis so the stats screen sees totals. */
	public function persist_stats() {
		if ( ! $this->redis || ( ! $this->redis_hits && ! $this->redis_misses ) ) {
			return;
		}
		try {
			if ( $this->redis_hits ) {
				$this->redis->incrBy( $this->prefix . 'stats:hits', $this->redis_hits );
			}
			if ( $this->redis_misses ) {
				$this->redis->incrBy( $this->prefix . 'stats:misses', $this->redis_misses );
			}
			$this->redis_hits   = 0;
			$this->redis_misses = 0;
		} catch ( Throwable $e ) {
			$this->redis = null;
		}
	}

	private function redis_key( $key, $group ) {
		$scope = in_array( $group, $this->global_groups, true ) ? 'g' : (string) $this->blog_id;

		// Colons are the key-structure separator; neutralize them in the
		// variable parts so crafted group/key pairs cannot collide.
		return $this->prefix . $scope . ':' . str_replace( ':', '_', $group ) . ':' . str_replace( ':', '_', (string) $key );
	}

	private function sign( $raw ) {
		return hash_hmac( 'sha256', $raw, $this->secret ) . '|' . $raw;
	}

	/** @return string|false The payload when the signature verifies. */
	private function verify( $stored ) {
		if ( ! is_string( $stored ) || strlen( $stored ) < 65 || '|' !== $stored[64] ) {
			return false;
		}
		$mac = substr( $stored, 0, 64 );
		$raw = substr( $stored, 65 );

		return hash_equals( hash_hmac( 'sha256', $raw, $this->secret ), $mac ) ? $raw : false;
	}

	private function persistent( $group ) {
		return $this->redis && ! in_array( $group, $this->non_persistent, true );
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$group = $group ?: 'default';

		if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
			$found = true;
			$this->cache_hits ++;
			$value = $this->cache[ $group ][ $key ];

			return is_object( $value ) ? clone $value : $value;
		}

		if ( $this->persistent( $group ) ) {
			try {
				$stored = $this->redis->get( $this->redis_key( $key, $group ) );
				$raw    = false === $stored ? false : $this->verify( $stored );
				if ( false !== $raw ) {
					$value = unserialize( $raw ); // phpcs:ignore
					$this->cache[ $group ][ $key ] = $value;
					$found = true;
					$this->cache_hits ++;
					$this->redis_hits ++;

					return is_object( $value ) ? clone $value : $value;
				}
				$this->redis_misses ++;
			} catch ( Throwable $e ) {
				$this->redis = null;
			}
		}

		$found = false;
		$this->cache_misses ++;

		return false;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$values = [];
		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}

		return $values;
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$group = $group ?: 'default';

		$this->cache[ $group ][ $key ] = is_object( $data ) ? clone $data : $data;

		if ( $this->persistent( $group ) ) {
			try {
				$rkey = $this->redis_key( $key, $group );
				$raw  = $this->sign( serialize( $data ) ); // phpcs:ignore
				if ( $expire < 1 && $this->max_ttl > 0 ) {
					$expire = $this->max_ttl;
				}
				$expire > 0 ? $this->redis->setex( $rkey, $expire, $raw ) : $this->redis->set( $rkey, $raw );
			} catch ( Throwable $e ) {
				$this->redis = null;
			}
		}

		return true;
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		$group = $group ?: 'default';

		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}

	public function delete( $key, $group = 'default' ) {
		$group = $group ?: 'default';
		unset( $this->cache[ $group ][ $key ] );

		if ( $this->persistent( $group ) ) {
			try {
				$this->redis->unlink( $this->redis_key( $key, $group ) );
			} catch ( Throwable $e ) {
				$this->redis = null;
			}
		}

		return true;
	}

	public function incr_decr( $key, $offset, $group = 'default' ) {
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found || ! is_numeric( $value ) ) {
			return false;
		}

		$value = max( 0, (int) $value + (int) $offset );
		$this->set( $key, $value, $group );

		return $value;
	}

	/** Flushes this site's keys only — never a neighbor's on shared Redis. */
	public function flush() {
		$this->cache = [];

		if ( $this->redis ) {
			try {
				$cursor = null;
				do {
					$keys = $this->redis->scan( $cursor, $this->prefix . '*', 500 );
					if ( is_array( $keys ) && $keys ) {
						$this->redis->unlink( $keys );
					}
				} while ( $cursor > 0 );
			} catch ( Throwable $e ) {
				$this->redis = null;
			}
		}

		return true;
	}

	public function flush_runtime() {
		$this->cache = [];

		return true;
	}

	public function flush_group( $group ) {
		unset( $this->cache[ $group ] );

		if ( $this->persistent( $group ) ) {
			try {
				$pattern_group = addcslashes( str_replace( ':', '_', $group ), '*?[]\\' );
				foreach ( [ 'g', (string) $this->blog_id ] as $scope ) {
					$cursor = null;
					do {
						$keys = $this->redis->scan( $cursor, $this->prefix . $scope . ':' . $pattern_group . ':*', 500 );
						if ( is_array( $keys ) && $keys ) {
							$this->redis->unlink( $keys );
						}
					} while ( $cursor > 0 );
				}
			} catch ( Throwable $e ) {
				$this->redis = null;
			}
		}

		return true;
	}

	public function add_global_groups( array $groups ) {
		$this->global_groups = array_unique( array_merge( $this->global_groups, $groups ) );
	}

	public function add_non_persistent_groups( array $groups ) {
		$this->non_persistent = array_unique( array_merge( $this->non_persistent, $groups ) );
	}

	public function switch_to_blog( $blog_id ) {
		if ( $this->multisite ) {
			$this->blog_id = (int) $blog_id;
			$this->cache   = [];
		}
	}

	public function stats() {
		echo '<p>ReallyCache Redis — hits: ' . (int) $this->cache_hits . ', misses: ' . (int) $this->cache_misses . '</p>';
	}
}
