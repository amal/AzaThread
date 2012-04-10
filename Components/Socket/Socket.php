<?php

namespace Aza\Components\Socket;
use Aza\Components\Socket\Exceptions\Exception;


/**
 * Socket functional helper
 *
 * @link http://php.net/sockets
 * @link http://php.net/stream
 *
 * @uses sockets
 * @uses stream
 *
 * @project Anizoptera CMF
 * @package system.AzaSocket
 * @version $Id: Socket.php 3235 2012-04-06 10:32:20Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
abstract class Socket
{
	const TYPE_TCP    = 0;
	const TYPE_SOCKET = 1;


	/**
	 * Whether to use socket extension.
	 * Stream extension will be used otherwise.
	 */
	public static $useSockets = true;

	/**
	 * Whether re-using of listener ports across multiple
	 * processes is available.
	 */
	public static $reusePort = false;



	/**
	 * Creates Unix-socket server
	 *
	 * @throws Exception
	 *
	 * @param string $path     Local socket path
	 * @param bool   $group    A group name or number
	 * @param bool   $user     A user name or number
	 * @param bool   $nonBlock Set nonblocking mode to socket
	 * @param int    $chmod    File access mode
	 *
	 * @return ASocket
	 */
	public static function serverUnix($path, $group = false, $user = false, $nonBlock = true, $chmod = 0770)
	{
		if ('sock' !== pathinfo($path, PATHINFO_EXTENSION)) {
			throw new Exception("Unix-socket '{$path}' must has '.sock' extension.");
		}

		if (file_exists($path)) {
			unlink($path);
		}

		$socket = self::$useSockets
				? SocketReal::server(AF_UNIX, SOCK_STREAM, 0, $path)
				: SocketStream::server('unix://' . $path);

		$nonBlock && $socket->setNonBlock();

		if ($chmod && !chmod($path, $chmod)) {
			unlink($path);
			throw new Exception(sprintf("chmod() to '%o' failed on unix-socket '%s'", $chmod, $path));
		}

		if (false !== $group && !@chgrp($path, $group)) {
			unlink($path);
			throw new Exception("chgrp() to '{$group}' failed on unix-socket '{$path}'");
		}

		if (false !== $group && !@chown($path, $user)) {
			unlink($path);
			throw new Exception("chown() to '{$user}' failed on unix-socket '{$path}'");
		}

		return $socket;
	}

	/**
	 * Creates TCP-socket server
	 *
	 * @throws Exception
	 *
	 * @param string $addr     Addresses to bind
	 * @param int    $port     Port to bind
	 * @param bool   $reuse    Whether to reuse address and port of connection
	 * @param bool   $nonBlock Set nonblocking mode to socket
	 *
	 * @return ASocket
	 */
	public static function server($addr, $port = 0, $reuse = true, $nonBlock = true)
	{
		$socket = self::$useSockets
				? SocketReal::server(AF_INET, SOCK_STREAM, SOL_TCP, $addr, $port, $reuse)
				: SocketStream::server("tcp://{$addr}:{$port}");

		$nonBlock && $socket->setNonBlock();

		return $socket;
	}


	/**
	 * Opens socket connection
	 *
	 * @see stream_socket_client
	 *
	 * @throws Exception
	 *
	 * @param string $path     Local socket path
	 * @param bool   $nonBlock Whether to enable nonblocking mode
	 *
	 * @return ASocket
	 */
	public static function clientUnix($path, $nonBlock = true)
	{
		return self::$useSockets
				? SocketReal::client(AF_UNIX, SOCK_STREAM, 0, $path, null, $nonBlock)
				: SocketStream::client('unix://' . $path, $nonBlock);
	}

	/**
	 * Opens socket connection
	 *
	 * @see stream_socket_client
	 *
	 * @throws Exception
	 *
	 * @param string $addr     Address to connect
	 * @param int    $port     Port
	 * @param bool   $nonBlock Whether to enable nonblocking mode
	 *
	 * @return ASocket
	 */
	public static function client($addr, $port = 0, $nonBlock = true)
	{
		return self::$useSockets
				? SocketReal::client(AF_INET, SOCK_STREAM, SOL_TCP, $addr, $port, $nonBlock)
				: SocketStream::client("{$addr}:{$port}", $nonBlock);
	}


	/**
	 * Creates a pair of connected, indistinguishable sockets
	 *
	 * @see socket_create_pair
	 * @see stream_socket_pair
	 *
	 * @throws Exception
	 *
	 * @param bool $nonBlock
	 *
	 * @return ASocket[] Array with two socket instances.
	 * Default use is: read/write or master/worker.
	 */
	public static function pair($nonBlock = true)
	{
		$sockets = self::$useSockets
				? SocketReal::pair()
				: SocketStream::pair();

		if ($nonBlock) {
			$sockets[0]->setNonBlock();
			$sockets[1]->setNonBlock();
		}

		return $sockets;
	}


	/**
	 * Runs the select() system call on the given arrays of sockets with a specified timeout
	 *
	 * @see socket_select
	 * @see stream_select
	 *
	 * @param ASocket[] $read
	 * @param ASocket[] $write
	 * @param ASocket[] $except
	 * @param int       $tv_sec
	 * @param int       $tv_usec
	 *
	 * @return int
	 */
	public static function select(&$read, &$write = null, &$except = null, $tv_sec = null, $tv_usec = 0)
	{
		// Preparations
		$arrays = array($read, $write, $except);
		$o_arrays = $arrays;
		foreach ($arrays as &$arr) {
			if ($arr) {
				/** @var $s ASocket */
				foreach ($arr as &$s) {
					$s = $s->resource;
				}
			} else {
				$arr = null;
			}
		}
		unset($arr, $s);
		$_arrays = $arrays;

		// Socket select
		$num = self::$useSockets
				? SocketReal::select($arrays[0], $arrays[1], $arrays[2], $tv_sec, $tv_usec)
				: SocketStream::select($arrays[0], $arrays[1], $arrays[2], $tv_sec, $tv_usec);

		// Results processing
		if ($num) {
			foreach ($arrays as $k => &$selected) {
				if ($selected) {
					$_selected = array();
					$haystack = $_arrays[$k];
					$original = $o_arrays[$k];
					foreach ($selected as $needle) {
						$key = array_search($needle, $haystack, true);
						$_selected[] = $original[$key];
					}
					$selected  = $_selected;
				}
			}
			list($read, $write, $except) = $arrays;
		} else {
			$read = $write = $except = array();
		}

		return $num;
	}
}


//Socket::$useSockets = version_compare(PHP_VERSION, '5.3.1', '>=');

// Currently re-using of listener ports across multiple processes is available
// only in BSD flavour operating systems via SO_REUSEPORT socket option
Socket::$reusePort = false !== stripos(php_uname('s'), 'BSD');
defined('SO_REUSEPORT') || define('SO_REUSEPORT', 0x200);
