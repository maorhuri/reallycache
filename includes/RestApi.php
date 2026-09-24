<?php
namespace ReallyCache;

/**
 * REST endpoints for the admin app, under reallycache/v1. Every route
 * requires manage_options and the standard cookie-auth nonce.
 */
class RestApi {

	private $engine;

	public function __construct( Engine $engine ) {
		$this->engine = $engine;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		$routes = [
			[ 'GET', '/state', 'get_state' ],
			[ 'POST', '/settings', 'save_settings' ],
			[ 'POST', '/purge', 'purge' ],
			[ 'POST', '/warm', 'warm' ],
			[ 'POST', '/webp-scan', 'webp_scan' ],
			[ 'POST', '/dev-mode', 'dev_mode' ],
		];

		foreach ( $routes as [ $method, $path, $callback ] ) {
			register_rest_route( 'reallycache/v1', $path, [
				'methods'             => $method,
				'callback'            => [ $this, $callback ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			] );
		}

		// Worker endpoints: hit by cookie-less loopback requests, so they are
		// public but demand the stored secret token instead.
		foreach ( [ '/warm-worker' => [ 'ReallyCache\\Warmer', 'work' ], '/webp-worker' => [ 'ReallyCache\\Media\\Webp', 'work' ] ] as $path => $callback ) {
			register_rest_route( 'reallycache/v1', $path, [
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( \WP_REST_Request $request ) use ( $callback ) {
					if ( ! Warmer::verify_token( (string) $request->get_param( 'key' ) ) ) {
						return new \WP_Error( 'reallycache_forbidden', 'Bad token.', [ 'status' => 403 ] );
					}
					ignore_user_abort( true );
					call_user_func( $callback );

					return rest_ensure_response( [ 'ok' => true ] );
				},
			] );
		}
	}

	public function get_state() {
		return rest_ensure_response( $this->state() );
	}

	public function save_settings( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'reallycache_bad_request', __( 'Invalid settings payload.', 'reallycache' ), [ 'status' => 400 ] );
		}

		$changes = [];
		foreach ( [ 'cache_enabled', 'separate_mobile', 'smart_cache', 'woocommerce_protection', 'webp_enabled', 'browser_cache', 'redis_object_cache' ] as $flag ) {
			if ( array_key_exists( $flag, $body ) ) {
				$changes[ $flag ] = (bool) $body[ $flag ];
			}
		}
		if ( array_key_exists( 'ttl', $body ) ) {
			$ttl            = (int) $body['ttl'];
			$changes['ttl'] = $ttl <= 0 ? 0 : max( 600, $ttl );
		}
		foreach ( [ 'excluded_uris', 'excluded_user_agents' ] as $list ) {
			if ( array_key_exists( $list, $body ) ) {
				$lines             = is_array( $body[ $list ] ) ? $body[ $list ] : preg_split( '/\r\n|\r|\n/', (string) $body[ $list ] );
				$changes[ $list ] = array_values( array_filter( array_map( function ( $line ) {
					return trim( sanitize_text_field( (string) $line ) );
				}, $lines ), 'strlen' ) );
			}
		}

		$config = Config::save( $changes );

		BrowserCache::sync( $config );
		Redis\Manager::sync( $config );

		// Settings shape what is cacheable, so stale entries must go.
		$this->engine->purge_all();

		return rest_ensure_response( $this->state() );
	}

	public function purge() {
		$this->engine->purge_all();

		return rest_ensure_response( $this->state() );
	}

	public function warm() {
		Warmer::seed_site();

		return rest_ensure_response( $this->state() );
	}

	public function webp_scan() {
		Media\Webp::seed_library();

		return rest_ensure_response( $this->state() );
	}

	public function dev_mode( \WP_REST_Request $request ) {
		$duration = sanitize_key( (string) $request->get_param( 'duration' ) );

		$until = 0;
		if ( '1h' === $duration ) {
			$until = time() + HOUR_IN_SECONDS;
		} elseif ( '24h' === $duration ) {
			$until = time() + DAY_IN_SECONDS;
		} elseif ( 'off' !== $duration ) {
			return new \WP_Error( 'reallycache_bad_request', __( 'Unknown duration.', 'reallycache' ), [ 'status' => 400 ] );
		}

		Config::save( [ 'dev_mode_until' => $until ] );

		return rest_ensure_response( $this->state() );
	}

	/** Everything the admin app needs, in one payload. */
	private function state() {
		// Status polling doubles as the queue pump: every poll guarantees
		// the background workers are alive or makes progress inline.
		Warmer::maybe_pump();
		Media\Webp::maybe_pump();

		$config = Config::load();
		$stats  = $this->engine->driver()->stats();

		return [
			'version'  => REALLYCACHE_VERSION,
			'settings' => [
				'cache_enabled'        => (bool) $config['cache_enabled'],
				'ttl'                  => (int) $config['ttl'],
				'separate_mobile'      => (bool) $config['separate_mobile'],
				'smart_cache'            => (bool) $config['smart_cache'],
				'woocommerce_protection' => (bool) $config['woocommerce_protection'],
				'webp_enabled'           => (bool) $config['webp_enabled'],
				'browser_cache'          => (bool) $config['browser_cache'],
				'redis_object_cache'     => (bool) $config['redis_object_cache'],
				'excluded_uris'        => array_values( (array) $config['excluded_uris'] ),
				'excluded_user_agents' => array_values( (array) $config['excluded_user_agents'] ),
			],
			'dev_mode' => [
				'active' => $config['dev_mode_until'] > time(),
				'until'  => (int) $config['dev_mode_until'],
			],
			'stats'    => [
				'pages' => (int) $stats['pages'],
				'size'  => (int) $stats['size'],
			],
			'server'   => [
				'name' => ServerDetector::label(),
				'tier' => ServerDetector::best_available_tier(),
			],
			'dropin'     => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'woo_active' => class_exists( 'WooCommerce' ),
			'warm'       => Warmer::status(),
			'webp'       => Media\Webp::status(),
			'redis'      => Redis\Manager::status(),
			'time'       => time(),
		];
	}
}
