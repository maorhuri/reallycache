<?php
namespace ReallyCache\Media;

use ReallyCache\Config;
use ReallyCache\Warmer;

/**
 * Local WebP conversion — no external service, images never leave the server.
 *
 * Zero-touch: new uploads are queued and converted in the background, the
 * existing library converts through the same throttled worker pattern as the
 * cache warmer, and cached HTML is rewritten to serve the .webp twin whenever
 * one exists next to the original.
 */
class Webp {

	const QUEUE_OPTION  = 'reallycache_webp_queue';
	const STATE_OPTION  = 'reallycache_webp_state';
	const TOTALS_OPTION = 'reallycache_webp_totals';
	const MODE_OPTION   = 'reallycache_webp_mode';
	const DIRTY_OPTION  = 'reallycache_webp_dirty';
	const TICK_HOOK     = 'reallycache_webp_tick';
	const LOCK          = 'reallycache_webp_lock';
	const META_KEY      = '_reallycache_webp';

	public static function register() {
		// New uploads: queue instead of converting inline, so uploads stay fast.
		add_filter( 'wp_generate_attachment_metadata', function ( $metadata, $attachment_id ) {
			$config = Config::load();
			if ( ! empty( $config['webp_enabled'] ) && ! empty( $config['webp_on_upload'] ) && self::is_supported() ) {
				self::enqueue( [ (int) $attachment_id ] );
			}

			return $metadata;
		}, 20, 2 );

		add_action( 'delete_attachment', [ __CLASS__, 'delete_twins' ] );

		// A full purge means every cached page will be rebuilt with the
		// current .webp twins, so the "clear your cache" note can go away.
		add_action( 'reallycache_purged_all', function () {
			delete_option( self::DIRTY_OPTION );
		} );

		add_filter( 'reallycache_before_store', function ( $html ) {
			$config = Config::load();

			return empty( $config['webp_enabled'] ) ? $html : self::rewrite( $html );
		} );
	}

	public static function is_supported() {
		if ( class_exists( '\Imagick' ) && in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true ) ) {
			return true;
		}

