<?php
namespace ReallyCache\Media;

use ReallyCache\Config;

/**
 * LCP Boost — attribute-only tweaks to the hero image of a page as it is
 * cached, targeting PageSpeed's Largest Contentful Paint:
 *
 *  - the first content image loses loading="lazy" (lazy hero = huge LCP hit),
 *  - it gains fetchpriority="high" so the browser fetches it first,
 *  - a <link rel="preload" as="image"> for it is injected into <head>.
 *
 * Runs after the WebP rewrite, so the preload points at the .webp twin.
 * Nothing is restructured — only attributes and one head line — so it is
 * safe with any theme or page builder.
 */
class LcpBoost {

	public static function register() {
		add_filter( 'reallycache_before_store', function ( $html ) {
			$config = Config::load();

			return empty( $config['lcp_boost'] ) ? $html : self::apply( $html );
		}, 20 );
	}

	public static function apply( $html ) {
		$body_pos = stripos( $html, '<body' );
		if ( false === $body_pos ) {
			return $html;
		}

		if ( ! preg_match( '/<img\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $body_pos ) ) {
			return $html;
		}

		$tag    = $match[0][0];
		$offset = $match[0][1];

		if ( false !== stripos( $tag, 'data:image' ) ) {
			return $html; // Inline placeholder — nothing worth boosting.
		}

		$boosted = preg_replace( '/\sloading=("|\')?lazy\1?/i', '', $tag );
		if ( false === stripos( $boosted, 'fetchpriority' ) ) {
			$boosted = preg_replace( '/<img\b/i', '<img fetchpriority="high"', $boosted, 1 );
		}

		$html = substr_replace( $html, $boosted, $offset, strlen( $tag ) );

		$preload = self::preload_tag( $boosted );
		if ( $preload ) {
			$head_end = stripos( $html, '</head>' );
			if ( false !== $head_end ) {
				$html = substr_replace( $html, $preload . "\n", $head_end, 0 );
			}
		}

		return $html;
	}

	private static function preload_tag( $img_tag ) {
		$src    = preg_match( '/\ssrc=("|\')(.*?)\1/i', $img_tag, $m ) ? $m[2] : '';
		$srcset = preg_match( '/\ssrcset=("|\')(.*?)\1/i', $img_tag, $m ) ? $m[2] : '';
		$sizes  = preg_match( '/\ssizes=("|\')(.*?)\1/i', $img_tag, $m ) ? $m[2] : '';

		if ( '' === $src && '' === $srcset ) {
			return '';
		}

		$attrs = 'rel="preload" as="image" fetchpriority="high"';
		if ( '' !== $src ) {
			$attrs .= ' href="' . esc_url( $src ) . '"';
		}
		if ( '' !== $srcset ) {
			$attrs .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
		}
		if ( '' !== $sizes ) {
			$attrs .= ' imagesizes="' . esc_attr( $sizes ) . '"';
		}

		return '<link ' . $attrs . ' data-reallycache="lcp">';
	}
}
