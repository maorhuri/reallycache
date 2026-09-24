<?php
namespace ReallyCache;

use ReallyCache\Drivers\DriverInterface;
use ReallyCache\Drivers\FileDriver;

/**
 * The one object shared by the drop-in (pre-WordPress) and the plugin
 * (full WordPress). It wires config + gate + driver, serves hits, starts
 * the output buffer on misses, and exposes purging to the rest of the code.
 */
class Engine {

	/** @var Engine|null */
	private static $instance;

	private $config;
	private $gate;
	private $driver;
	private $buffering = false;

	public static function boot( array $config, $content_dir ) {
		if ( ! self::$instance ) {
			self::$instance = new self( $config, $content_dir );
		}

		return self::$instance;
	}

	/** @return Engine|null Null when the drop-in never booted (cache disabled). */
	public static function instance() {
		return self::$instance;
	}

	private function __construct( array $config, $content_dir ) {
		$this->config = $config;
		$this->gate   = new Gate( $config );
		$this->driver = new FileDriver( $content_dir, $config );
	}

	public function config() {
		return $this->config;
	}

	/** @return DriverInterface */
	public function driver() {
		return $this->driver;
	}

	public function gate() {
		return $this->gate;
	}

	/** Drop-in entry point: deliver a cached page and exit, or fall through. */
	public function maybe_serve() {
		if ( empty( $this->config['cache_enabled'] ) || ! $this->gate->request_ok() ) {
			return;
		}

		$key = CacheKey::from_request( $this->config );
		if ( null === $key ) {
			return;
		}

		$this->driver->serve( $key, (int) $this->config['ttl'] );
	}

	/** Called from wp_cache_postload() on every request that wasn't a hit. */
	public function start_buffer() {
		if ( $this->buffering || empty( $this->config['cache_enabled'] ) || ! $this->gate->request_ok() ) {
			return;
		}

		$key = CacheKey::from_request( $this->config );
		if ( null === $key ) {
			return;
		}

		$this->buffering = true;
		ob_start( function ( $buffer ) use ( $key ) {
			return $this->maybe_store( $key, $buffer );
		} );
	}

	private function maybe_store( CacheKey $key, $buffer ) {
		if ( strlen( $buffer ) < 255 || ! $this->gate->response_ok( $buffer ) ) {
			$this->driver->mark_uncacheable();
			return $buffer;
		}

		$html = apply_filters( 'reallycache_before_store', $buffer, $key );
		$html .= "\n<!-- Cached by ReallyCache on " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->";

		$this->driver->store( $key, $html, (int) $this->config['ttl'] );
		header( 'X-ReallyCache: MISS' );

		return $html;
	}

	public function purge_url( $url ) {
		$this->driver->purge_url( $url );
		do_action( 'reallycache_purged_url', $url );
	}

	public function purge_urls( array $urls ) {
		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			$this->purge_url( $url );
		}
	}

	public function purge_all() {
		$this->driver->purge_all();
		do_action( 'reallycache_purged_all' );
	}
}
