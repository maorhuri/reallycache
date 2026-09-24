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
delete_option( 'reallycache_warm_queue' );
delete_option( 'reallycache_warm_state' );
