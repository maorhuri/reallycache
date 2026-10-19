<?php
namespace ReallyCache;

/**
 * Plugin updates straight from GitHub — no wordpress.org involved.
 * WordPress's normal update UI (banner, button, auto-updates toggle) is
 * pointed at the repo's main branch: bump the Version header, push, and
 * every site offers the update.
 *
 * Works with a public repo out of the box. For a private repo, define
 * REALLYCACHE_GH_TOKEN in wp-config.php (a fine-grained token with
 * read-only Contents permission) or use the reallycache_github_token
 * filter; the token is attached to both the version check and the
 * package download.
 */
class Updater {

	const REPO      = 'maorhuri/reallycache';
	const CACHE_KEY = 'reallycache_update_check';

	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'details' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_folder' ], 10, 4 );
		add_filter( 'http_request_args', [ __CLASS__, 'authorize_download' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'after_update' ], 10, 2 );

		// Self-heal whenever the code version changes — covers both auto
		// updates and a manually replaced folder (git pull): refresh the
		// advanced-cache drop-in and purge, since rewriters may differ.
		add_action( 'admin_init', [ __CLASS__, 'maybe_migrate' ], 5 );
	}

	private static function token() {
		$token = defined( 'REALLYCACHE_GH_TOKEN' ) ? REALLYCACHE_GH_TOKEN : '';

		return (string) apply_filters( 'reallycache_github_token', $token );
	}

	/** @return array{version:string,package:string}|null Cached for 6 hours. */
	public static function remote() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$headers = [
			'Accept'     => 'application/vnd.github.raw',
			'User-Agent' => 'ReallyCache-Updater',
		];
		if ( self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/contents/reallycache.php?ref=main',
			[ 'timeout' => 10, 'headers' => $headers ]
		);

		$remote = [];
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body = wp_remote_retrieve_body( $response );
			if ( preg_match( '/^\s*Version:\s*([0-9][0-9a-z.\-]*)/mi', $body, $match ) ) {
				$remote = [
					'version' => $match[1],
					'package' => 'https://api.github.com/repos/' . self::REPO . '/zipball/main',
				];
			}
		}

		// Failures are cached too (shorter), so an unreachable GitHub never
		// hammers the API from every admin page load.
		set_site_transient( self::CACHE_KEY, $remote, $remote ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );

		return $remote ?: null;
	}

	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$basename = plugin_basename( REALLYCACHE_FILE );
		$remote   = self::remote();

		if ( $remote && version_compare( $remote['version'], REALLYCACHE_VERSION, '>' ) ) {
			$transient->response[ $basename ] = (object) [
				'slug'         => 'reallycache',
				'plugin'       => $basename,
				'new_version'  => $remote['version'],
				'package'      => $remote['package'],
				'url'          => 'https://github.com/' . self::REPO,
				'tested'       => '6.8',
				'requires_php' => '7.4',
				'icons'        => [],
			];
		} else {
			$transient->no_update[ $basename ] = (object) [
				'slug'        => 'reallycache',
				'plugin'      => $basename,
				'new_version' => REALLYCACHE_VERSION,
				'url'         => '',
				'package'     => '',
			];
		}

		return $transient;
	}

	/** The "View details" popup on the Plugins screen. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'reallycache' !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$remote = self::remote();

		return (object) [
			'name'          => 'ReallyCache',
			'slug'          => 'reallycache',
			'version'       => $remote['version'] ?? REALLYCACHE_VERSION,
			'author'        => 'MVN',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires_php'  => '7.4',
			'sections'      => [
				'description' => __( 'Really fast, really simple page caching by MVN.', 'reallycache' ),
				'changelog'   => __( 'See the changelog in readme.txt inside the plugin, or the commit history on GitHub.', 'reallycache' ),
			],
			'download_link' => $remote['package'] ?? '',
		];
	}

	/** A private-repo package download needs the same token as the API. */
	public static function authorize_download( $args, $url ) {
		if ( self::token() && 0 === strpos( (string) $url, 'https://api.github.com/repos/' . self::REPO . '/zipball' ) ) {
			$args['headers']                  = (array) ( $args['headers'] ?? [] );
			$args['headers']['Authorization'] = 'Bearer ' . self::token();
		}

		return $args;
	}

	/**
	 * GitHub zipballs extract to "owner-repo-hash/"; WordPress must end up
	 * with the SAME folder the plugin is already installed in, or it would
	 * deactivate itself on update. The folder name is taken from the current
	 * install (e.g. "reallycache" or "reallycache-main"), never hardcoded.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( plugin_basename( REALLYCACHE_FILE ) !== ( $hook_extra['plugin'] ?? '' ) ) {
			return $source;
		}

		global $wp_filesystem;
		$folder = dirname( plugin_basename( REALLYCACHE_FILE ) ); // e.g. "reallycache-main".
		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $folder . '/';

		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
			return $target;
		}

		return $source;
	}

	public static function after_update( $upgrader, $extra ) {
		if ( 'update' === ( $extra['action'] ?? '' ) && 'plugin' === ( $extra['type'] ?? '' )
			&& in_array( plugin_basename( REALLYCACHE_FILE ), (array) ( $extra['plugins'] ?? [] ), true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	public static function maybe_migrate() {
		if ( get_option( 'reallycache_version' ) === REALLYCACHE_VERSION ) {
			return;
		}
		update_option( 'reallycache_version', REALLYCACHE_VERSION, false );

		@copy( REALLYCACHE_DIR . '/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php' );

		$engine = Engine::instance();
		if ( $engine ) {
			$engine->purge_all();
		}
	}
}
