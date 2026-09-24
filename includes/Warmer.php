<?php
namespace ReallyCache;

/**
 * Background cache warmer. Everything goes through a queue processed in
 * small batches over WP-Cron, so warming never stampedes the server:
 * a few blocking requests per tick, a pause, then the next tick.
 *
 * Two entry points:
 *  - seed_site(): full warm-up (home, posts, pages, products, archives).
 *  - enqueue(): targeted re-warm of URLs that were just purged (smart cache).
 */
class Warmer {

	const QUEUE_OPTION = 'reallycache_warm_queue';
	const STATE_OPTION = 'reallycache_warm_state';
	const TICK_HOOK    = 'reallycache_warm_tick';

	/** Queue a full-site warm-up and start processing. */
	public static function seed_site() {
		$urls   = [ home_url( '/' ) ];
		$config = Config::load();

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

		update_option( self::STATE_OPTION, [ 'done' => 0, 'total' => 0, 'updated' => time() ], false );
		self::enqueue( $urls );
	}

	/** Add URLs to the queue (deduped) and make sure a tick is scheduled. */
	public static function enqueue( array $urls ) {
		$urls = array_values( array_unique( array_filter( $urls ) ) );
		if ( empty( $urls ) ) {
			return;
		}

		$queue = (array) get_option( self::QUEUE_OPTION, [] );
		$queue = array_values( array_unique( array_merge( $queue, $urls ) ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		$state          = self::state();
		$state['total'] = $state['done'] + count( $queue );
		update_option( self::STATE_OPTION, $state, false );

		self::kick();
	}

	/** Process one small batch, then schedule the next tick. */
	public static function tick() {
		$queue = (array) get_option( self::QUEUE_OPTION, [] );
		if ( empty( $queue ) ) {
			return;
		}

		$config = Config::load();
		$batch  = array_splice( $queue, 0, max( 1, (int) apply_filters( 'reallycache_warm_batch', 3 ) ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		foreach ( $batch as $url ) {
			self::warm_url( $url, ! empty( $config['separate_mobile'] ) );
		}

		$state            = self::state();
		$state['done']   += count( $batch );
		$state['updated'] = time();
		update_option( self::STATE_OPTION, $state, false );

		if ( ! empty( $queue ) ) {
			self::kick( (int) apply_filters( 'reallycache_warm_pause', 2 ) );
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

	/** @return array{done:int,total:int,queued:int,running:bool} */
	public static function status() {
		$state  = self::state();
		$queued = count( (array) get_option( self::QUEUE_OPTION, [] ) );

		return [
			'done'    => (int) $state['done'],
			'total'   => (int) $state['total'],
			'queued'  => $queued,
			'running' => $queued > 0,
		];
	}

	public static function reset() {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::STATE_OPTION );
		wp_clear_scheduled_hook( self::TICK_HOOK );
	}

	private static function state() {
		$state = get_option( self::STATE_OPTION, [] );

		return wp_parse_args( is_array( $state ) ? $state : [], [ 'done' => 0, 'total' => 0, 'updated' => 0 ] );
	}

	private static function kick( $delay = 1 ) {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::TICK_HOOK );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}
