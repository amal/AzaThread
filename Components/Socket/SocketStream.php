<?php

namespace Aza\Components\Socket;
use Aza\Components\Socket\Exceptions\Exception;

/**
 * Sockets functional wrapper based on stream extension
 *
 * WARNING!
 * Stream implementation is slower on 9-16%
 *
 * @link http://php.net/stream
 *
 * @uses stream
 *
 * @project Anizoptera CMF
 * @package system.AzaSocket
 * @version $Id: SocketStream.php 3223 2012-03-30 20:12:22Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class SocketStream extends ASocket
{
	public function read($length = 4096, $quiet = true)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}
		return fread($sock, $length);
	}

	public function readLine($length = 4096, $quiet = true)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}
		return fgets($sock, $length);
	}


	public function write($buffer, $length = null)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}
		return isset($length)
			? fwrite($sock, $buffer, $length)
			: fwrite($sock, $buffer);
	}


	public function accept($nonBlock = true, $throw = false)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!$msgsock = @stream_socket_accept($sock)) {
			if ($throw) {
				throw new Exception("Socket accept failed.");
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
			fclose($sock);
		}
		$this->resource = null;
	}


	public function setNonBlock()
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!stream_set_blocking($sock, 0)) {
			throw new Exception("Couldn't set nonblocking mode.");
		}

		return true;
	}

	public function setBlock()
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!stream_set_blocking($sock, 1)) {
			throw new Exception("Couldn't set blocking mode.");
		}

		return true;
	}


	public function setRecieveTimeout($sec, $usec = 0)
	{
		return $this->setSendTimeout($sec, $usec);
	}

	public function setSendTimeout($sec, $usec = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		elseif (!stream_set_timeout($sock, $sec, $usec)) {
			throw new Exception("Can't set socket timeout.");
		}

		return true;
	}


	public function setReadBuffer($buffer = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		// Function is available only in PHP >= 5.3.3
		elseif (!function_exists('stream_set_read_buffer')) {
			return true;
		}

		/** @noinspection PhpUndefinedFunctionInspection */
		stream_set_read_buffer($sock, $buffer);

		return true;
	}

	public function setWriteBuffer($buffer = 0)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		stream_set_write_buffer($sock, $buffer);

		return true;
	}


	public function getPeer(&$addr, &$port = null)
	{
		return $this->_getName(false, $addr, $port);
	}

	public function getLocal(&$addr, &$port = null)
	{
		return $this->_getName(false, $addr, $port);
	}

	/**
	 * Retrieve the name of the local or remote sockets
	 *
	 * @throws Exception
	 *
	 * @see stream_socket_get_name
	 *
	 * @param bool   $want_peer
	 * @param string $addr
	 * @param int    $port
	 *
	 * @return bool
	 */
	protected function _getName($want_peer, &$addr, &$port = null)
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}

		$port = '';
		$addr = stream_socket_get_name($sock, $want_peer);

		if (preg_match('/:(\d+)$/DSX', $addr, $m)) {
			$port = $m[1];
			$addr = substr($addr, 0, -(strlen($port)+1));
		}

		return true;
	}


	public function getInfo()
	{
		if (!$sock = $this->resource) {
			throw new Exception("Invalid socket resource");
		}
		return stream_get_meta_data($sock);
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
		// On Windows we need to use PF_INET
		$domain = IS_WIN ? STREAM_PF_INET : STREAM_PF_UNIX;
		if (!$sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, 0)) {
			throw new Exception("Can't create stream socket pair.");
		}
		$sockets[0] = new self($sockets[0]);
		$sockets[1] = new self($sockets[1]);
		return $sockets;
	}

	/**
	 * Creates socket server via stream extension
	 *
	 * @see stream_socket_server
	 *
	 * @throws Exception
	 *
	 * @param string $addr Server listening address
	 *
	 * @return self
	 */
	public static function server($addr)
	{
		$flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

		if (!$sock = @stream_socket_server($addr, $errno, $error, $flags)) {
			throw new Exception(
				"Can't create socket server (via stream) \"$addr\" (#{$errno}: {$error}).",
				2
			);
		}

		return new self($sock);
	}

	/**
	 * Opens socket connection
	 *
	 * @see stream_socket_client
	 *
	 * @throws Exception
	 *
	 * @param string $addr     Address to the socket to connect to.
	 * @param bool   $nonBlock Whether to enable nonblocking mode
	 *
	 * @return self
	 */
	public static function client($addr, $nonBlock = true)
	{
		if (!$sock = @stream_socket_client($addr, $errno, $error)) {
			throw new Exception(
				"Can't create socket client (via stream) \"$addr\" (#{$errno}: {$error}).",
				2
			);
		}
		$sock = new self($sock);
		$nonBlock && $sock->setNonBlock();
		return $sock;
	}

	/**
	 * Runs the select() system call on the given arrays of sockets with a specified timeout
	 *
	 * @see stream_select
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
		if (false === $res = stream_select($read, $write, $except, $tv_sec, $tv_usec)) {
			throw new Exception("stream_select() failed.");
		}
		return $res;
	}
}