		return function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromjpeg' );
	}

	/* ------------------------------------------------------------ queueing */

	/**
	 * Queue the media library's JPEG/PNGs. Normally only unconverted images;
	 * with $force everything is queued and existing twins are regenerated.
	 */
	public static function seed_library( $force = false ) {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => [ 'image/jpeg', 'image/png' ],
			'posts_per_page' => (int) apply_filters( 'reallycache_webp_max_scan', 5000 ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( ! $force ) {
			$args['meta_query'] = [ [ 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ] ];
		}

		update_option( self::MODE_OPTION, $force ? 'redo' : 'fresh', false );
		update_option( self::STATE_OPTION, [ 'done' => 0, 'total' => 0, 'current' => '', 'updated' => time() ], false );
		delete_transient( 'reallycache_webp_estimate' );
		self::enqueue( array_map( 'intval', get_posts( $args ) ) );
	}

	/**
	 * Disk needed for the twins of everything still unconverted. WebP at
	 * our quality typically lands at 60–80% of the original, so 75% of the
	 * pending originals' size is a safe planning figure.
	 *
	 * @return array{originals:int,estimate:int,free:int|null}
	 */
	public static function disk_estimate() {
		$bytes = get_transient( 'reallycache_webp_estimate' );

		if ( false === $bytes ) {
			$bytes = 0;
			$ids   = get_posts( [
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/jpeg', 'image/png' ],
				'posts_per_page' => 3000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [ [ 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ] ],
			] );

			foreach ( $ids as $id ) {
				$meta = wp_get_attachment_metadata( $id );
				if ( ! empty( $meta['filesize'] ) ) {
					$bytes += (int) $meta['filesize'];
				} else {
					$file = get_attached_file( $id );
					if ( $file && is_file( $file ) ) {
						$bytes += (int) filesize( $file );
					}
				}
				if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $size ) {
						$bytes += (int) ( $size['filesize'] ?? 0 );
					}
				}
			}

			set_transient( 'reallycache_webp_estimate', $bytes, 10 * MINUTE_IN_SECONDS );
		}

		$uploads = wp_upload_dir();
		$free    = ! empty( $uploads['basedir'] ) && function_exists( 'disk_free_space' ) ? @disk_free_space( $uploads['basedir'] ) : null;

		return [
			'originals' => (int) $bytes,
			'estimate'  => (int) round( $bytes * 0.75 ),
			'free'      => null === $free || false === $free ? null : (int) $free,
		];
	}

	public static function enqueue( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$queue = (array) get_option( self::QUEUE_OPTION, [] );
		$queue = array_values( array_unique( array_merge( $queue, $ids ) ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		$state          = self::state();
		$state['total'] = (int) $state['done'] + count( $queue );
		update_option( self::STATE_OPTION, $state, false );

		self::kick();
	}

	public static function kick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::TICK_HOOK );
		}

		wp_remote_post( rest_url( 'reallycache/v1/webp-worker' ), [
			'blocking'  => false,
			'timeout'   => 0.02,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			'body'      => [ 'key' => Warmer::token() ],
		] );
	}

	/** Atomic lock via add_option — get/set transients can race. */
	private static function acquire_lock() {
		if ( add_option( self::LOCK, (string) time(), '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK );
		if ( $held && ( time() - $held ) > 150 ) {
			update_option( self::LOCK, (string) time(), false );
			return true;
		}

		return false;
	}

	private static function locked() {
		$held = (int) get_option( self::LOCK );

		return $held && ( time() - $held ) <= 150;
	}

	public static function work() {
		if ( ! self::acquire_lock() ) {
			return;
		}

		$started = time();
		while ( ( time() - $started ) < 20 ) {
			if ( ! self::step( 2 ) ) {
				break;
			}
			usleep( 200000 );
		}

		delete_option( self::LOCK );

		if ( self::queued() > 0 ) {
			self::kick();
		}
	}

	/** Convert up to $count attachments. Returns false when the queue is empty. */
	public static function step( $count ) {
		$queue = (array) get_option( self::QUEUE_OPTION, [] );
		if ( empty( $queue ) ) {
			return false;
		}

		$batch = array_splice( $queue, 0, max( 1, $count ) );
		update_option( self::QUEUE_OPTION, $queue, false );

		$overwrite = 'redo' === get_option( self::MODE_OPTION );

		foreach ( $batch as $id ) {
			$file             = get_attached_file( (int) $id );
			$state            = self::state();
			$state['current'] = $file ? wp_basename( $file ) : '#' . $id;
			$state['updated'] = time();
			update_option( self::STATE_OPTION, $state, false );

			self::convert_attachment( (int) $id, $overwrite );

			$state            = self::state();
			$state['done']   += 1;
			$state['updated'] = time();
			update_option( self::STATE_OPTION, $state, false );
		}

		return true;
	}

	public static function maybe_pump() {
		if ( self::queued() < 1 || self::locked() ) {
			return;
		}

		$stale = time() - (int) self::state()['updated'];
		if ( $stale >= 15 ) {
			self::step( 1 );
		} elseif ( $stale >= 6 ) {
			self::kick();
		}
	}

	public static function tick() {
		if ( self::queued() > 0 ) {
			if ( ! self::locked() ) {
				self::kick();
			}
			wp_schedule_single_event( time() + 60, self::TICK_HOOK );
		}
	}

	/* ---------------------------------------------------------- conversion */

	/**
	 * Convert an attachment's original file and every registered size.
	 * The originals are never modified or removed — each conversion only
	 * adds a .webp twin next to the source file.
	 */
	public static function convert_attachment( $attachment_id, $overwrite = false ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			update_post_meta( $attachment_id, self::META_KEY, -1 );
			return 0;
		}

		// A .webp twin is served without WordPress in the loop, so images
		// belonging to non-public content (draft/private products, gated
		// downloads) must not get a publicly fetchable twin.
		$parent_id = (int) get_post_field( 'post_parent', $attachment_id );
		$public    = ! $parent_id || 'publish' === get_post_status( $parent_id );
		if ( ! apply_filters( 'reallycache_webp_allow_attachment', $public, $attachment_id ) ) {
			update_post_meta( $attachment_id, self::META_KEY, -1 );
			return 0;
		}

		$files = [ $file ];
		$meta  = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}

		$saved = 0;
		foreach ( array_unique( $files ) as $path ) {
			$saved += self::convert_file( $path, $overwrite );
		}

		$had_meta = '' !== (string) get_post_meta( $attachment_id, self::META_KEY, true );
		$previous = max( 0, (int) get_post_meta( $attachment_id, self::META_KEY, true ) );
		update_post_meta( $attachment_id, self::META_KEY, $saved );

		$totals           = self::totals();
		$totals['count'] += $had_meta ? 0 : 1;
		$totals['saved'] += $overwrite ? ( $saved - $previous ) : $saved;
		update_option( self::TOTALS_OPTION, $totals, false );

		if ( $saved > 0 ) {
			// Cached pages still reference the pre-conversion HTML — the UI
			// nudges the admin to clear the cache so visitors get the twins.
			update_option( self::DIRTY_OPTION, 1, false );
		}

		return $saved;
	}

	/** @return int Bytes saved (0 when skipped or WebP came out larger). */
	public static function convert_file( $path, $overwrite = false ) {
		if ( ! is_file( $path ) || ! preg_match( '/\.(jpe?g|png)$/i', $path ) ) {
			return 0;
		}

		// Trust the file content, not the extension: a non-image stored as
		// .jpg must never reach Imagick's format auto-detection (SVG/MVG/PS
		// coders are an attack surface — the ImageTragick class).
		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $path ) : ( getimagesize( $path )['mime'] ?? false );
		if ( 'image/jpeg' !== $mime && 'image/png' !== $mime ) {
			return 0;
		}

		// Same decompression-bomb bound for both decoders: the Imagick
		// branch enforces it via resource limits, but GD would happily
		// allocate gigabytes for a 12000×12000+ PNG.
		$dims = @getimagesize( $path );
		if ( ! is_array( $dims ) || (int) $dims[0] > 12000 || (int) $dims[1] > 12000 ) {
			return 0;
		}

		$webp = $path . '.webp';
		if ( is_file( $webp ) ) {
			if ( ! $overwrite ) {
				return 0;
			}
			@unlink( $webp );
		}

		$quality = (int) apply_filters( 'reallycache_webp_quality', 82 );
		$done    = false;

		try {
			if ( class_exists( '\Imagick' ) && in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true ) ) {
				$image = new \Imagick();
				// Bounded resources against decompression bombs, and an
				// explicit coder so only JPEG/PNG decoders ever run.
				$image->setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
				$image->setResourceLimit( \Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024 );
				$image->setResourceLimit( \Imagick::RESOURCETYPE_WIDTH, 12000 );
				$image->setResourceLimit( \Imagick::RESOURCETYPE_HEIGHT, 12000 );
				$image->readImage( ( 'image/png' === $mime ? 'png:' : 'jpeg:' ) . $path );
				$image->setImageFormat( 'webp' );
				$image->setImageCompressionQuality( $quality );
				$done = $image->writeImage( $webp );
				$image->destroy();
			} elseif ( function_exists( 'imagewebp' ) ) {
				$source = 'image/png' === $mime ? @imagecreatefrompng( $path ) : @imagecreatefromjpeg( $path );
				if ( $source ) {
					imagepalettetotruecolor( $source );
					imagealphablending( $source, false );
					imagesavealpha( $source, true );
					$done = imagewebp( $source, $webp, $quality );
					imagedestroy( $source );
				}
			}
		} catch ( \Throwable $e ) {
			$done = false;
		}

		if ( ! $done || ! is_file( $webp ) ) {
			@unlink( $webp );
			return 0;
		}

		$original = (int) filesize( $path );
		$new      = (int) filesize( $webp );
		if ( $new >= $original || $new < 1 ) {
			// The WebP came out bigger — keep serving the original.
			unlink( $webp );
			return 0;
		}

		return $original - $new;
	}

	public static function delete_twins( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return;
		}

		$files = [ $file ];
		$meta  = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}

		foreach ( $files as $path ) {
			if ( is_file( $path . '.webp' ) ) {
				@unlink( $path . '.webp' );
			}
		}
	}

	/* ------------------------------------------------------------- serving */

	/**
	 * Rewrites upload-image URLs in HTML about to be cached to their .webp
	 * twin when one exists on disk. Runs once per page at store time, so
	 * the cost is amortized across every cached hit.
	 */
	public static function rewrite( $html ) {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return $html;
		}

		$basedir  = rtrim( $uploads['basedir'], '/' );
		$base     = preg_replace( '#^https?:#i', '', rtrim( $uploads['baseurl'], '/' ) );
		$pattern  = '#(?:https?:)?' . preg_quote( $base, '#' ) . '/[^"\'\s>\)\?]+?\.(?:jpe?g|png)#i';
		$replaced = preg_replace_callback( $pattern, function ( $match ) use ( $base, $basedir ) {
			$url = $match[0];
			$rel = preg_replace( '#^(?:https?:)?' . preg_quote( $base, '#' ) . '#i', '', $url );
			if ( ! is_string( $rel ) || false !== strpos( $rel, '..' ) ) {
				return $url; // No filesystem probing outside uploads.
			}
			$path = $basedir . $rel;

			return is_file( $path . '.webp' ) ? $url . '.webp' : $url;
		}, $html );

		return null === $replaced ? $html : $replaced;
	}

	/* -------------------------------------------------------------- status */

	public static function status() {
		global $wpdb;

		$state  = self::state();
		$totals = self::totals();
		$queued = self::queued();

		$library_total     = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')"
		);
		$library_converted = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND p.post_type = 'attachment'",
			self::META_KEY
		) );

		$disk = ( $library_total > $library_converted ) ? self::disk_estimate() : [ 'estimate' => 0, 'free' => null ];

		return [
			'supported'         => self::is_supported(),
			'converted'         => (int) $totals['count'],
			'saved'             => (int) $totals['saved'],
			'estimate'          => (int) $disk['estimate'],
			'disk_free'         => $disk['free'],
			'library_total'     => $library_total,
			'library_converted' => min( $library_converted, $library_total ),
			'done'              => (int) $state['done'],
			'total'             => (int) $state['total'],
			'pending'           => $queued,
			'current'           => (string) $state['current'],
			'running'           => $queued > 0,
			'cache_stale'       => (bool) get_option( self::DIRTY_OPTION ),
		];
	}

	public static function reset() {
		delete_option( self::QUEUE_OPTION );
		delete_option( self::STATE_OPTION );
		delete_option( self::MODE_OPTION );
		delete_option( self::DIRTY_OPTION );
		delete_option( self::LOCK );
		wp_clear_scheduled_hook( self::TICK_HOOK );
	}

	private static function queued() {
		return count( (array) get_option( self::QUEUE_OPTION, [] ) );
	}

	private static function state() {
		$state = get_option( self::STATE_OPTION, [] );

		return wp_parse_args( is_array( $state ) ? $state : [], [ 'done' => 0, 'total' => 0, 'current' => '', 'updated' => 0 ] );
	}

	private static function totals() {
		$totals = get_option( self::TOTALS_OPTION, [] );

		return wp_parse_args( is_array( $totals ) ? $totals : [], [ 'count' => 0, 'saved' => 0 ] );
	}
}
