<?php
/**
 * ReallyCache drop-in. WordPress loads this file from wp-settings.php before
 * plugins, themes or the database are available, so everything here must be
 * self-contained: no WP functions, no autoloader, minimal file reads.
 *
 * __DIR__ is wp-content — the plugin location is read from the config file
 * written on activation, never hardcoded, so any plugin folder name works.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}
if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}

$reallycache_config_file = __DIR__ . '/reallycache/config.php';
if ( ! is_readable( $reallycache_config_file ) ) {
	return;
}

// A torn or corrupted config file must degrade to "no caching",
// never take the site down this early in the load.
try {
	$reallycache_config = include $reallycache_config_file;
} catch ( \Throwable $reallycache_e ) {
	return;
}
if ( ! is_array( $reallycache_config ) || empty( $reallycache_config['cache_enabled'] ) ) {
	return;
}

$reallycache_plugin_dir = $reallycache_config['plugin_dir'] ?? '';
if ( ! $reallycache_plugin_dir || ! is_file( $reallycache_plugin_dir . '/includes/Engine.php' ) ) {
	return;
}

require_once $reallycache_plugin_dir . '/includes/CacheKey.php';
require_once $reallycache_plugin_dir . '/includes/Gate.php';
require_once $reallycache_plugin_dir . '/includes/Drivers/DriverInterface.php';
require_once $reallycache_plugin_dir . '/includes/Drivers/FileDriver.php';
require_once $reallycache_plugin_dir . '/includes/Engine.php';

ReallyCache\Engine::boot( $reallycache_config, __DIR__ )->maybe_serve();

/**
 * Called by WordPress core right after the drop-in is loaded. This is where
 * output buffering starts on a cache miss, so the rendered page can be stored.
 */
function wp_cache_postload() {
	$engine = ReallyCache\Engine::instance();
	if ( $engine ) {
		$engine->start_buffer();
	}
}
