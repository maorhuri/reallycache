<?php
namespace ReallyCache;

/**
 * Background cache warmer with a real worker loop.
 *
 * WP-Cron alone is unreliable for this (it only fires future events when
 * traffic happens to arrive), so the queue is pumped three ways:
 *  1. A loopback worker request that processes batches for ~20s, then
 *     re-fires itself while the queue is non-empty.
 *  2. The admin app's status polling: if the worker looks dead, each poll
 *     processes a small batch inline, so progress is visible regardless.
 *  3. A cron watchdog every minute as the last resort.
 *
 * Pacing is built in: small batches, blocking fetches (the warmer can never
 * outrun the server rendering pages) and a pause between URLs.
 */
class Warmer {

	const QUEUE_OPTION = 'reallycache_warm_queue';
	const STATE_OPTION = 'reallycache_warm_state';
	const TOKEN_OPTION = 'reallycache_worker_token';
	const TICK_HOOK    = 'reallycache_warm_tick';
	const LOCK         = 'reallycache_warm_lock';

	/** Queue a full-site warm-up and start processing. */
	public static function seed_site() {
		$urls = [ home_url( '/' ) ];

		$types = array_filter( get_post_types( [ 'public' => true ] ), 'is_post_type_viewable' );
		$ids   = get_posts( [
			'post_type'      => array_values( $types ),
			'post_status'    => 'publish',
			'posts_per_page' => (int) apply_filters( 'reallycache_warm_max_posts', 500 ),
			'orderby'        => 'modified',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}

		$taxonomies = get_taxonomies( [ 'public' => true ] );
		$terms      = get_terms( [ 'taxonomy' => array_values( $taxonomies ), 'hide_empty' => true, 'number' => 200 ] );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		update_option( self::STATE_OPTION, [ 'done' => 0, 'total' => 0, 'current' => '', 'updated' => time() ], false );
		self::enqueue( $urls );
	}

	/** Add URLs to the queue (deduped) and make sure processing runs. */
	public static function enqueue( array $urls ) {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( empty( $urls ) ) {
			return;
		}

		$queue = (array) get_option( self::QUEUE_OPTION, [] );
		$queue = array_values( array_unique( array_merge( $queue, $urls ) ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		$state          = self::state();
		$state['total'] = (int) $state['done'] + count( $queue );
		update_option( self::STATE_OPTION, $state, false );

		self::kick();
	}

	/** Fire the background worker and arm the cron watchdog. */
	public static function kick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::TICK_HOOK );
		}

		wp_remote_post( rest_url( 'reallycache/v1/warm-worker' ), [
			'blocking'  => false,
			'timeout'   => 0.02,
			'sslverify' => false,
			'body'      => [ 'key' => self::token() ],
		] );
	}

	/**
	 * The worker: processes the queue for up to ~20 seconds under a lock,
	 * then re-fires itself if work remains.
	 */
	public static function work() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 90 );

		$started = time();
		while ( ( time() - $started ) < 20 ) {
			if ( ! self::step( (int) apply_filters( 'reallycache_warm_batch', 3 ) ) ) {
				break;
			}
			usleep( 250000 );
		}

		delete_transient( self::LOCK );

		if ( self::queued() > 0 ) {
			self::kick();
		}
	}

	/**
	 * Warm up to $count URLs from the queue. Returns false when it's empty.
	 * Also called inline from status polling when the worker looks dead.
	 */
	public static function step( $count ) {
		$queue = (array) get_option( self::QUEUE_OPTION, [] );
		if ( empty( $queue ) ) {
			return false;
		}

		$batch = array_splice( $queue, 0, max( 1, $count ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		$config = Config::load();
		foreach ( $batch as $url ) {
			$state            = self::state();
			$state['current'] = (string) $url;
			$state['updated'] = time();
			update_option( self::STATE_OPTION, $state, false );

			self::warm_url( $url, ! empty( $config['separate_mobile'] ) );

			$state            = self::state();
			$state['done']   += 1;
			$state['updated'] = time();
			update_option( self::STATE_OPTION, $state, false );
		}

		return true;
	}

	/** Called on every /state poll: keeps the queue moving no matter what. */
	public static function maybe_pump() {
		if ( self::queued() < 1 || get_transient( self::LOCK ) ) {
			return;
		}

		$stale = time() - (int) self::state()['updated'];
		if ( $stale >= 15 ) {
			// The loopback worker looks dead (blocked on this host?) —
			// make progress inside this request so the UI always moves.
			self::step( 2 );
		} elseif ( $stale >= 6 ) {
			self::kick();
		}
	}

	/** Cron watchdog: the last-resort pump. */
	public static function tick() {
		if ( self::queued() > 0 ) {
			if ( ! get_transient( self::LOCK ) ) {
				self::kick();
			}
			wp_schedule_single_event( time() + 60, self::TICK_HOOK );
		}
	}

	/**
	 * One blocking GET per variant. Blocking is deliberate: it paces the
	 * batch so the warmer can never outrun the server rendering the pages.
	 */
	public static function warm_url( $url, $mobile_too = false ) {
		$args = [
			'timeout'     => 15,
			'redirection' => 2,
			'sslverify'   => false,
			'headers'     => [ 'Accept-Encoding' => 'gzip', 'X-ReallyCache-Warm' => '1' ],
			'user-agent'  => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36 ReallyCache-Warmer',
		];
		wp_remote_get( $url, $args );

		if ( $mobile_too ) {
			$args['user-agent'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1 ReallyCache-Warmer';
			wp_remote_get( $url, $args );
		}
	}

	/** @return array{done:int,total:int,queued:int,running:bool,current:string} */
	public static function status() {
		$state  = self::state();
		$queued = self::queued();

		return [
			'done'    => (int) $state['done'],
			'total'   => (int) $state['total'],
			'queued'  => $queued,
			'current' => (string) $state['current'],
			'running' => $queued > 0,
		];
	}

	/** Stop a running warm-up: drop the queue; an in-flight batch finishes. */
	public static function stop() {
		delete_option( self::QUEUE_OPTION );
		wp_clear_scheduled_hook( self::TICK_HOOK );

		$state            = self::state();
		$state['current'] = '';
		$state['updated'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function reset() {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::STATE_OPTION );
		delete_transient( self::LOCK );
		wp_clear_scheduled_hook( self::TICK_HOOK );
	}

	/** Shared secret for the public worker endpoints (loopbacks carry no cookies). */
	public static function token() {
		$token = get_option( self::TOKEN_OPTION );
		if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
			$token = wp_generate_password( 48, false, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}

		return $token;
	}

	public static function verify_token( $key ) {
		return is_string( $key ) && hash_equals( self::token(), $key );
	}

	private static function queued() {
		return count( (array) get_option( self::QUEUE_OPTION, [] ) );
	}

	private static function state() {
		$state = get_option( self::STATE_OPTION, [] );

		return wp_parse_args( is_array( $state ) ? $state : [], [ 'done' => 0, 'total' => 0, 'current' => '', 'updated' => 0 ] );
	}
}
