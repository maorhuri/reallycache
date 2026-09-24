<?php
namespace ReallyCache\Admin;

use ReallyCache\Engine;
use ReallyCache\Redis;
use ReallyCache\ServerDetector;
use ReallyCache\Warmer;

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
		add_action( 'admin_post_reallycache_purge_page', [ $this, 'handle_purge_page' ] );
		add_action( 'admin_post_reallycache_warm', [ $this, 'handle_warm' ] );
		add_action( 'admin_post_reallycache_redis_flush', [ $this, 'handle_redis_flush' ] );
		add_action( 'admin_post_reallycache_clear_all', [ $this, 'handle_clear_all' ] );
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
				'hosting'  => esc_url_raw( apply_filters( 'reallycache_hosting_url', 'https://mvn.co.il/?utm_source=reallycache&utm_medium=plugin' ) ),
				'whatsapp' => esc_url_raw( apply_filters( 'reallycache_whatsapp_url', 'https://wa.me/97237722100' ) ),
			],
			'server'  => ServerDetector::server(),
			'snippets' => [
				'browser_cache' => (string) file_get_contents( REALLYCACHE_DIR . '/docs/nginx-browser-cache.conf' ),
				'gzip'          => "# ReallyCache — compression (inside http { } or server { })\ngzip on;\ngzip_comp_level 5;\ngzip_min_length 256;\ngzip_types text/css application/javascript application/json image/svg+xml application/xml text/plain font/ttf;\ngzip_vary on;",
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
			'id'    => 'reallycache',
			'title' => 'ReallyCache',
			'href'  => admin_url( 'admin.php?page=reallycache' ),
		] );
		// On the frontend: purge exactly the page being viewed and re-warm
		// it on the spot — perfect for testing one page without a full run.
		if ( ! is_admin() && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$current = home_url( strtok( wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) );
			$bar->add_node( [
				'id'     => 'reallycache-page',
				'parent' => 'reallycache',
				'title'  => __( 'Purge & warm this page', 'reallycache' ),
				'href'   => wp_nonce_url(
					admin_url( 'admin-post.php?action=reallycache_purge_page&target=' . rawurlencode( $current ) ),
					'reallycache_purge_page'
				),
			] );
		}

		$bar->add_node( [
			'id'     => 'reallycache-clear',
			'parent' => 'reallycache',
			'title'  => __( 'Clear page cache', 'reallycache' ),
			'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_clear' ), 'reallycache_clear' ),
		] );
		$bar->add_node( [
			'id'     => 'reallycache-warm',
			'parent' => 'reallycache',
			'title'  => __( 'Warm cache', 'reallycache' ),
			'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_warm' ), 'reallycache_warm' ),
		] );
		if ( Redis\Manager::active() ) {
			$bar->add_node( [
				'id'     => 'reallycache-redis',
				'parent' => 'reallycache',
				'title'  => __( 'Flush Redis cache', 'reallycache' ),
				'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_redis_flush' ), 'reallycache_redis_flush' ),
			] );
		}
		$bar->add_node( [
			'id'     => 'reallycache-all',
			'parent' => 'reallycache',
			'title'  => __( 'Clear everything', 'reallycache' ),
			'href'   => wp_nonce_url( admin_url( 'admin-post.php?action=reallycache_clear_all' ), 'reallycache_clear_all' ),
		] );
	}

	public function handle_clear() {
		$this->authorize_action( 'reallycache_clear' );
		$this->engine->purge_all();
		$this->back();
	}

	public function handle_purge_page() {
		$this->authorize_action( 'reallycache_purge_page' );

		$url  = esc_url_raw( wp_unslash( $_GET['target'] ?? '' ) );
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		if ( $url && $host === strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$this->engine->purge_url( $url );
			// Synchronous warm: by the time the redirect lands, the page
			// is already hot again for the next visitor.
			Warmer::warm_url( $url, ! empty( $this->engine->config()['separate_mobile'] ) );
		}

		wp_safe_redirect( $url ?: ( wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	public function handle_warm() {
		$this->authorize_action( 'reallycache_warm' );
		Warmer::seed_site();
		$this->back();
	}

	public function handle_redis_flush() {
		$this->authorize_action( 'reallycache_redis_flush' );
		if ( Redis\Manager::active() ) {
			wp_cache_flush();
			delete_transient( 'reallycache_redis_insights' );
		}
		$this->back();
	}

	public function handle_clear_all() {
		$this->authorize_action( 'reallycache_clear_all' );
		$this->engine->purge_all();
		if ( Redis\Manager::active() ) {
			wp_cache_flush();
			delete_transient( 'reallycache_redis_insights' );
		}
		$this->back();
	}

	private function authorize_action( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'reallycache' ) );
		}
		check_admin_referer( $nonce_action );
	}

	private function back() {
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
			'btn_warm'        => __( 'Warm Cache', 'reallycache' ),
			'warm_running'    => __( 'Warming… %s', 'reallycache' ),
			'warm_now'        => __( 'Warming:', 'reallycache' ),
			'btn_stop'        => __( 'Stop', 'reallycache' ),
			'btn_stop_warm'   => __( 'Stop the warm-up', 'reallycache' ),
			'toast_warm_stopped' => __( 'Warm-up stopped', 'reallycache' ),
			'toast_warm'      => __( 'Warm-up started — pages are being cached in the background', 'reallycache' ),
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
			'dropin_foreign_title' => __( 'Another caching plugin controls this site.', 'reallycache' ),
			'dropin_foreign_body'  => __( 'advanced-cache.php belongs to a different plugin. Remove or deactivate the other cache plugin, then deactivate and reactivate ReallyCache.', 'reallycache' ),
			'health_load_title'    => __( 'The cache layer did not load on this request.', 'reallycache' ),
			'health_load_body'     => __( 'Usually a migration or PHP switch dropped the drop-in or WP_CACHE. Auto-repair just reinstalled both — refresh this page. If this message stays, wp-config.php is not writable.', 'reallycache' ),
			'health_write_title'   => __( 'The cache folder is not writable!', 'reallycache' ),
			'health_write_body'    => __( 'Nothing can be saved to %s — usually leftover permissions from a previous cache plugin. Fix the folder\'s owner/permissions (or delete wp-content/cache and reload).', 'reallycache' ),

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
			'row_smart'       => __( 'Smart cache', 'reallycache' ),
			'row_smart_desc'  => __( 'When a page or product changes, only its cache (plus home and archives) is purged — and re-warmed automatically in the background', 'reallycache' ),
			'row_warm_max'      => __( 'Warm-up scope', 'reallycache' ),
			'row_warm_max_desc' => __( 'How many pages a full warm-up covers, processed gradually. Content updates always re-warm their own pages regardless.', 'reallycache' ),
			'warm_max_pages'    => __( '%s pages', 'reallycache' ),
			'warm_max_all'      => __( 'The whole site', 'reallycache' ),
			'row_daily'         => __( 'Nightly full refresh', 'reallycache' ),
			'row_daily_desc'    => __( 'Once a day the entire cache is purged and rebuilt in the background, so every page starts the day freshly rendered with a full TTL', 'reallycache' ),
			'row_daily_hour'    => __( 'Refresh hour', 'reallycache' ),
			'row_daily_hour_desc' => __( 'Site timezone. Pick a quiet hour — the rebuild runs gradually and gently', 'reallycache' ),
			'woo_cart_ok'      => __( '✓ Theme verified: identical HTML with a cart — visitors with items in their cart get cached pages too', 'reallycache' ),
			'woo_cart_safe'    => __( 'This theme renders cart data server-side, so visitors with items in their cart get live pages (safe mode). Re-checked weekly.', 'reallycache' ),
			'woo_cart_variant' => __( '✓ This theme renders cart data server-side — so each cart state gets its own short-lived private cache entry: correct cart, cached speed', 'reallycache' ),
			'woo_fragments'    => __( '✓ Cart fragments cached — the slow mini-cart refresh that fires on every page with a cart now returns in milliseconds', 'reallycache' ),
			'woo_cart_probing' => __( 'Checking whether the theme supports cached pages for visitors with a cart…', 'reallycache' ),
			'woo_cart_diff'    => __( 'Show what differs with a cart (diagnostic)', 'reallycache' ),
			'woo_cart_untested' => __( 'Could not build a test cart to verify the theme (no purchasable product found) — running in the safe private-copy mode meanwhile; re-trying daily', 'reallycache' ),
			'btn_cart_probe'   => __( 'Check now', 'reallycache' ),
			'row_woo'         => __( 'WooCommerce protection', 'reallycache' ),
			'row_woo_desc'    => __( 'Cart, checkout and account pages are detected from your WooCommerce settings and never cached; visitors with a cart always bypass the cache', 'reallycache' ),
			'row_uris'        => __( 'Never cache these URLs', 'reallycache' ),
			'row_uris_desc'   => __( 'One path per line, * matches anything. Cart, checkout and account pages are protected automatically.', 'reallycache' ),
			'row_uas'         => __( 'Never cache for these user agents', 'reallycache' ),
			'row_uas_desc'    => __( 'One per line. Requests whose user agent contains the text bypass the cache.', 'reallycache' ),

			'ttl_1h'          => __( '1 hour', 'reallycache' ),
			'ttl_6h'          => __( '6 hours', 'reallycache' ),
			'ttl_12h'         => __( '12 hours', 'reallycache' ),
			'ttl_24h'         => __( '24 hours', 'reallycache' ),
			'ttl_7d'          => __( '7 days', 'reallycache' ),
			'ttl_forever'     => __( 'Permanent — until cleared', 'reallycache' ),

			'media_title'      => __( 'Images & WebP', 'reallycache' ),
			'media_desc'       => __( 'New uploads convert automatically', 'reallycache' ),
			'row_webp'         => __( 'WebP conversion', 'reallycache' ),
			'row_webp_desc'    => __( 'Serve images as WebP — converted locally on this server, no external service', 'reallycache' ),
			'webp_unsupported' => __( 'This server has no WebP support (Imagick or GD required) — ask your host to enable it.', 'reallycache' ),
			'webp_converted'   => __( 'Images converted', 'reallycache' ),
			'webp_saved'       => __( 'Space saved', 'reallycache' ),
			'webp_pending'     => __( 'Waiting', 'reallycache' ),
			'btn_webp_scan'    => __( 'Convert existing images', 'reallycache' ),
			'btn_webp_rescan'  => __( 'Re-convert all images', 'reallycache' ),
			'webp_estimate'    => __( 'Converting the rest will use about %s of disk (originals are kept)', 'reallycache' ),
			'webp_disk_free'   => __( 'free: %s', 'reallycache' ),
			'webp_disk_low'    => __( 'only %s free — not enough!', 'reallycache' ),
			'webp_stale_note'  => __( 'New WebP images were created, but cached pages still serve the old ones — clear the cache to update them.', 'reallycache' ),
			'webp_now'         => __( 'Converting:', 'reallycache' ),
			'toast_webp'       => __( 'Image conversion started in the background', 'reallycache' ),

			'row_bcache'       => __( 'Browser cache', 'reallycache' ),
			'row_bcache_desc'  => __( 'Static files get year-long expiry headers so repeat visits load instantly. Automatic on Apache; an Nginx snippet ships in the plugin\'s docs folder.', 'reallycache' ),
			'row_redis'        => __( 'Redis object cache', 'reallycache' ),
			'row_redis_desc'   => __( 'Redis detected on this server — cache database queries in memory, no extra plugin needed', 'reallycache' ),
			'redis_foreign'    => __( 'Another plugin already manages the object cache on this site', 'reallycache' ),
			'redis_socket_note' => __( 'Running in socket mode (the php-redis extension is not installed). Everything works; installing php-redis will make it even faster.', 'reallycache' ),
			'redis_desc'       => __( 'Live view of what WordPress keeps in memory', 'reallycache' ),
			'redis_hit_rate'   => __( 'Hit rate', 'reallycache' ),
			'redis_hits_label' => __( 'Queries from RAM', 'reallycache' ),
			'redis_keys_label' => __( 'Keys stored', 'reallycache' ),
			'redis_memory_label' => __( 'Redis memory', 'reallycache' ),
			'redis_latency_label' => __( 'Avg op latency', 'reallycache' ),
			'redis_groups_title' => __( 'What lives in Redis right now:', 'reallycache' ),
			'row_redis_ttl'    => __( 'Default expiry', 'reallycache' ),
			'row_redis_ttl_desc' => __( 'Entries without an expiry of their own are capped at this age', 'reallycache' ),
			'row_redis_groups' => __( 'Excluded groups', 'reallycache' ),
			'row_redis_groups_desc' => __( 'One cache group per line that should stay out of Redis', 'reallycache' ),
			'ttl_unlimited'    => __( 'Unlimited', 'reallycache' ),
			'btn_redis_flush'  => __( 'Flush Redis cache', 'reallycache' ),
			'toast_redis_flushed' => __( 'Redis cache flushed', 'reallycache' ),

			'row_webp_upload'      => __( 'Convert on upload', 'reallycache' ),
			'row_webp_upload_desc' => __( 'Every image you upload is converted to WebP automatically in the background', 'reallycache' ),
			'row_lcp'          => __( 'LCP Boost', 'reallycache' ),
			'row_lcp_desc'     => __( 'The hero image gets top fetch priority and a preload hint — a direct Largest Contentful Paint win', 'reallycache' ),
			'row_fonts'        => __( 'Font Display Swap', 'reallycache' ),
			'row_fonts_desc'   => __( 'Text shows immediately in a fallback font while webfonts load. Covers inline fonts, Google Fonts and Elementor custom fonts.', 'reallycache' ),
			'row_datauri'      => __( 'Extract embedded images', 'reallycache' ),
			'row_datauri_desc' => __( 'Huge base64 images inlined in the HTML (e.g. Figma-exported SVGs with photos inside) are extracted to static files, shrinking the page by megabytes and letting WebP compress them', 'reallycache' ),

			'btn_server_recs'  => __( 'Server recommendations', 'reallycache' ),
			'tab_cache'        => __( 'Cache settings', 'reallycache' ),
			'recs_title'       => __( 'Nginx recommendations', 'reallycache' ),
			'recs_intro'       => __( 'Paste these blocks into the site\'s Nginx configuration and reload Nginx. They fix the PageSpeed "efficient cache lifetimes" audit and compression.', 'reallycache' ),
			'recs_bcache'      => __( 'Browser cache headers for static files', 'reallycache' ),
			'recs_gzip'        => __( 'Compression', 'reallycache' ),
			'btn_copy'         => __( 'Copy', 'reallycache' ),
			'toast_copied'     => __( 'Copied to clipboard', 'reallycache' ),

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
