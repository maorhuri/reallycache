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
	const DAILY_HOOK   = 'reallycache_daily_warm';
	const LOCK         = 'reallycache_warm_lock';
	const STOP_FLAG    = 'reallycache_warm_stopped';

	/**
	 * Start a full-site warm-up. The queue never holds the whole site:
	 * a cursor pulls the next 200 posts by ID as the queue drains, so even
	 * a 100,000-product catalog warms gradually with bounded memory and
	 * bounded option size. Already-fresh pages are skipped at fetch time.
	 */
	public static function seed_site() {
		delete_option( self::STOP_FLAG );

		$config = Config::load();
		$max    = max( 0, (int) ( $config['warm_max'] ?? 500 ) );

		update_option( self::STATE_OPTION, [
			'done'    => 0,
			'total'   => self::estimate_total( $max ),
			'current' => '',
			'updated' => time(),
			'seed'    => [ 'cursor' => 0, 'seeded' => 0, 'terms_offset' => 0, 'phase' => 'posts', 'max' => $max ],
		], false );
		delete_option( self::QUEUE_OPTION );

		self::enqueue( [ home_url( '/' ) ] );
		self::refill();
	}

	/** @return string[] Viewable public post types. */
	private static function viewable_types() {
		return array_values( array_filter( get_post_types( [ 'public' => true ] ), 'is_post_type_viewable' ) );
	}

	/** Cheap count of what a full warm-up will cover, for the progress bar. */
	private static function estimate_total( $max ) {
		$posts = 0;
		foreach ( self::viewable_types() as $type ) {
			$counts = wp_count_posts( $type );
			$posts += (int) ( $counts->publish ?? 0 );
		}
		if ( $max > 0 ) {
			$posts = min( $posts, $max );
		}

		$taxonomies = array_values( get_taxonomies( [ 'public' => true ] ) );
		$terms      = $taxonomies ? wp_count_terms( [ 'taxonomy' => $taxonomies, 'hide_empty' => true ] ) : 0;
		$terms      = is_wp_error( $terms ) ? 0 : (int) $terms;

		return 1 + $posts + $terms;
	}

	/**
	 * Top the queue up with the next slice of the site. Returns true when
	 * anything was added or seeding is still in progress.
	 */
	public static function refill() {
		// The stop flag lives OUTSIDE the state blob on purpose: a worker
		// mid-batch re-writes the whole state it read earlier, which used
		// to resurrect a stopped seed (read old phase → write it back).
		// A separate option cannot be clobbered that way.
		if ( get_option( self::STOP_FLAG ) ) {
			return false;
		}

		$state = self::state();
		$seed  = is_array( $state['seed'] ?? null ) ? $state['seed'] : null;
		if ( ! $seed || 'done' === $seed['phase'] ) {
			return false;
		}

		global $wpdb;
		$urls = [];

		if ( 'posts' === $seed['phase'] ) {
			$remaining = $seed['max'] > 0 ? $seed['max'] - (int) $seed['seeded'] : PHP_INT_MAX;
			if ( $remaining < 1 ) {
				$seed['phase'] = 'terms';
			} else {
				$types = self::viewable_types();
				$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
				// Keyset pagination on the primary key: OFFSET on a large
				// table gets slower the deeper it goes; ID > cursor doesn't.
				$sql = $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
					array_merge( $types, [ (int) $seed['cursor'], min( 200, $remaining ) ] )
				);
				$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

				foreach ( $ids as $id ) {
					$link = get_permalink( $id );
					if ( $link ) {
						$urls[] = $link;
					}
				}
				if ( $ids ) {
					$seed['cursor']  = max( $ids );
					$seed['seeded'] += count( $ids );
				}
				if ( count( $ids ) < min( 200, $remaining ) ) {
					$seed['phase'] = 'terms';
				}
			}
		}

		if ( 'terms' === $seed['phase'] && count( $urls ) < 100 ) {
			$taxonomies = array_values( get_taxonomies( [ 'public' => true ] ) );
			$terms      = $taxonomies ? get_terms( [
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'number'     => 200,
				'offset'     => (int) $seed['terms_offset'],
			] ) : [];
			$terms = is_wp_error( $terms ) ? [] : (array) $terms;

			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
			$seed['terms_offset'] += count( $terms );
			if ( count( $terms ) < 200 ) {
				$seed['phase'] = 'done';
			}
		}

		$state            = self::state();
		$state['seed']    = $seed;
		$state['updated'] = time();
		update_option( self::STATE_OPTION, $state, false );

		if ( $urls ) {
			self::enqueue( $urls );
		}

		return ! empty( $urls ) || 'done' !== $seed['phase'];
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
		// Grow-only: during a seeded run the total is a site-wide estimate
		// that a small in-flight queue must not shrink.
		$state['total'] = max( (int) $state['total'], (int) $state['done'] + count( $queue ) );
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
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => [ 'key' => self::token() ],
		] );
	}

	/** Atomic lock via add_option — get/set transients can race. */
	private static function acquire_lock() {
		if ( add_option( self::LOCK, (string) time(), '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK );
		if ( $held && ( time() - $held ) > 150 ) {
			update_option( self::LOCK, (string) time(), false );
			return true;
		}

		return false;
	}

	private static function release_lock() {
		delete_option( self::LOCK );
	}

	private static function locked() {
		$held = (int) get_option( self::LOCK );

		return $held && ( time() - $held ) <= 150;
	}

	/**
	 * The worker: processes the queue for up to ~20 seconds under a lock,
	 * then re-fires itself if work remains.
	 */
	public static function work() {
		if ( ! self::acquire_lock() ) {
			return;
		}

		$started = time();
		while ( ( time() - $started ) < 20 ) {
			if ( ! self::step( (int) apply_filters( 'reallycache_warm_batch', 3 ) ) ) {
				break;
			}
			usleep( 250000 );
		}

		self::release_lock();

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
			if ( ! self::refill() ) {
				return false;
			}
			$queue = (array) get_option( self::QUEUE_OPTION, [] );
			if ( empty( $queue ) ) {
				return false;
			}
		}

		$batch = array_splice( $queue, 0, max( 1, $count ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		// Top up before the queue runs dry, so seeding and warming overlap.
		if ( count( $queue ) < 20 ) {
			self::refill();
		}

		$config = Config::load();
		$engine = \ReallyCache\Engine::instance();
		foreach ( $batch as $url ) {
			$state            = self::state();
			$state['current'] = (string) $url;
			$state['updated'] = time();
			update_option( self::STATE_OPTION, $state, false );

			// A visitor may have warmed it since it was queued.
			if ( ! $engine || ! $engine->url_is_cached( $url ) ) {
				self::warm_url( $url, ! empty( $config['separate_mobile'] ) );
			}

			$state            = self::state();
			$state['done']   += 1;
			$state['updated'] = time();
			update_option( self::STATE_OPTION, $state, false );
		}

		return true;
	}

	/** Called on every /state poll: keeps the queue moving no matter what. */
	public static function maybe_pump() {
		if ( self::queued() < 1 || self::locked() ) {
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
			if ( ! self::locked() ) {
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
		// Same-host only, no redirects: queue URLs pass through filters,
		// and following a crafted redirect would be blind SSRF.
		$url_host  = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $url_host || $url_host !== $site_host ) {
			return;
		}

		$args = [
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
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
		$state   = self::state();
		$queued  = self::queued();
		$seeding = ! get_option( self::STOP_FLAG )
			&& is_array( $state['seed'] ?? null ) && 'done' !== ( $state['seed']['phase'] ?? 'done' );

		return [
			'done'    => (int) $state['done'],
			'total'   => (int) $state['total'],
			'queued'  => $queued,
			'current' => (string) $state['current'],
			'running' => $queued > 0 || $seeding,
		];
	}

	/**
	 * Nightly full refresh: at the configured hour (site timezone) the
	 * whole cache is purged and rebuilt through the gradual queue, so
	 * every page starts the day with a fresh TTL. Self-rescheduling
	 * single events keep the chosen hour exact across DST changes.
	 */
	public static function schedule_daily( array $config ) {
		wp_clear_scheduled_hook( self::DAILY_HOOK );

		if ( empty( $config['daily_warm'] ) ) {
			return;
		}

		$hour = max( 0, min( 23, (int) ( $config['daily_warm_hour'] ?? 0 ) ) );
		try {
			$timezone = wp_timezone();
			$next     = ( new \DateTimeImmutable( 'today', $timezone ) )->setTime( $hour, 0 );
			if ( $next <= new \DateTimeImmutable( 'now', $timezone ) ) {
				$next = $next->modify( '+1 day' );
			}
			$timestamp = $next->getTimestamp();
		} catch ( \Throwable $e ) {
			$timestamp = time() + DAY_IN_SECONDS;
		}

		wp_schedule_single_event( $timestamp, self::DAILY_HOOK );
	}

	public static function run_daily() {
		$engine = \ReallyCache\Engine::instance();
		if ( $engine ) {
			$engine->purge_all();
		}
		self::seed_site();
		self::schedule_daily( Config::load() );
	}

	/** Stop a running warm-up: drop the queue; an in-flight batch finishes. */
	public static function stop() {
		update_option( self::STOP_FLAG, (string) time(), false );
		delete_option( self::QUEUE_OPTION );
		wp_clear_scheduled_hook( self::TICK_HOOK );

		$state            = self::state();
		$state['current'] = '';
		$state['updated'] = time();
		if ( is_array( $state['seed'] ?? null ) ) {
			$state['seed']['phase'] = 'done';
		}
		update_option( self::STATE_OPTION, $state, false );
	}

	public static function reset() {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::STATE_OPTION );
		delete_option( self::LOCK );
		delete_option( self::TOKEN_OPTION ); // Rotate the worker token.
		delete_option( self::STOP_FLAG );
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
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

		return wp_parse_args( is_array( $state ) ? $state : [], [ 'done' => 0, 'total' => 0, 'current' => '', 'updated' => 0, 'seed' => null ] );
	}
}
