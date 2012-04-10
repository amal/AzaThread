<?php

namespace Aza\Components\Socket;
use Aza\Components\Socket\Exceptions\Exception;

/**
 * Sockets functional wrapper based on sockets extension
 *
 * The fastest implementation
 *
 * @link http://php.net/sockets
 *
 * @uses sockets
 *
 * @project Anizoptera CMF
 * @package system.AzaSocket
 * @version $Id: SocketReal.php 3235 2012-04-06 10:32:20Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class SocketReal extends ASocket
{
	public function read($length = 4096, $quiet = true)
	{
		return $this->_read($length, $quiet, PHP_BINARY_READ);
	}

	public function readLine($length = 4096, $quiet = true)
	{
		return $this->_read($length, $quiet, PHP_NORMAL_READ);
	}

	/**
	 * Socket read wrapper
	 *
	 * @see read
	 * @see readLine
	 *
	 * @throws Exception
	 *
	 * @param int  $length Number of bytes to read from the socket
	 * @param bool $quiet  Whether not to throw exception on error
	 * @param int  $flag   Read flag
	 *
	 * @return string
	 */
	protected function _read($length, $quiet, $flag)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}
		elseif (false === ($buf = socket_read($sock, $length, $flag))
			&& !$quiet
			&& SOCKET_EAGAIN !== socket_last_error($sock)
		) {
			$error = self::getError($this);
			throw new Exception("Can't read '{$length}' bytes from socket {$error}.");
		}
		return $buf;
	}


	public function write($buffer, $length = null)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}
		isset($length) || $length = strlen($buffer);
		if (false === ($written = socket_write($sock, $buffer, $length))
			&& SOCKET_EAGAIN !== socket_last_error($sock)
		) {
			$error = self::getError($this);
			throw new Exception("Can't write '{$length}' bytes to the socket {$error}.");
		}
		return $written;
	}


	public function accept($nonBlock = true, $throw = false)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (false === $msgsock = @socket_accept($sock)) {
			if ($throw) {
				$error = self::getError($this);
				throw new Exception("Socket accept failed {$error}.");
			}
			return false;
		}

		$msgsock = new self($msgsock);

		$nonBlock && $msgsock->setNonBlock();

		return $msgsock;
	}


	public function close()
	{
		if (is_resource($sock = $this->resource)) {
			socket_close($sock);
		}
		$this->resource = null;
	}


	public function setNonBlock()
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_set_nonblock($sock)) {
			$error = self::getError($this);
			throw new Exception("Can't set nonblocking mode {$error}.");
		}

		return true;
	}

	public function setBlock()
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_set_block($sock)) {
			$error = self::getError($this);
			throw new Exception("Can't set blocking mode {$error}.");
		}

		return true;
	}


	public function setRecieveTimeout($sec, $usec = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $sec, 'usec' => $usec))) {
			$error = self::getError($this);
			throw new Exception("Can't set socket recieve timeout ($sec, $usec) {$error}.");
		}

		return true;
	}

	public function setSendTimeout($sec, $usec = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_set_option($sock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $sec, 'usec' => $usec))) {
			$error = self::getError($this);
			throw new Exception("Can't set socket send timeout ($sec, $usec) {$error}.");
		}

		return true;
	}


	public function setReadBuffer($buffer = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_set_option($sock, SOL_SOCKET, SO_RCVBUF, $buffer)) {
			$error = self::getError($this);
			throw new Exception("Can't set socket read buffer timeout ($buffer) {$error}.");
		}

		return true;
	}

	public function setWriteBuffer($buffer = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, $buffer)) {
			$error = self::getError($this);
			throw new Exception("Can't set socket write buffer timeout ($buffer) {$error}.");
		}

		return true;
	}


	/**
	 * Queries the remote side of the given socket which may either result in host/port
	 * or in a Unix filesystem path, dependent on its type.
	 *
	 * @see socket_getpeername
	 *
	 * @param string $addr <p>
	 * If the given socket is of type AF_INET or
	 * AF_INET6, socket_getpeername
	 * will return the peers (remote) IP address in
	 * appropriate notation (e.g. 127.0.0.1 or
	 * fe80::1) in the address
	 * parameter and, if the optional port parameter is
	 * present, also the associated port.
	 * </p>
	 * <p>
	 * If the given socket is of type AF_UNIX,
	 * socket_getpeername will return the Unix filesystem
	 * path (e.g. /var/run/daemon.sock) in the
	 * address parameter.
	 * </p>
	 *
	 * @param int $port [optional] <p>
	 * If given, this will hold the port associated to
	 * address.
	 * </p>
	 *
	 * @return bool Returns true on success or false on failure. socket_getpeername may also return
	 * false if the socket type is not any of AF_INET,
	 * AF_INET6, or AF_UNIX, in which
	 * case the last socket error code is not updated.
	 */
	public function getPeer(&$addr, &$port = null)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_getpeername($sock, $addr, $port)) {
			$error = self::getError($this);
			throw new Exception("Can't get peer name {$error}.");
		}

		return true;
	}

	/**
	 * Queries the local side of the given socket which may either result in host/port
	 * or in a Unix filesystem path, dependent on its type
	 *
	 * @see socket_getsockname
	 *
	 * @param string $addr <p>
	 * If the given socket is of type AF_INET
	 * or AF_INET6, socket_getsockname
	 * will return the local IP address in appropriate notation (e.g.
	 * 127.0.0.1 or fe80::1) in the
	 * address parameter and, if the optional
	 * port parameter is present, also the associated port.
	 * </p>
	 * <p>
	 * If the given socket is of type AF_UNIX,
	 * socket_getsockname will return the Unix filesystem
	 * path (e.g. /var/run/daemon.sock) in the
	 * address parameter.
	 * </p>
	 *
	 * @param int $port [optional] <p>
	 * If provided, this will hold the associated port.
	 * </p>
	 *
	 * @return bool Returns true on success or false on failure. socket_getsockname may also return
	 * false if the socket type is not any of AF_INET,
	 * AF_INET6, or AF_UNIX, in which
	 * case the last socket error code is not updated.
	 */
	public function getLocal(&$addr, &$port = null)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!socket_getsockname($sock, $addr, $port)) {
			$error = self::getError($this);
			throw new Exception("Can't get socket name {$error}.");
		}

		return true;
	}



	public function getInfo()
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		$result = array();

		try {
			if (@$this->getLocal($addr, $port)) {
				$result['local'] = array($addr, $port);
			}
		} catch (Exception $e) {}

		try {
			if (@$this->getPeer($addr, $port)) {
				$result['peer'] = array($addr, $port);
			}
		} catch (Exception $e) {}

		$constants = array(
			'SO_DEBUG',
			'SO_BROADCAST',
			'SO_REUSEADDR',
			'SO_KEEPALIVE',
			'SO_LINGER',
			'SO_SNDBUF',
			'SO_RCVBUF',
			'SO_ERROR',
			'SO_TYPE',
			'SO_DONTROUTE',
			'SO_RCVLOWAT',
			'SO_RCVTIMEO',
			'SO_SNDTIMEO',
			'SO_SNDLOWAT',
			'TCP_NODELAY',
		);
		foreach ($constants as $const) {
			$result[$const] = socket_get_option($sock, SOL_SOCKET, constant($const));
		}

		return $result;
	}


	/**
	 * Returns prepared socket error string
	 *
	 * @throws Exception
	 *
	 * @param SocketReal|resource $sock
	 *
	 * @return string
	 */
	protected static function getError($sock = null)
	{
		$sock = $sock ? ($sock instanceof self ? $sock->resource : $sock) : null;
		$errno = socket_last_error($sock);
		$error = socket_strerror($errno);
		socket_clear_error($sock);
		return "#{$errno}: {$error}";
	}

	/**
	 * Creates a pair of connected, indistinguishable sockets
	 *
	 * @throws Exception
	 *
	 * @return self[] Array with two socket resources. Default use is: read, write.
	 */
	public static function pair()
	{
		// On Windows we need to use AF_INET
		$domain = IS_WIN ? AF_INET : AF_UNIX;
		if (!socket_create_pair($domain, SOCK_STREAM, 0, $sockets)) {
			$error = self::getError();
			throw new Exception("Can't create socket pair {$error}.");
		}
		$sockets[0] = new self($sockets[0]);
		$sockets[1] = new self($sockets[1]);
		return $sockets;
	}

	/**
	 * Creates socket server
	 *
	 * @throws Exception
	 *
	 * @see socket_create
	 * @see socket_bind
	 * @see socket_listen
	 *
	 * @param int    $domain
	 * @param int    $type
	 * @param int    $protocol
	 * @param string $address
	 * @param int    $port
	 * @param bool   $reuse
	 *
	 * @return self
	 */
	public static function server($domain, $type, $protocol, $address, $port = null, $reuse = true)
	{
		$sock = self::create($domain, $type, $protocol);

		if ($reuse) {
			if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
				$error = self::getError($sock);
				throw new Exception("Can't set option REUSEADDR to socket {$error}.");
			}

			if (Socket::$reusePort && !socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
				$error = self::getError($sock);
				throw new Exception("Can't set option REUSEPORT to socket {$error}.");
			}
		}

		if (!@socket_bind($sock, $address, $port)) {
			$error = self::getError($sock);
			$path  = $port ? "$address:$port" : $address;
			throw new Exception("Can't bind socket '$path' ({$error}).");
		}

		if (!socket_listen($sock, SOMAXCONN)) {
			$error = self::getError($sock);
			$path  = null !== $port ? "$address:$port" : $address;
			throw new Exception("Can't listen socket '$path' ({$error}).");
		}

		return new self($sock);
	}

	/**
	 * Opens socket connection
	 *
	 * @throws Exception
	 *
	 * @see socket_create
	 * @see socket_connect
	 *
	 * @param int    $domain
	 * @param int    $type
	 * @param int    $protocol
	 * @param string $address
	 * @param int    $port
	 * @param bool   $nonBlock Whether to enable nonblocking mode
	 *
	 * @return self
	 */
	public static function client($domain, $type, $protocol, $address, $port = null, $nonBlock = true)
	{
		$sock = self::create($domain, $type, $protocol);

		$nonBlock && socket_set_nonblock($sock);

		if (!@socket_connect($sock, $address, $port)
			// If the socket is non-blocking then FALSE returned
			// with an error "Operation now in progress".
			&& socket_last_error($sock) !== SOCKET_EINPROGRESS
		) {
			$error = self::getError($sock);
			$path  = $port ? "$address:$port" : $address;
			throw new Exception("Can't connect socket '$path' ({$error}).");
		}

		return new self($sock);
	}

	/**
	 * Creates a socket
	 *
	 * @throws Exception
	 *
	 * @see socket_create
	 *
	 * @param int $domain
	 * @param int $type
	 * @param int $protocol
	 *
	 * @return resource
	 */
	protected static function create($domain, $type, $protocol)
	{
		if (!$sock = socket_create($domain, $type, $protocol)) {
			$error = self::getError();
			throw new Exception("Can't create socket {$error} [$domain, $type, $protocol].");
		}
		return $sock;
	}

	/**
	 * Runs the select() system call on the given arrays of sockets with a specified timeout
	 *
	 * @see socket_select
	 *
	 * @param resource[]|null $read
	 * @param resource[]|null $write
	 * @param resource[]|null $except
	 * @param int             $tv_sec
	 * @param int             $tv_usec
	 *
	 * @return int
	 */
	public static function select(&$read, &$write = null, &$except = null, $tv_sec = 0, $tv_usec = 0)
	{
		if (false === $res = socket_select($read, $write, $except, $tv_sec, $tv_usec)) {
			$error = self::getError();
			throw new Exception("socket_select() failed {$error}.");
		}
		return $res;
	}
}
