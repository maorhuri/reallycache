<?php
namespace ReallyCache\Media;

use ReallyCache\Config;

/**
 * Font Display Swap — the PageSpeed "font display" audit. Invisible text
 * while a webfont loads (FOIT) delays FCP/LCP; font-display:swap shows
 * fallback text immediately.
 *
 * Three coverage layers, all safe:
 *  - inline <style> @font-face blocks get font-display:swap at cache time,
 *  - Google Fonts stylesheet links get &display=swap appended,
 *  - Elementor (Pro) custom fonts are generated with swap via its own
 *    filter, which covers self-hosted .woff files like Elementor uploads.
 */
class Fonts {

	public static function register() {
		add_filter( 'reallycache_before_store', function ( $html ) {
			$config = Config::load();

			return empty( $config['font_display_swap'] ) ? $html : self::rewrite( $html );
		}, 15 );

		add_filter( 'elementor_pro/custom_fonts/font_display', function ( $display ) {
			$config = Config::load();

			return empty( $config['font_display_swap'] ) ? $display : 'swap';
		} );
	}

	public static function rewrite( $html ) {
		// PCRE returns null on backtrack/JIT limits (a huge or unclosed
		// <style> can do it); the original page must survive that, or a
		// blank document gets cached for the whole TTL.

		// Inline @font-face blocks without an explicit font-display.
		$out = preg_replace_callback( '/<style\b[^>]*>.*?<\/style>/is', function ( $match ) {
			$swapped = preg_replace_callback( '/@font-face\s*{[^}]*}/i', function ( $face ) {
				if ( false !== stripos( $face[0], 'font-display' ) ) {
					return $face[0];
				}

				return preg_replace( '/{/', '{font-display:swap;', $face[0], 1 ) ?? $face[0];
			}, $match[0] );

			return $swapped ?? $match[0];
		}, $html );
		$html = $out ?? $html;

		// Google Fonts stylesheet links without a display parameter.
		$out = preg_replace_callback( '/https:\/\/fonts\.googleapis\.com\/css2?\?[^"\'\s>]+/i', function ( $match ) {
			$url = $match[0];
			if ( false !== stripos( $url, 'display=' ) ) {
				return $url;
			}

			return $url . ( false !== strpos( $url, '&amp;' ) ? '&amp;' : '&' ) . 'display=swap';
		}, $html );

		return $out ?? $html;
	}
}
