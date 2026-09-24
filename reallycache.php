<?php
/*
	Plugin Name: ReallyCache
	Description: Really fast, really simple page caching. Server-aware: file cache everywhere, LiteSpeed and Nginx FastCGI cache where available.
	Version: 0.7.2
	Author: MVN
	Text Domain: reallycache
	Domain Path: /languages/
	License: GPLv2 or later
	License URI: http://www.gnu.org/licenses/gpl-2.0.html

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REALLYCACHE_VERSION', '0.7.2' );
define( 'REALLYCACHE_DIR', __DIR__ );
define( 'REALLYCACHE_FILE', __FILE__ );

spl_autoload_register( function ( $class ) {
	if ( 0 !== strpos( $class, 'ReallyCache\\' ) ) {
		return;
	}
	$path = REALLYCACHE_DIR . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( 'ReallyCache\\' ) ) ) . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, [ 'ReallyCache\\Setup', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'ReallyCache\\Setup', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
	// The drop-in may have booted the engine already on a cache miss; reuse it
	// so the plugin and the drop-in always share one config and one driver.
	$engine = ReallyCache\Engine::instance();
	if ( ! $engine ) {
		$engine = ReallyCache\Engine::boot( ReallyCache\Config::load(), WP_CONTENT_DIR );
	}

	new ReallyCache\PurgeManager( $engine );
	new ReallyCache\Compat\WooCommerce( $engine );
	new ReallyCache\RestApi( $engine );

	// Always constructed: the admin-bar Clear Cache node renders on the
	// frontend too, and is_admin_bar_showing() is unreliable this early.
	new ReallyCache\Admin\AdminPage( $engine );

	add_action( 'reallycache_gc', function () use ( $engine ) {
		$engine->driver()->gc( $engine->config()['ttl'] );
	} );
	add_action( ReallyCache\Warmer::TICK_HOOK, [ 'ReallyCache\\Warmer', 'tick' ] );
	add_action( ReallyCache\Warmer::DAILY_HOOK, [ 'ReallyCache\\Warmer', 'run_daily' ] );
	add_action( ReallyCache\Media\Webp::TICK_HOOK, [ 'ReallyCache\\Media\\Webp', 'tick' ] );
	ReallyCache\Media\Webp::register();
	ReallyCache\Media\LcpBoost::register();
	ReallyCache\Media\Fonts::register();
	ReallyCache\Media\DataUri::register();
	ReallyCache\Compat\CartProbe::register();

	add_action( 'init', function () {
		load_plugin_textdomain( 'reallycache', false, dirname( plugin_basename( REALLYCACHE_FILE ) ) . '/languages' );
	} );

	// Keep the cacheable-host allowlist in sync, so the pre-WordPress gate
	// can refuse arbitrary Host headers. Besides home/site URL, the host an
	// authenticated admin actually arrives on is trusted too — behind
	// reverse proxies PHP can legitimately see a different HTTP_HOST than
	// the public address, and rejecting it would silently disable caching.
	add_action( 'admin_init', function () use ( $engine ) {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = (array) ( $engine->config()['allowed_hosts'] ?? [] );
		$hosts   = array_slice( array_values( array_unique( array_filter( array_merge( $current, [
			strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) parse_url( site_url(), PHP_URL_HOST ) ),
			ReallyCache\CacheKey::sanitize_host( $_SERVER['HTTP_HOST'] ?? '' ),
		] ) ) ) ), 0, 10 );

		if ( $hosts && $hosts !== array_values( $current ) ) {
			ReallyCache\Config::save( [ 'allowed_hosts' => $hosts ] );
		}

		// Self-heal the nightly-refresh schedule if cron lost it.
		if ( ! empty( $engine->config()['daily_warm'] ) && ! wp_next_scheduled( ReallyCache\Warmer::DAILY_HOOK ) ) {
			ReallyCache\Warmer::schedule_daily( $engine->config() );
		}
	} );
} );
