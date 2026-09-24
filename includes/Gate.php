<?php
namespace ReallyCache;

/**
 * Decides what may be cached. Two stages:
 *
 *  - request_ok()  runs before WordPress loads (from the drop-in), so it can
 *    only look at the request itself: method, cookies, query string, URI.
 *  - response_ok() runs after the page rendered, with all of WordPress
 *    available: conditionals, response code, third-party opt-outs.
 *
 * The exclusion lists here cover years of production edge cases: per-request
 * tokens, cart cookies, bot traffic, personalization triggers.
 */
class Gate {

	/** @var string Why the last response_ok() said no — for the debug header. */
	public $reject_reason = '';

	private $config;

	public function __construct( array $config ) {
		$this->config = $config;
	}

	/** Cheap, WP-free checks. Gates both serving and storing. */
	public function request_ok() {
		if ( ! empty( $this->config['dev_mode_until'] ) && $this->config['dev_mode_until'] > time() ) {
			return false;
		}

		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}

		foreach ( [ 'WP_CLI', 'DOING_CRON', 'REST_REQUEST', 'JSON_REQUEST', 'WC_API_REQUEST', 'WP_ADMIN', 'DOING_AJAX', 'XMLRPC_REQUEST' ] as $const ) {
			if ( defined( $const ) && constant( $const ) ) {
				return false;
			}
		}
		if ( defined( 'WP_USE_THEMES' ) && false === WP_USE_THEMES ) {
			return false;
		}

		if ( isset( $_GET['customize_changeset_uuid'] ) || isset( $_POST['wp_customize'] ) || isset( $_GET['preview'] ) ) {
			return false;
		}

