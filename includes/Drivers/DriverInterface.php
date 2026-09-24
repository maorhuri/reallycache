<?php
namespace ReallyCache\Drivers;

use ReallyCache\CacheKey;

/**
 * A cache driver owns storage and delivery of full pages. The engine decides
 * *what* is cacheable; the driver decides *where it lives and who serves it*:
 * the filesystem served by PHP, Nginx's FastCGI cache, LiteSpeed's server
 * cache — each is one implementation of this contract.
 */
interface DriverInterface {

	/** Machine name, e.g. 'file', 'nginx', 'litespeed'. */
	public function name();

	/**
	 * Try to deliver a cached page for this request. On a hit the driver
	 * sends the response itself and never returns. Server-level drivers
	 * are a no-op here — their server answered before PHP even started.
	 *
	 * @param CacheKey $key
	 * @param int      $ttl Seconds a stored page stays fresh.
	 */
	public function serve( CacheKey $key, $ttl );

	/**
	 * Persist a rendered page (or, for header-based drivers, mark the
	 * in-flight response as cacheable).
	 *
	 * @param CacheKey $key
	 * @param string   $html
	 * @param int      $ttl
	 * @param string[] $headers Security headers to replay on hits.
	 */
	public function store( CacheKey $key, $html, $ttl, array $headers = [] );

	/**
	 * Tell the outer cache this response must not be stored. Only matters
	 * for header-based drivers; called on every uncacheable page view.
	 */
	public function mark_uncacheable();

	/** True when a fresh cached copy of this exact key exists. */
	public function has( CacheKey $key, $ttl );

	/** Purge one URL, all its variants, leaving sub-pages alone. */
	public function purge_url( $url );

	public function purge_all();

	/** Drop entries older than $ttl seconds. */
	public function gc( $ttl );

	/** @return array{pages:int,size:int} */
	public function stats();
}
