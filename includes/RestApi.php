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
		foreach ( [ 'cache_enabled', 'separate_mobile', 'purge_home_on_update' ] as $flag ) {
			if ( array_key_exists( $flag, $body ) ) {
				$changes[ $flag ] = (bool) $body[ $flag ];
			}
		}
		if ( array_key_exists( 'ttl', $body ) ) {
			$changes['ttl'] = max( 600, (int) $body['ttl'] );
		}
		foreach ( [ 'excluded_uris', 'excluded_user_agents' ] as $list ) {
			if ( array_key_exists( $list, $body ) ) {
				$lines             = is_array( $body[ $list ] ) ? $body[ $list ] : preg_split( '/\r\n|\r|\n/', (string) $body[ $list ] );
				$changes[ $list ] = array_values( array_filter( array_map( function ( $line ) {
					return trim( sanitize_text_field( (string) $line ) );
				}, $lines ), 'strlen' ) );
			}
		}

		Config::save( $changes );

		// Settings shape what is cacheable, so stale entries must go.
		$this->engine->purge_all();

		return rest_ensure_response( $this->state() );
	}

	public function purge() {
		$this->engine->purge_all();

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
		$config = Config::load();
		$stats  = $this->engine->driver()->stats();

		return [
			'version'  => REALLYCACHE_VERSION,
			'settings' => [
				'cache_enabled'        => (bool) $config['cache_enabled'],
				'ttl'                  => (int) $config['ttl'],
				'separate_mobile'      => (bool) $config['separate_mobile'],
				'purge_home_on_update' => (bool) $config['purge_home_on_update'],
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
			'dropin'   => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'time'     => time(),
		];
	}
}
