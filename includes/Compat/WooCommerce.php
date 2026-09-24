<?php
namespace ReallyCache\Compat;

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

		add_action( 'woocommerce_loaded', function () {
			add_action( 'woocommerce_product_set_stock', [ $this, 'on_stock_change' ] );
			add_action( 'woocommerce_variation_set_stock', [ $this, 'on_stock_change' ] );
			add_action( 'woocommerce_product_set_stock_status', [ $this, 'on_stock_status_change' ], 10, 3 );
			add_action( 'woocommerce_update_product', [ $this, 'on_product_update' ] );
		} );
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

		$this->engine->purge_urls( $urls );
	}
}
