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

		// DONOTCACHEPAGE is the ecosystem-wide opt-out convention honored by
		// every serious cache plugin; third-party code relies on it.
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		$error = error_get_last();
		if ( null !== $error && ( $error['type'] & ( E_ERROR | E_CORE_ERROR | E_PARSE | E_COMPILE_ERROR | E_USER_ERROR ) ) ) {
			return false;
		}

		if ( function_exists( 'http_response_code' ) && http_response_code() >= 300 ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) && false === stripos( $header, 'text/html' ) ) {
				return false;
			}
		}

		// A page cut off mid-render (timeout, fatal in a late hook) must never
		// be frozen into the cache.
		if ( false === stripos( $buffer, '</html>' ) ) {
			return false;
		}

		if ( is_null( $wp_query ) || ! function_exists( 'is_404' ) ) {
			return false;
		}

		if ( is_404() || is_search() || is_feed() || is_preview() || is_robots() || is_trackback() || is_user_logged_in() ) {
			return false;
		}

		if ( get_query_var( 'sitemap' ) || get_query_var( 'xsl' ) || get_query_var( 'xml_sitemap' ) ) {
			return false;
		}

		if ( ! empty( $this->config['woocommerce_protection'] ) && function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}

		if ( function_exists( 'post_password_required' ) && is_singular() && post_password_required() ) {
			return false;
		}

		if ( is_singular() && get_post_meta( get_the_ID(), '_reallycache_skip', true ) ) {
			return false;
		}

		return (bool) apply_filters( 'reallycache_should_cache', true, $buffer );
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
			$prefixes = array_merge( $prefixes, [ 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_' ] );
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
