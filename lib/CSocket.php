<?php

/**
 * Class wrapper for socket functional
 *
 * @see http://php.net/sockets
 * @see http://php.net/stream
 *
 * @project Anizoptera CMF
 * @package system.socket
 * @version $Id: CSocket.php 2787 2011-11-16 13:24:24Z samally $
 */
abstract class CSocket
{
	/**
	 * Whether to use socket extension.
	 * Stream extension will be used otherwise.
	 */
	public static $useSocket = true;


	/**
	 * Creates a pair of connected, indistinguishable sockets
	 *
	 * @see socket_create_pair
	 * @see stream_socket_pair
	 *
	 * @throws AzaSystemException
	 *
	 * @return resource[] Array with two socket resources. Default use is: read, write.
	 */
	public static function pair()
	{
		if (self::$useSocket) {
			// On Windows we need to use AF_INET
			$domain = IS_WIN ? AF_INET : AF_UNIX;
			if (!socket_create_pair($domain, SOCK_STREAM, 0, $sockets)) {
				$errno = socket_last_error();
				$error = socket_strerror($errno);
				throw new AzaException("Can't create socket pair. Reason ($errno): $error", 1);
			}
		} else {
			// On Windows we need to use PF_INET
			$domain = IS_WIN ? STREAM_PF_INET : STREAM_PF_UNIX;
			if (!$sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, 0)) {
				throw new AzaException('Can\'t create stream socket pair.', 1);
			}
		}
		return $sockets;
	}


	/**
	 * Reads a maximum of length bytes from a socket
	 *
	 * @see socket_read
	 * @see fread
	 *
	 * @param resource $socket <p>
	 * A valid socket resource
	 * </p>
	 * @param int $length <p>
	 * The maximum number of bytes read is specified by the
	 * length parameter.
	 * </p>
	 *
	 * @return string|bool <p>
	 * Returns the data as a string on success, or FALSE on error
	 * (including if the remote host has closed the connection).
	 * Returns a zero length string ("") when there is no more data to read.
	 * </p>
	 */
	public static function read($socket, $length)
	{
		if (self::$useSocket) {
			return socket_read($socket, $length, PHP_BINARY_READ);
		} else {
			return fread($socket, $length);
		}
	}

	/**
	 * Reads a line from a socket
	 *
	 * @see socket_read
	 * @see fgets
	 *
	 * @param resource $socket <p>
	 * A valid socket resource
	 * </p>
	 * @param int $length <p>
	 * The maximum number of bytes read is specified by the
	 * length parameter. Otherwise you can use \r or \n.
	 * </p>
	 *
	 * @return string|bool <p>
	 * Returns the data as a string on success, or FALSE on error
	 * (including if the remote host has closed the connection).
	 * Returns a zero length string ("") when there is no more data to read.
	 * </p>
	 */
	public static function readLine($socket, $length)
	{
		if (self::$useSocket) {
			return socket_read($socket, $length, PHP_NORMAL_READ);
		} else {
			return fgets($socket, $length);
		}
	}


	/**
	 * Writes data to a socket (binary-safe)
	 *
	 * @see socket_write
	 * @see fwrite
	 *
	 * @param resource $socket A valid socket resource
	 * @param string   $buffer The buffer to be written.
	 * @param int      $length [optional] <p>
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
	public static function write($socket, $buffer, $length = null)
	{
		if (self::$useSocket) {
			if ($length === null) {
				return socket_write($socket, $buffer);
			}
			return socket_write($socket, $buffer, $length);
		} else {
			if ($length === null) {
				return fwrite($socket, $buffer);
			}
			return fwrite($socket, $buffer, $length);
		}
	}


	/**
	 * Closes a socket resource
	 *
	 * @see socket_close
	 * @see fclose
	 *
	 * @param resource $socket A valid socket resource
	 */
	public static function close($socket)
	{
		if (self::$useSocket) {
			socket_close($socket);
		} else {
			fclose($socket);
		}
	}
}
