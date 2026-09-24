<?php
namespace ReallyCache\Admin;

use ReallyCache\Engine;

/**
 * The admin screen shell: menu entry, asset loading and the root element the
 * JS app renders into. All data flows through the REST API; the only
 * non-REST action left is the admin-bar Clear Cache link.
 */
class AdminPage {

	private $engine;

	public function __construct( Engine $engine ) {
		$this->engine = $engine;

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 90 );
		add_action( 'admin_post_reallycache_clear', [ $this, 'handle_clear' ] );
	}

	public function register_menu() {
		$hook = add_menu_page(
			'ReallyCache',
			'ReallyCache',
			'manage_options',
			'reallycache',
			[ $this, 'render' ],
			self::menu_icon(),
			81
		);

		add_action( 'load-' . $hook, function () {
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		} );
	}

	public function enqueue() {
		$base = plugin_dir_url( REALLYCACHE_FILE ) . 'assets/admin/';
		$dir  = REALLYCACHE_DIR . '/assets/admin/';

		wp_enqueue_style( 'reallycache-app', $base . 'app.css', [], (string) filemtime( $dir . 'app.css' ) );
		wp_enqueue_script( 'reallycache-app', $base . 'app.js', [], (string) filemtime( $dir . 'app.js' ), true );

		wp_localize_script( 'reallycache-app', 'ReallyCacheData', [
			'restUrl' => esc_url_raw( rest_url( 'reallycache/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'locale'  => str_replace( '_', '-', get_user_locale() ),
			'brand'   => [
				'hosting'  => apply_filters( 'reallycache_hosting_url', 'https://mvn.co.il/?utm_source=reallycache&utm_medium=plugin' ),
				'whatsapp' => apply_filters( 'reallycache_whatsapp_url', 'https://wa.me/972000000000' ),
			],
			'i18n'    => self::strings(),
		] );
	}

	public function render() {
		echo '<div id="reallycache-app"><noscript>' . esc_html__( 'ReallyCache needs JavaScript enabled.', 'reallycache' ) . '</noscript></div>';
	}

	public function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'reallycache-clear',
			'title' => __( 'Clear Cache', 'reallycache' ),
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_clear' ), 'reallycache_clear' ),
		] );
	}

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'reallycache' ) );
		}
		check_admin_referer( 'reallycache_clear' );

		$this->engine->purge_all();

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=reallycache' ) );
		exit;
	}

	/** Every string the JS app shows. Keys are stable; values are translatable. */
	private static function strings() {
		return [
			'status_active'   => __( 'Cache active', 'reallycache' ),
			'status_paused'   => __( 'Dev mode', 'reallycache' ),
			'status_disabled' => __( 'Cache off', 'reallycache' ),

			'btn_clear'       => __( 'Clear Cache', 'reallycache' ),
			'toast_cleared'   => __( 'Cache cleared', 'reallycache' ),
			'toast_saved'     => __( 'Saved — cache cleared', 'reallycache' ),
			'toast_error'     => __( 'Something went wrong. Try again', 'reallycache' ),
			'err_load'        => __( 'Could not load ReallyCache data. Refresh the page to try again.', 'reallycache' ),

			'tile_status'     => __( 'Page cache', 'reallycache' ),
			'tile_server'     => __( 'Web server', 'reallycache' ),
			'tile_pages'      => __( 'Cached pages', 'reallycache' ),
			'tile_size'       => __( 'Cache size', 'reallycache' ),
			'tile_ttl_sub'    => __( 'Lifetime: %s', 'reallycache' ),
			'value_on'        => __( 'Active', 'reallycache' ),
			'value_off'       => __( 'Off', 'reallycache' ),
			'value_paused'    => __( 'Paused', 'reallycache' ),

			'dropin_warn_title' => __( 'The cache drop-in is missing.', 'reallycache' ),
			'dropin_warn_body'  => __( 'Deactivate and reactivate ReallyCache to reinstall it — until then pages are not served from cache.', 'reallycache' ),

			'dev_title'       => __( 'Development mode', 'reallycache' ),
			'dev_desc'        => __( 'Pause caching while working on the site. It turns itself back on.', 'reallycache' ),
			'dev_active_desc' => __( 'Caching is paused — resumes in %s', 'reallycache' ),
			'dev_1h'          => __( 'Pause for 1 hour', 'reallycache' ),
			'dev_24h'         => __( 'Pause for 24 hours', 'reallycache' ),
			'dev_off'         => __( 'Resume caching now', 'reallycache' ),
			'toast_dev_on'    => __( 'Development mode is on', 'reallycache' ),
			'toast_dev_off'   => __( 'Development mode is off — caching resumed', 'reallycache' ),
			'time_h'          => __( '%s hours', 'reallycache' ),
			'time_m'          => __( '%s minutes', 'reallycache' ),

			'settings_title'  => __( 'Settings', 'reallycache' ),
			'settings_desc'   => __( 'Saving clears the cache', 'reallycache' ),
			'row_cache'       => __( 'Page caching', 'reallycache' ),
			'row_cache_desc'  => __( 'Serve pages as static HTML instead of rebuilding them on every visit', 'reallycache' ),
			'row_ttl'         => __( 'Cache lifetime', 'reallycache' ),
			'row_ttl_desc'    => __( 'How long a cached page stays fresh before it is rebuilt', 'reallycache' ),
			'row_mobile'      => __( 'Separate mobile cache', 'reallycache' ),
			'row_mobile_desc' => __( 'Only needed when the site serves different HTML to mobile — not just responsive CSS', 'reallycache' ),
			'row_home'        => __( 'Refresh home page on updates', 'reallycache' ),
			'row_home_desc'   => __( 'Also purge the home page whenever a post or page changes', 'reallycache' ),
			'row_uris'        => __( 'Never cache these URLs', 'reallycache' ),
			'row_uris_desc'   => __( 'One path per line, * matches anything. Cart, checkout and account pages are protected automatically.', 'reallycache' ),
			'row_uas'         => __( 'Never cache for these user agents', 'reallycache' ),
			'row_uas_desc'    => __( 'One per line. Requests whose user agent contains the text bypass the cache.', 'reallycache' ),

			'ttl_1h'          => __( '1 hour', 'reallycache' ),
			'ttl_6h'          => __( '6 hours', 'reallycache' ),
			'ttl_12h'         => __( '12 hours', 'reallycache' ),
			'ttl_24h'         => __( '24 hours', 'reallycache' ),
			'ttl_7d'          => __( '7 days', 'reallycache' ),

			'save_pending'    => __( 'You have unsaved changes', 'reallycache' ),
			'btn_save'        => __( 'Save changes', 'reallycache' ),
			'btn_discard'     => __( 'Discard', 'reallycache' ),

			'promo_title'     => __( 'Want the site even faster?', 'reallycache' ),
			'promo_desc'      => __( 'The MVN speed team is happy to help — free advice on WhatsApp. And when you want the fastest home for your site, MVN fast hosting is built for it.', 'reallycache' ),
			'promo_whatsapp'  => __( 'Chat on WhatsApp', 'reallycache' ),
			'promo_hosting'   => __( 'MVN fast hosting', 'reallycache' ),
			'promo_dismiss'   => __( 'Hide this', 'reallycache' ),

			'footer_by'       => __( 'Crafted by MVN', 'reallycache' ),
		];
	}

	/** Brand-pink bolt, encoded for the admin menu. */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" d="M13 2 4.5 13.5h6L10.5 22 19 10.5h-6L13 2z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
