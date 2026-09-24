<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Config.php';

// Cache files.
$root = WP_CONTENT_DIR . '/cache/reallycache';
if ( is_dir( $root ) ) {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $root );
}

// Config file and directory.
ReallyCache\Config::delete();

// Drop-in, only if it is ours.
$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if ( is_file( $dropin ) && false !== strpos( (string) file_get_contents( $dropin ), 'ReallyCache' ) ) {
	unlink( $dropin );
}

wp_clear_scheduled_hook( 'reallycache_gc' );
wp_clear_scheduled_hook( 'reallycache_warm_tick' );
wp_clear_scheduled_hook( 'reallycache_webp_tick' );
delete_option( 'reallycache_warm_queue' );
delete_option( 'reallycache_warm_state' );
delete_option( 'reallycache_webp_queue' );
delete_option( 'reallycache_webp_state' );
delete_option( 'reallycache_webp_totals' );
delete_option( 'reallycache_webp_mode' );
delete_option( 'reallycache_webp_dirty' );
delete_option( 'reallycache_warm_lock' );
delete_option( 'reallycache_webp_lock' );
delete_option( 'reallycache_worker_token' );
delete_option( 'reallycache_datauri_pending' );
wp_clear_scheduled_hook( 'reallycache_datauri_convert' );

// Cart-fragment cache files.
$rc_frag = WP_CONTENT_DIR . '/cache/reallycache-fragments';
if ( is_dir( $rc_frag ) ) {
	foreach ( glob( $rc_frag . '/*' ) ?: [] as $rc_file ) {
		@unlink( $rc_file );
	}
	@rmdir( $rc_frag );
}

// Extracted data-URI media files.
$rc_media = wp_get_upload_dir()['basedir'] . '/reallycache-media';
if ( is_dir( $rc_media ) ) {
	foreach ( glob( $rc_media . '/*' ) ?: [] as $rc_file ) {
		@unlink( $rc_file );
	}
	@rmdir( $rc_media );
}
delete_transient( 'reallycache_webp_estimate' );
delete_transient( 'reallycache_redis_insights' );
delete_metadata( 'post', 0, '_reallycache_webp', '', true );

// Our Redis object-cache drop-in, if it is ours.
$object_cache = WP_CONTENT_DIR . '/object-cache.php';
if ( is_file( $object_cache ) && false !== strpos( (string) file_get_contents( $object_cache ), 'ReallyCache' ) ) {
	unlink( $object_cache );
}

// Strip the browser-cache block from .htaccess without wp-admin helpers.
$htaccess = ABSPATH . '.htaccess';
if ( is_file( $htaccess ) && is_writable( $htaccess ) ) {
	$contents = (string) file_get_contents( $htaccess );
	$stripped = preg_replace( '/# BEGIN ReallyCache Browser Cache.*?# END ReallyCache Browser Cache\n?/s', '', $contents );
	if ( null !== $stripped && $stripped !== $contents ) {
		file_put_contents( $htaccess, $stripped );
	}
}
