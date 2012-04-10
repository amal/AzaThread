<?php

namespace Aza\Components\Socket;
use Aza\Components\Socket\Exceptions\Exception;

/**
 * Socket abstraction
 *
 * @project Anizoptera CMF
 * @package system.AzaSocket
 * @version $Id: ASocket.php 3235 2012-04-06 10:32:20Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
abstract class ASocket
{
	/**
	 * Unique socket IDs counter
	 *
	 * @var int
	 */
	private static $counter = 0;

	/**
	 * Unique (for current process) socket id
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Socket resource
	 *
	 * @var resource
	 */
	public $resource;

	/**
	 * Write buffer
	 *
	 * @var string
	 */
	public $buffer;

	/**
	 * Maximum write buffer size in bytes
	 */
	public $maxBufferLength = 31457280; // 30 MB



	/**
	 * Socket constructor
	 *
	 * @param resource $resource
	 */
	public function __construct($resource)
	{
		if (!is_resource($resource)) {
			throw new Exception("Invalid socket resource");
		}
		$this->resource = $resource;
		$this->id = ++self::$counter;
	}

	/**
	 * Cleanup
	 */
	public function __destruct()
	{
		$this->close();
	}



	/**
	 * Reads a maximum of length bytes from a socket
	 *
	 * @throws Exception
	 *
	 * @param int $length <p>
	 * The maximum number of bytes read is specified by the
	 * length parameter.
	 * </p>
	 * @param bool $quiet <p>
	 * Not to throw exception on error.
	 * </p>
	 *
	 * @return string|bool <p>
	 * Returns the data as a string on success, or FALSE on error
	 * (including if the remote host has closed the connection).
	 * Returns a zero length string ("") when there is no more data to read.
	 * </p>
	 */
	abstract public function read($length = 4096, $quiet = true);

	/**
	 * Reads a line from a socket
	 *
	 * @throws Exception
	 *
	 * @param int $length <p>
	 * The maximum number of bytes read is specified by the
	 * length parameter. Otherwise you can use \r or \n.
	 * </p>
	 * @param bool $quiet <p>
	 * Not to throw exception on error.
	 * </p>
	 *
	 * @return string|bool <p>
	 * Returns the data as a string on success, or FALSE on error
	 * (including if the remote host has closed the connection).
	 * Returns a zero length string ("") when there is no more data to read.
	 * </p>
	 */
	abstract public function readLine($length = 4096, $quiet = true);


	/**
	 * Writes data to a socket (binary-safe)
	 *
	 * @throws Exception
	 *
	 * @param string $buffer <p>
	 * The buffer to be written.
	 * </p>
	 * @param int $length [optional] <p>
	 * The optional parameter length can specify an alternate
	 * length of bytes written to the socket. If this length
	 * is greater then the buffer length, it is silently
	 * truncated to the length of the buffer.
	 * </p>
	 *
	 * @return int|bool <p>
	 * The number of bytes successfully written to the socket or FALSE
	 * for failure. It is perfectly valid for socket_write to return zero
	 * which means no bytes have been written.
	 * </p>
	 */
	abstract public function write($buffer, $length = null);

	/**
	 * Writes data to a socket (binary-safe). Tries to write full data.
	 *
	 * @throws Exception
	 *
	 * @param string $buffer <p>
	 * The buffer to be written.
	 * </p>
	 *
	 * @return bool <p>
	 * TRUE if no data is left in write buffer FALSE otherwise.
	 * </p>
	 */
	public function writeFull($buffer = null)
	{
		$buffer = $this->buffer . $buffer;
		$length = strlen($buffer);
		$written = $this->write($buffer, $length);
		if ($written < $length) {
			$this->buffer = substr($buffer, $written);
			if ($this->maxBufferLength < $buffer = strlen($this->buffer)) {
				$limit  = $this->maxBufferLength;
				throw new Exception(
					"Socket instance write buffer size is exceeded ({$limit} bytes"
					." limit, {$buffer} bytes in buffer)."
				);
			}
			return false;
		}
		return true;
	}


	/**
	 * Returns if there is data in write buffer
	 *
	 * @return bool
	 */
	public function hasBuffer()
	{
		return (bool)$this->buffer;
	}

	/**
	 * Cleans write buffer
	 */
	public function cleanBuffer()
	{
		$this->buffer = null;
	}


	/**
	 * Accepts a connection on a socket
	 *
	 * @throws Exception
	 *
	 * @param bool $nonBlock Whether to enable nonblocking mode
	 * @param bool $throw    Whether to throw exeption on error
	 *
	 * @return self|bool Returns new socket instance or FALSE on failure
	 */
	abstract public function accept($nonBlock = true, $throw = false);


	/**
	 * Closes a socket resource
	 */
	abstract public function close();


	/**
	 * Sets nonblocking mode for socket
	 *
	 * @throws Exception
	 *
	 * @return bool
	 */
	abstract public function setNonBlock();

	/**
	 * Sets blocking mode for socket
	 *
	 * @throws Exception
	 *
	 * @return bool
	 */
	abstract public function setBlock();


	/**
	 * Sets receive timeout period on a socket
	 *
	 * @throws Exception
	 *
	 * @param int $sec
	 * @param int $usec
	 *
	 * @return bool
	 */
	abstract public function setRecieveTimeout($sec, $usec = 0);

	/**
	 * Sets send timeout period on a socket
	 *
	 * @throws Exception
	 *
	 * @param int $sec
	 * @param int $usec
	 *
	 * @return bool
	 */
	abstract public function setSendTimeout($sec, $usec = 0);


	/**
	 * Sets size of read buffer on the socket
	 *
	 * @throws Exception
	 *
	 * @param int $buffer Size in bytes
	 *
	 * @return bool
	 */
	abstract public function setReadBuffer($buffer = 0);

	/**
	 * Sets size of write buffer on the socket
	 *
	 * @throws Exception
	 *
	 * @param int $buffer Size in bytes
	 *
	 * @return bool
	 */
	abstract public function setWriteBuffer($buffer = 0);


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
	abstract public function getPeer(&$addr, &$port = null);

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
	abstract public function getLocal(&$addr, &$port = null);


	/**
	 * Debugging method. Returns maximum information about socket
	 *
	 * @throws Exception
	 *
	 * @return array
	 */
	abstract public function getInfo();
}