		// Never-cache query parameters: per-request tokens and non-idempotent
		// actions. Serving one visitor's nonce to another is a security bug.
		foreach ( [ '_wpnonce', 'wc-ajax', 'add-to-cart', 'remove_item', 'removed_item', 'action', 'doing_wp_cron', 'add_to_wishlist', 's' ] as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				return false;
			}
		}

		if ( $this->has_login_cookie() || $this->has_bypass_cookie() ) {
			return false;
		}

		if ( $this->uri_is_excluded() || $this->woo_uri_is_excluded() || $this->user_agent_is_excluded() ) {
			return false;
		}

		$uri_path = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
		if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-json)([/?]|$)#', $uri_path ) ) {
			return false;
		}

		return true;
	}

	/** Full-WordPress checks, run against the rendered page. */
	public function response_ok( $buffer ) {
		global $wp_query;

		$this->reject_reason = '';

		// DONOTCACHEPAGE is the ecosystem-wide opt-out convention honored by
		// every serious cache plugin; third-party code relies on it.
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return $this->reject( 'donotcachepage' );
		}

		$error = error_get_last();
		if ( null !== $error && ( $error['type'] & ( E_ERROR | E_CORE_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
			return $this->reject( 'php-error' );
		}

		if ( function_exists( 'http_response_code' ) && http_response_code() >= 300 ) {
			return $this->reject( 'status-' . http_response_code() );
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) ) {
				return $this->reject( 'content-type' );
			}
			// A Set-Cookie usually means personalized output. But many
			// themes/plugins call session_start() on every page and emit a
			// fresh, content-irrelevant PHPSESSID even for anonymous
			// visitors — refusing those would disable caching site-wide.
			// So only a cookie that actually marks a logged-in / cart /
			// comment state blocks caching; benign ones are ignored (and
			// stripped from the stored headers so they are never replayed).
			if ( 0 === stripos( $header, 'Set-Cookie:' ) && $this->set_cookie_is_personal( $header ) ) {
				return $this->reject( 'set-cookie' );
			}
		}

		// PHP's session_start() sprays "Cache-Control: no-store, no-cache"
		// + a hardcoded 1981 Expires on EVERY page when any plugin opens a
		// session. That boilerplate says nothing about the content and
		// honoring it disables caching site-wide, so it is ignored. A
		// deliberate private/no-store (without the 1981 signature) is
		// still honored — as is DONOTCACHEPAGE, the real opt-out.
		$php_session_boilerplate = false;
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Expires:' ) && false !== strpos( $header, '1981' ) ) {
				$php_session_boilerplate = true;
				break;
			}
		}
		if ( ! $php_session_boilerplate ) {
			foreach ( headers_list() as $header ) {
				if ( 0 === stripos( $header, 'Cache-Control:' ) && preg_match( '/\b(?:private|no-store)\b/i', $header ) ) {
					return $this->reject( 'cache-control' );
				}
			}
		}

		// A page cut off mid-render (timeout, fatal in a late hook) must never
		// be frozen into the cache.
		if ( false === stripos( $buffer, '</html>' ) ) {
			return $this->reject( 'truncated' );
		}

		if ( is_null( $wp_query ) || ! function_exists( 'is_404' ) ) {
			return $this->reject( 'no-wp-query' );
		}

		if ( is_404() || is_search() || is_feed() || is_preview() || is_robots() || is_trackback() || is_user_logged_in() ) {
			return $this->reject( 'page-type' );
		}

		if ( get_query_var( 'sitemap' ) || get_query_var( 'xsl' ) || get_query_var( 'xml_sitemap' ) ) {
			return $this->reject( 'sitemap' );
		}

		if ( ! empty( $this->config['woocommerce_protection'] ) && function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return $this->reject( 'woocommerce' );
		}

		if ( function_exists( 'post_password_required' ) && is_singular() && post_password_required() ) {
			return $this->reject( 'password' );
		}

		if ( is_singular() && get_post_meta( get_the_ID(), '_reallycache_skip', true ) ) {
			return $this->reject( 'post-meta' );
		}

		if ( ! apply_filters( 'reallycache_should_cache', true, $buffer ) ) {
			return $this->reject( 'filter' );
		}

		return true;
	}

	private function reject( $reason ) {
		$this->reject_reason = $reason;

		return false;
	}

	/**
	 * True only when a Set-Cookie header carries a cookie that marks
	 * personalized state in THIS response. Context matters: request_ok()
	 * already guaranteed the request arrived cookie-less, so a WooCommerce
	 * guest-session cookie born here (some plugins spawn one on every
	 * page) belongs to an empty, generic session — the HTML is identical
	 * for everyone and safe to cache. The cookie itself is never stored
	 * or replayed, and a visitor CARRYING that cookie still bypasses the
	 * cache at request time. Cookies that imply the page content itself
	 * is personal (login, post password, cart contents) still block.
	 */
	private function set_cookie_is_personal( $header ) {
		$value = trim( substr( $header, strlen( 'Set-Cookie:' ) ) );
		$name  = strtolower( trim( strtok( $value, '=' ) ) );

		$personal_prefixes = [ 'wordpress_logged_in_', 'wordpress_sec_', 'comment_author_', 'wp-postpass_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'edd_items_in_cart', 'edd_cart_token' ];

		foreach ( $personal_prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'reallycache_set_cookie_is_personal', false, $name, $header );
	}

	private function has_login_cookie() {
		$prefix = defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : 'wordpress_logged_in_';

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( $name, $prefix ) || 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}

		return false;
	}

	private function has_bypass_cookie() {
		$prefixes = (array) ( $this->config['bypass_cookies'] ?? [] );
		if ( ! empty( $this->config['woocommerce_protection'] ) ) {
			// Only an actual cart forces live pages. The session cookie
			// alone lives 48h (and some plugins spawn one for everyone),
			// so keying on it would lock visitors out of the cache for
			// days after their cart emptied. items_in_cart exists exactly
			// while the cart has contents — the WP Rocket approach.
			$prefixes = array_merge( $prefixes, [ 'woocommerce_items_in_cart' ] );
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			foreach ( $prefixes as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function uri_is_excluded() {
		$patterns = $this->config['excluded_uris'] ?? [];
		if ( empty( $patterns ) ) {
			return false;
		}

		$uri = rawurldecode( strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' ) );

		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			// Wildcard patterns: escape the literal parts around each `*`.
			// preg_quote() must run on the pieces, not the whole pattern —
			// quoting first turns `*` into `\*` and the wildcard dies silently.
			$regex = implode( '.*?', array_map( function ( $part ) {
				return preg_quote( $part, '#' );
			}, explode( '*', '/' . trim( $pattern, '/' ) ) ) );

			if ( @preg_match( "#^{$regex}/?$#u", $uri ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The cart, checkout and account pages as configured in WooCommerce
	 * (any slug, any language), synced into the config by Compat\WooCommerce
	 * so this check works before WordPress loads. Prefix match covers
	 * checkout endpoints like /checkout/order-pay/.
	 */
	private function woo_uri_is_excluded() {
		if ( empty( $this->config['woocommerce_protection'] ) || empty( $this->config['woo_excluded_paths'] ) ) {
			return false;
		}

		$uri = rtrim( rawurldecode( strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' ) ), '/' ) . '/';

		foreach ( (array) $this->config['woo_excluded_paths'] as $path ) {
			$path = rtrim( (string) $path, '/' ) . '/';
			if ( '/' !== $path && 0 === strpos( $uri, $path ) ) {
				return true;
			}
		}

		return false;
	}

	private function user_agent_is_excluded() {
		$needles = $this->config['excluded_user_agents'] ?? [];
		$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
		if ( '' === $ua || empty( $needles ) ) {
			return false;
		}

		foreach ( $needles as $needle ) {
			if ( '' !== trim( (string) $needle ) && false !== stripos( $ua, trim( $needle ) ) ) {
				return true;
			}
		}

		return false;
	}
}
