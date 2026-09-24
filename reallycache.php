<?php
/*
	Plugin Name: ReallyCache
	Description: Really fast, really simple page caching. Server-aware: file cache everywhere, LiteSpeed and Nginx FastCGI cache where available.
	Version: 0.3.0
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

define( 'REALLYCACHE_VERSION', '0.3.0' );
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

	add_action( 'init', function () {
		load_plugin_textdomain( 'reallycache', false, dirname( plugin_basename( REALLYCACHE_FILE ) ) . '/languages' );
	} );
} );
