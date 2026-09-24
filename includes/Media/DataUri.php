<?php
namespace ReallyCache\Media;

use ReallyCache\Config;

/**
 * Data-URI extractor — rescues pages with huge base64-inlined images
 * (the classic case: SVG exported from Figma with photos embedded, which
 * puts megabytes of base64 into the HTML of every view).
 *
 * At cache time, any inline image blob over the threshold is decoded,
 * verified by magic bytes, written once to a content-hashed static file
 * under uploads, and the HTML reference becomes a plain URL. The page
 * shrinks by megabytes, the browser caches the image separately, and the
 * existing WebP pipeline picks the file up like any other image.
 *
 * Fail-safe direction everywhere: any doubt (bad base64, unknown format,
 * write failure) leaves the original data-URI untouched.
 */
class DataUri {

	const DIR_NAME       = 'reallycache-media';
	const PENDING_OPTION = 'reallycache_datauri_pending';
	const CONVERT_HOOK   = 'reallycache_datauri_convert';

	/** Base64 length threshold (~48KB binary). Below this, inlining is fine. */
	const MIN_BASE64_LEN = 65000;

	public static function register() {
		// Priority 5 — before the WebP rewrite (10), so once a twin exists
		// the same store pass swaps the extracted file to .webp.
		add_filter( 'reallycache_before_store', function ( $html ) {
			$config = Config::load();

			return empty( $config['datauri_extract'] ) ? $html : self::rewrite( $html );
		}, 5 );

		add_action( self::CONVERT_HOOK, [ __CLASS__, 'convert_pending' ] );
	}

	public static function rewrite( $html ) {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return $html;
		}

		$dir  = rtrim( $uploads['basedir'], '/' ) . '/' . self::DIR_NAME;
		$base = rtrim( $uploads['baseurl'], '/' ) . '/' . self::DIR_NAME;

		$out = preg_replace_callback(
			'#data:image/(?:png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=]{' . self::MIN_BASE64_LEN . ',})#',
			function ( $match ) use ( $dir, $base ) {
				return self::extract( $match[0], $match[1], $dir, $base );
			},
			$html,
			20 // Bounded work per page.
		);

		return is_string( $out ) ? $out : $html;
	}

	/** @return string The replacement URL, or the original token on any doubt. */
	private static function extract( $original, $payload, $dir, $base ) {
		$binary = base64_decode( $payload, true );
		// The floor skips blobs too small to matter; the ceiling bounds
		// what a single embedded blob can put on disk (the Figma case that
		// motivated this feature embeds ~1–8MB images).
		if ( false === $binary || strlen( $binary ) < 1024 || strlen( $binary ) > 12 * 1024 * 1024 ) {
			return $original;
		}

		// Trust the bytes, not the declared mime.
		$ext = self::type_from_magic( $binary );
		if ( null === $ext ) {
			return $original;
		}

		// A magic prefix alone would pass a polyglot (valid header, junk
		// body) to a public URL — require the whole blob to parse as the
		// image type the prefix claims.
		if ( function_exists( 'getimagesizefromstring' ) ) {
			$info = @getimagesizefromstring( $binary );
			$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
			$want = [ 'png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp' ];
			if ( $mime !== ( $want[ $ext ] ?? '' ) ) {
				return $original;
			}
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return $original;
		}
		if ( ! is_file( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}

		// Content-hashed name: identical images across pages share one
		// file, repeated stores cost nothing, and the URL is immutable.
		$name = sha1( $binary ) . '.' . $ext;
		$path = $dir . '/' . $name;

		if ( ! is_file( $path ) ) {
			$tmp = $path . '.' . uniqid( '', true ) . '.tmp';
			if ( false === @file_put_contents( $tmp, $binary ) ) {
				@unlink( $tmp );
				return $original;
			}
			if ( ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
				return $original;
			}
		}

		// Queue a WebP twin so the next store serves the compressed form.
		if ( in_array( $ext, [ 'png', 'jpg' ], true ) && ! is_file( $path . '.webp' ) ) {
			$pending = (array) get_option( self::PENDING_OPTION, [] );
			if ( ! in_array( $path, $pending, true ) && count( $pending ) < 100 ) {
				$pending[] = $path;
				update_option( self::PENDING_OPTION, $pending, false );
			}
			if ( ! wp_next_scheduled( self::CONVERT_HOOK ) ) {
				wp_schedule_single_event( time() + 30, self::CONVERT_HOOK );
			}
		}

		return $base . '/' . $name;
	}

	/** @return string|null File extension for recognized image magic bytes. */
	private static function type_from_magic( $binary ) {
		if ( 0 === strncmp( $binary, "\x89PNG\r\n\x1a\n", 8 ) ) {
			return 'png';
		}
		if ( 0 === strncmp( $binary, "\xFF\xD8\xFF", 3 ) ) {
			return 'jpg';
		}
		if ( 0 === strncmp( $binary, 'GIF8', 4 ) ) {
			return 'gif';
		}
		if ( 0 === strncmp( $binary, 'RIFF', 4 ) && 'WEBP' === substr( $binary, 8, 4 ) ) {
			return 'webp';
		}

		return null;
	}

	/** Cron: create WebP twins for extracted files, a few per run. */
	public static function convert_pending() {
		$pending = (array) get_option( self::PENDING_OPTION, [] );
		if ( empty( $pending ) ) {
			return;
		}

		$batch = array_splice( $pending, 0, 5 );
		update_option( self::PENDING_OPTION, $pending, false );

		foreach ( $batch as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				Webp::convert_file( $path );
			}
		}

		if ( ! empty( $pending ) ) {
			wp_schedule_single_event( time() + 30, self::CONVERT_HOOK );
		}
	}
}
