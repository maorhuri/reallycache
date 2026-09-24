<?php
namespace ReallyCache\Media;

use ReallyCache\Config;

/**
 * Attribute-only image hardening applied as a page is cached, targeting two
 * PageSpeed audits without ever touching the layout:
 *
 *  - loading="lazy" + decoding="async" on off-screen images, so the browser
 *    defers everything below the fold. The first images are left eager
 *    (they are usually the LCP candidate — LcpBoost owns the very first).
 *  - width/height on images that lack them, so the browser reserves the box
 *    and the page stops shifting (Cumulative Layout Shift). Dimensions are
 *    read from WordPress's own "-800x600" filename suffix — no file access,
 *    no guesswork, and only applied when the pair is unambiguous.
 *
 * Everything here is safe with any theme: no tags are added or removed, only
 * attributes on <img> elements that were missing them.
 */
class ImageDims {

	/** The first N images stay eager — the hero/LCP region. */
	const EAGER_SKIP = 2;

	public static function register() {
		add_filter( 'reallycache_before_store', function ( $html ) {
			$config = Config::load();

			return empty( $config['image_dims'] ) ? $html : self::apply( $html );
		}, 15 );
	}

	public static function apply( $html ) {
		$seen = 0;

		return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( &$seen ) {
			$seen ++;

			return self::rewrite_tag( $m[0], $seen );
		}, $html ) ?? $html;
	}

	private static function rewrite_tag( $tag, $index ) {
		// Never touch inline placeholders or the LCP boost's own markup.
		if ( false !== stripos( $tag, 'data:image' ) || false !== stripos( $tag, 'fetchpriority' ) ) {
			return $tag;
		}

		$add = '';

		// decoding is safe on every image, including the hero.
		if ( false === stripos( $tag, 'decoding=' ) ) {
			$add .= ' decoding="async"';
		}

		// Lazy only below the fold, and never override an author's choice.
		if ( $index > self::EAGER_SKIP && false === stripos( $tag, 'loading=' ) ) {
			$add .= ' loading="lazy"';
		}

		// Dimensions from the WordPress "-WxH" filename suffix, and only when
		// BOTH are missing (a half-set pair means the theme is doing something
		// deliberate with aspect-ratio — leave it alone).
		if ( false === stripos( $tag, 'width=' ) && false === stripos( $tag, 'height=' ) ) {
			$dims = self::dims_from_tag( $tag );
			if ( $dims ) {
				$add .= ' width="' . $dims[0] . '" height="' . $dims[1] . '"';
			}
		}

		if ( '' === $add ) {
			return $tag;
		}

		// Splice the attributes in right after "<img".
		return preg_replace( '/<img\b/i', '<img' . $add, $tag, 1 ) ?? $tag;
	}

	/**
	 * Pull intrinsic dimensions from the src (or the largest srcset entry),
	 * where WordPress encodes them as "name-<w>x<h>.ext".
	 *
	 * @return array{0:int,1:int}|null
	 */
	private static function dims_from_tag( $tag ) {
		$src = preg_match( '/\ssrc=("|\')(.*?)\1/i', $tag, $m ) ? $m[2] : '';
		if ( preg_match( '/-(\d{2,5})x(\d{2,5})\.(?:jpe?g|png|gif|webp)(?:\?|$|["\'\s])/i', $src, $d ) ) {
			return [ (int) $d[1], (int) $d[2] ];
		}

		// A lazy-loading theme often parks a 1x1 or blank in src and the real
		// file in srcset — read the first sized candidate there.
		$srcset = preg_match( '/\ssrcset=("|\')(.*?)\1/i', $tag, $m ) ? $m[2] : '';
		if ( '' !== $srcset && preg_match( '/-(\d{2,5})x(\d{2,5})\.(?:jpe?g|png|gif|webp)/i', $srcset, $d ) ) {
			return [ (int) $d[1], (int) $d[2] ];
		}

		return null;
	}
}
