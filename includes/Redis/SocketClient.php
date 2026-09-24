<?php
namespace ReallyCache\Redis;

/**
 * Minimal pure-PHP Redis client (RESP2 over a stream socket), used when
 * the Redis service exists but the php-redis extension does not — the
 * default situation on CloudPanel and similar stacks.
 *
 * Exposes the same method surface the drop-in and Manager use on
 * \Redis (connect, auth, select, ping, get, set, setex, unlink, incrBy,
 * scan, info, setOption, close), so both work with either client
 * unchanged. Slower than the C extension, still far faster than MySQL.
 */
class SocketClient {

	/** @var resource|null */
	private $sock = null;

	/**
	 * @param string $host_or_path Hostname/IP, or a unix socket path.
	 * @param int    $port         0 means $host_or_path is a unix socket.
	 */
	public function connect( $host_or_path, $port = 0, $timeout = 1.0 ) {
		$target = $port > 0
			? 'tcp://' . $host_or_path . ':' . (int) $port
			: 'unix://' . $host_or_path;

		$errno  = 0;
		$errstr = '';
		$sock   = @stream_socket_client( $target, $errno, $errstr, (float) $timeout );
		if ( ! $sock ) {
			return false;
		}

		stream_set_timeout( $sock, 1, 0 );
		$this->sock = $sock;

		return true;
	}

	/** phpredis-compat no-op (timeouts are set on the stream directly). */
	public function setOption( $option, $value ) {
		return true;
	}

	public function auth( $password ) {
		return 'OK' === $this->cmd( 'AUTH', $password );
	}

	public function select( $database ) {
		return 'OK' === $this->cmd( 'SELECT', (string) (int) $database );
	}

	public function ping() {
		return 'PONG' === $this->cmd( 'PING' );
	}

	/** @return string|false */
	public function get( $key ) {
		return $this->cmd( 'GET', $key );
	}

	public function set( $key, $value ) {
		return 'OK' === $this->cmd( 'SET', $key, $value );
	}

	public function setex( $key, $ttl, $value ) {
		return 'OK' === $this->cmd( 'SETEX', $key, (string) (int) $ttl, $value );
	}

	/** @param string|string[] ...$keys */
	public function unlink( ...$keys ) {
		if ( isset( $keys[0] ) && is_array( $keys[0] ) ) {
			$keys = $keys[0];
		}
		if ( empty( $keys ) ) {
			return 0;
		}

		return (int) $this->cmd( 'UNLINK', ...array_map( 'strval', $keys ) );
	}

	public function incrBy( $key, $amount ) {
		return (int) $this->cmd( 'INCRBY', $key, (string) (int) $amount );
	}

	/**
	 * phpredis-style cursor iteration: pass null/0 to start; the cursor is
	 * updated by reference and reaches 0 when the scan completes.
	 *
	 * @return string[]|false
	 */
	public function scan( &$iterator, $pattern, $count = 100 ) {
		$reply = $this->cmd( 'SCAN', (string) (int) $iterator, 'MATCH', $pattern, 'COUNT', (string) (int) $count );

		if ( ! is_array( $reply ) || count( $reply ) < 2 ) {
			$iterator = 0;
			return false;
		}

		$iterator = (int) $reply[0];

		return is_array( $reply[1] ) ? $reply[1] : [];
	}

	/** @return array<string,string> */
	public function info( $section = '' ) {
		$raw = '' === $section ? $this->cmd( 'INFO' ) : $this->cmd( 'INFO', $section );
		$out = [];

		foreach ( preg_split( '/\r?\n/', (string) $raw ) as $line ) {
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$pos = strpos( $line, ':' );
			if ( false !== $pos ) {
				$out[ substr( $line, 0, $pos ) ] = substr( $line, $pos + 1 );
			}
		}

		return $out;
	}

	public function close() {
		if ( $this->sock ) {
			@fclose( $this->sock );
			$this->sock = null;
		}

		return true;
	}

	/** @return mixed RESP reply; false on nil, error or connection trouble. */
	private function cmd( ...$args ) {
		if ( ! $this->sock ) {
			return false;
		}

		$payload = '*' . count( $args ) . "\r\n";
		foreach ( $args as $arg ) {
			$arg      = (string) $arg;
			$payload .= '$' . strlen( $arg ) . "\r\n" . $arg . "\r\n";
		}

		if ( false === @fwrite( $this->sock, $payload ) ) {
			return false;
		}

		return $this->read_reply();
	}

	private function read_reply() {
		$line = @fgets( $this->sock );
		if ( false === $line || '' === $line ) {
			return false;
		}

		$type = $line[0];
		$data = rtrim( substr( $line, 1 ), "\r\n" );

		switch ( $type ) {
			case '+':
				return $data;
			case '-':
				return false;
			case ':':
				return (int) $data;
			case '$':
				$length = (int) $data;
				if ( $length < 0 ) {
					return false; // nil
				}
				$buffer    = '';
				$remaining = $length + 2; // Payload + trailing CRLF.
				while ( $remaining > 0 ) {
					$chunk = @fread( $this->sock, $remaining );
					if ( false === $chunk || '' === $chunk ) {
						return false;
					}
					$buffer    .= $chunk;
					$remaining -= strlen( $chunk );
				}
				return substr( $buffer, 0, $length );
			case '*':
				$count = (int) $data;
				if ( $count < 0 ) {
					return false;
				}
				$items = [];
				for ( $i = 0; $i < $count; $i ++ ) {
					$items[] = $this->read_reply();
				}
				return $items;
		}

		return false;
	}
}
