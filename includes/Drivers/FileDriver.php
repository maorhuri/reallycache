<?php
namespace ReallyCache\Drivers;

use ReallyCache\CacheKey;

/**
 * The universal driver: gzipped HTML on disk, served by PHP from the drop-in.
 * Works on any host with zero server configuration.
 *
 * Layout mirrors the URL — cache/reallycache/{host}/{path}/index[-mobile].html
 * plus a precompressed .gz twin — so a web server can be pointed straight at
 * the files later (Apache rewrite / Nginx try_files), and purging a URL is a
 * directory-local delete that never touches sub-pages.
 */
class FileDriver implements DriverInterface {

	private $root;
	private $config;

	public function __construct( $content_dir, array $config ) {
		$this->root   = rtrim( $content_dir, '/' ) . '/cache/reallycache';
		$this->config = $config;
	}

	public function name() {
		return 'file';
	}

	public function serve( CacheKey $key, $ttl ) {
		$file = $this->path_for( $key );
		if ( null === $file || ! is_file( $file ) ) {
			return;
		}

		$mtime = filemtime( $file );
		if ( ! $mtime ) {
			return;
		}
		// TTL 0 means permanent: pages live until a purge removes them.
		if ( $ttl > 0 && ( time() - $mtime ) > $ttl ) {
			return; // Stale entries are treated as a miss; gc() removes them.
		}

		$last_modified = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

		header( 'X-ReallyCache: HIT' );
		header( 'Age: ' . ( time() - $mtime ) );
		header( 'Last-Modified: ' . $last_modified );
		// Mobile gets its own entry, so intermediaries must key on UA too.
		header( 'Vary: Accept-Encoding' . ( empty( $this->config['separate_mobile'] ) ? '' : ', User-Agent' ) );

		// Replay the security headers captured when the page was stored —
		// on a hit PHP never runs the code that would send them.
		$meta = @file_get_contents( $file . '.h.json' );
		if ( $meta ) {
			$headers = json_decode( $meta, true );
			if ( is_array( $headers ) ) {
				foreach ( $headers as $stored_header ) {
					if ( is_string( $stored_header ) && ! preg_match( '/[\r\n]/', $stored_header ) ) {
						header( $stored_header );
					}
				}
			}
		}

		if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && trim( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) === $last_modified ) {
			header( 'HTTP/1.1 304 Not Modified' );
			exit;
		}

		header( 'Content-Type: text/html; charset=UTF-8' );

		$gz = $file . '.gz';
		if ( is_file( $gz ) && false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip' ) ) {
			header( 'Content-Encoding: gzip' );
			header( 'Content-Length: ' . filesize( $gz ) );
			readfile( $gz );
		} else {
			header( 'Content-Length: ' . filesize( $file ) );
			readfile( $file );
		}

		exit;
	}

	public function store( CacheKey $key, $html, $ttl, array $headers = [] ) {
		$file = $this->path_for( $key );
		if ( null === $file ) {
			return;
		}

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return;
		}

		$this->ensure_root_guard();

		if ( $headers ) {
			@file_put_contents( $file . '.h.json', (string) wp_json_encode( array_values( $headers ) ) );
		} elseif ( is_file( $file . '.h.json' ) ) {
			@unlink( $file . '.h.json' );
		}

		// Atomic publish: a visitor must never read a half-written page.
		$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $html ) ) {
			@unlink( $tmp );
			return;
		}
		rename( $tmp, $file );

		$gzipped = gzencode( $html, 6 );
		if ( false !== $gzipped ) {
			$tmp = $file . '.' . uniqid( '', true ) . '.tmp';
			if ( false !== @file_put_contents( $tmp, $gzipped ) ) {
				rename( $tmp, $file . '.gz' );
			} else {
				@unlink( $tmp );
			}
		}
	}

	public function has( CacheKey $key, $ttl ) {
		$file = $this->path_for( $key );
		if ( null === $file || ! is_file( $file ) ) {
			return false;
		}

		$mtime = filemtime( $file );

		return $mtime && ( $ttl <= 0 || ( time() - $mtime ) <= $ttl );
	}

	public function mark_uncacheable() {
		// PHP serves this driver's hits itself — nothing upstream to signal.
	}

	public function purge_url( $url ) {
		$key = CacheKey::from_url( $url, $this->config );
		if ( null === $key ) {
			return;
		}

		$dir = $this->root . '/' . $key->dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// Only this URL's own files: sub-pages live in sub-directories and
		// purging a parent must not wipe them.
		foreach ( glob( $dir . 'index*.html*' ) ?: [] as $file ) {
			@unlink( $file );
		}
	}

	public function purge_all() {
		$this->delete_tree( $this->root );
	}

	public function gc( $ttl ) {
		if ( $ttl <= 0 || ! is_dir( $this->root ) ) {
			return; // Permanent cache: nothing ever expires by age.
		}

		$cutoff = time() - $ttl;
		$it     = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $it as $item ) {
			if ( $item->isFile() && $item->getMTime() < $cutoff ) {
				@unlink( $item->getPathname() );
			} elseif ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // Only succeeds when empty.
			}
		}
	}

	public function stats() {
		$pages = 0;
		$size  = 0;

		if ( is_dir( $this->root ) ) {
			$it = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				$size += $item->getSize();
				if ( '.html' === substr( $item->getFilename(), -5 ) ) {
					$pages ++;
				}
			}
		}

		return [ 'pages' => $pages, 'size' => $size ];
	}

	/** Keeps directory listings of the cache tree from enumerating cached URLs. */
	private function ensure_root_guard() {
		if ( ! is_file( $this->root . '/.htaccess' ) ) {
			@file_put_contents( $this->root . '/.htaccess', "Options -Indexes\n" );
		}
	}

	/** @return string|null Absolute file path, guaranteed inside the cache root. */
	private function path_for( CacheKey $key ) {
		$file = $this->root . '/' . $key->dir() . $key->filename();

		// CacheKey already rejects traversal; this is defense in depth.
		if ( 0 !== strpos( $file, $this->root . '/' ) || false !== strpos( $file, '..' ) ) {
			return null;
		}

		return $file;
	}

	private function delete_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
