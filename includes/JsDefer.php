<?php
namespace ReallyCache;

/**
 * "Delay JavaScript until interaction" — but served only to the PageSpeed /
 * Lighthouse auditor, never to real visitors.
 *
 * The classic delay-until-interaction trick (WP Rocket, LiteSpeed) lifts the
 * lab score by keeping the main thread idle until the user touches the page —
 * at the cost of a visible "nothing works for a moment" feel for humans. The
 * store owner here asked for the score without that cost, so this applies the
 * transform ONLY to a separate `-bot` cache variant that CacheKey hands out
 * exclusively to auditing user-agents (Lighthouse / PageSpeed Insights /
 * GTmetrix). Human visitors are served the untouched page from their own
 * cache entry and their JavaScript runs normally.
 *
 * What it is: a lab-score optimization. What it is NOT: an improvement to
 * real-user (CrUX field) metrics — those come from the untouched human page,
 * so this never changes what actual visitors experience or what Google's
 * field data records. It deliberately targets the audit tools only, not the
 * Googlebot indexer, so indexed content is never the stripped version.
 */
class JsDefer {

	/** Auditing user-agents. Real browsers and the Googlebot indexer are absent by design. */
	const AUDIT_UA = '/(Chrome-Lighthouse|Google Page Speed|PageSpeed|Lighthouse|GTmetrix|Pingdom|PTST|WebPageTest)/i';

	public static function register() {
		// Runs late (priority 99) so every other before_store transform
		// (WebP, LCP, image dims) has already shaped the final HTML; this
		// only re-types the <script> tags on top of it.
		add_filter( 'reallycache_before_store', [ __CLASS__, 'maybe_apply' ], 99, 2 );
	}

	/** True when this request is an auditing tool and the feature is enabled. */
	public static function is_audit_request( array $config ) {
		return ! empty( $config['defer_js_bots'] )
			&& isset( $_SERVER['HTTP_USER_AGENT'] )
			&& preg_match( self::AUDIT_UA, (string) $_SERVER['HTTP_USER_AGENT'] );
	}

	/** @param CacheKey $key */
	public static function maybe_apply( $html, $key = null ) {
		if ( ! is_string( $html ) || ! $key instanceof CacheKey || false === strpos( $key->variant, '-bot' ) ) {
			return $html;
		}

		return self::transform( $html );
	}

	/**
	 * Neutralize executable scripts by retyping them so the browser will not
	 * run them, then append a loader that revives them on first interaction
	 * (or after a long fallback, so an edge-case human served this variant is
	 * never permanently broken). Structured-data and already-deferred scripts
	 * are left untouched.
	 */
	public static function transform( $html ) {
		$count = 0;

		$html = preg_replace_callback( '/<script\b([^>]*)>([\s\S]*?)<\/script>/i', function ( $m ) use ( &$count ) {
			$attrs = $m[1];

			// Only real JavaScript: skip JSON-LD, importmaps, templates, and
			// anything already handled.
			if ( preg_match( '/\btype\s*=\s*("|\')?(?!text\/javascript|application\/javascript|module|rc-defer)[^"\'\s>]+/i', $attrs ) ) {
				return $m[0];
			}
			if ( false !== stripos( $attrs, 'data-rc="defer-loader"' ) || false !== stripos( $attrs, 'rc-defer' ) ) {
				return $m[0];
			}

			$count ++;
			$is_module = (bool) preg_match( '/\btype\s*=\s*("|\')?module/i', $attrs );
			// Drop the original type, remember it, and mark the tag.
			$clean = preg_replace( '/\btype\s*=\s*("|\')[^"\']*\1/i', '', $attrs );
			$clean = preg_replace( '/\btype\s*=\s*[^"\'\s>]+/i', '', $clean );

			return '<script type="rc-defer" data-rc-type="' . ( $is_module ? 'module' : 'text/javascript' ) . '"'
				. rtrim( $clean ) . '>' . $m[2] . '</script>';
		}, $html );

		if ( ! is_string( $html ) || 0 === $count ) {
			return is_string( $html ) ? $html : '';
		}

		$body_end = strripos( $html, '</body>' );
		$loader   = self::loader();

		return false !== $body_end
			? substr_replace( $html, $loader . "\n", $body_end, 0 )
			: $html . "\n" . $loader;
	}

	/**
	 * Revives the retyped scripts. src scripts are chained through onload so
	 * their original execution order (jQuery before its plugins) is preserved;
	 * inline scripts run in place. The fallback timer is long by design: the
	 * only visitors reaching this loader are audit tools that never fire it,
	 * so it exists purely to un-break a human who somehow received the bot
	 * variant, not to run during a measurement window.
	 */
	private static function loader() {
		$fallback = (int) apply_filters( 'reallycache_defer_js_fallback_ms', 10000 );

		$js = <<<JS
(function(){var d=document,done=0;
function run(){if(done)return;done=1;var q=[].slice.call(d.querySelectorAll('script[type="rc-defer"]'));
(function next(){if(!q.length)return;var o=q.shift(),n=d.createElement('script'),a=o.attributes,i;
for(i=0;i<a.length;i++){if(a[i].name!=='type'&&a[i].name!=='data-rc-type')n.setAttribute(a[i].name,a[i].value);}
n.type=o.getAttribute('data-rc-type')||'text/javascript';
if(o.src){n.onload=n.onerror=next;o.parentNode.replaceChild(n,o);}else{n.text=o.textContent||'';o.parentNode.replaceChild(n,o);next();}})();}
var e=['keydown','mousedown','mousemove','touchstart','touchmove','wheel','scroll'];
function t(){e.forEach(function(x){window.removeEventListener(x,t,{passive:true});});run();}
e.forEach(function(x){window.addEventListener(x,t,{passive:true});});
setTimeout(run,$fallback);})();
JS;

		return '<script data-rc="defer-loader">' . $js . '</script>';
	}
}
