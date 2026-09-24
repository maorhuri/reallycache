<?php
namespace ReallyCache\Compat;

use ReallyCache\Config;
use ReallyCache\Engine;

/**
 * WooCommerce safety, active only when WooCommerce is.
 *
 * The request gate already keeps visitors with a cart out of the cache
 * (cart/session cookies in bypass_cookies) and the response gate skips
 * cart/checkout/account pages. What's left is keeping product pages fresh:
 * stock and price changes happen through order processing, not post edits,
 * so they need their own purge hooks — a gap in most file-cache plugins.
 */
class WooCommerce {

	private $engine;

	public function __construct( Engine $engine ) {
		$this->engine = $engine;

		add_action( 'admin_init', [ $this, 'sync_excluded_paths' ] );

		add_action( 'woocommerce_loaded', function () {
			add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_change' ] );
			add_action( 'woocommerce_variation_set_stock', [ $this, 'on_stock_change' ] );
			add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_stock_status_change' ], 10, 3 );
			add_action( 'woocommerce_update_product', [ $this, 'on_product_update' ] );
		} );
	}

	/**
	 * Mirrors the cart/checkout/account page URLs — whatever slugs and
	 * language the store configured — into the config file, so the drop-in
	 * can exclude them before WordPress loads. Cheap: only writes on change.
	 */
	public function sync_excluded_paths() {
		// admin_init also fires for anonymous admin-ajax traffic, and with
		// multilingual plugins the permalinks differ per request language —
		// public traffic must never trigger config writes or path flips.
		if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$paths = [];
		foreach ( [ 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ] as $option ) {
			$page_id = (int) get_option( $option );
			if ( ! $page_id ) {
				continue;
			}
			$link = get_permalink( $page_id );
			$path = $link ? (string) parse_url( $link, PHP_URL_PATH ) : '';
			if ( '' !== $path && '/' !== $path ) {
				$paths[] = rawurldecode( $path );
			}
		}
		// Accumulate rather than replace: with WPML/Polylang each language
		// has its own slugs, and all of them must stay excluded at once.
		$config = Config::load();
		$paths  = array_slice( array_values( array_unique( array_merge( (array) $config['woo_excluded_paths'], $paths ) ) ), 0, 20 );

		if ( $paths !== array_values( (array) $config['woo_excluded_paths'] ) ) {
			Config::save( [ 'woo_excluded_paths' => $paths ] );
		}
	}

	/** @param \WC_Product $product */
	public function on_stock_change( $product ) {
		$this->purge_product( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
	}

	public function on_stock_status_change( $product_id, $status, $product ) {
		$this->purge_product( (int) $product_id );
	}

	public function on_product_update( $product_id ) {
		$this->purge_product( (int) $product_id );
	}

	private function purge_product( $product_id ) {
		if ( ! $product_id ) {
			return;
		}

		$urls   = [];
		$urls[] = get_permalink( $product_id );

		$shop_page = (int) get_option( 'woocommerce_shop_page_id' );
		if ( $shop_page ) {
			$urls[] = get_permalink( $shop_page );
		}

		$this->engine->purge_urls( $urls, true );
	}
}
