<?php

/**
 * Shared memory segments wrapper.
 *
 * For debugging purposes:
 *  use `ipcs` to view current memory
 *  use `ipcrm -m {shmid}` to remove
 *  on some systems use `ipcclean` to clean up unused memory if you
 *  don't want to do it by hand
 *
 * @link http://php.net/shmop
 *
 * @uses shmop
 *
 * @project Anizoptera CMF
 * @package system.ipc
 */
class CIpcShmop
{
	/**
	 * Access mode (sets SHM_RDONLY for shmat) use this flag
	 * when you need to open an existing shared memory segment
	 * for read only.
	 *
	 * @see shmop_open
	 */
	const MODE_READ = 'a';

	/**
	 * Create mode (sets IPC_CREATE) use this flag when you need
	 * to create a new shared memory segment or if a segment with
	 * the same key exists, try to open it for read and write
	 *
	 * @see shmop_open
	 */
	const MODE_CREATE_READ_WRITE = 'c';

	/**
	 * Mode for read and write access.
	 *
	 * @see shmop_open
	 */
	const MODE_READ_WRITE = 'w';

	/**
	 * Exclusive create mode (sets IPC_CREATE|IPC_EXCL) use this
	 * flag when you want to create a new shared memory segment
	 * but if one already exists with the same flag, fail.
	 * This is useful for security purposes, using this you can
	 * prevent race condition exploits.
	 *
	 * @see shmop_open
	 */
	const MODE_CREATE_READ_WRITE_EXCL = 'n';

	/**
	 * Default memory size
	 */
	const MEMORY_SIZE = 524288;

	/**
	 * Default permissions
	 */
	const PERMISSIONS = 0777;


	/**
	 * Whether to use igbinary serialization
	 */
	public static $useIgbinary = false;


	/**
	 * A numeric shared memory segment ID
	 *
	 * @var int
	 */
	protected $key;

	/**
	 * Shared memory flags (see self::MODE_* constants)
	 *
	 * @var string
	 */
	protected $mode;

	/**
	 * The memory size. If not provided, default to the sysvshm.init_mem in the php.ini, otherwise 10000 bytes.
	 *
	 * @var int
	 */
	protected $memorySize;

	/**
	 * The optional permission bits. Default to 0666.
	 *
	 * @var int
	 */
	protected $permissions;

	/**
	 * A shared memory segment identifier.
	 *
	 * @var int
	 */
	protected $shmId;


	/**
	 * Initializes shared memory with file path and project identifier.
	 *
	 * @see shmop_open
	 *
	 * @param string $file        Path to an accessible file.
	 * @param string $proj        Project identifier. This must be a one character string.
	 * @param string $mode        Shared memory flags (see self::MODE_* constants)
	 * @param int    $memorySize  The memory size. If not provided, default to the sysvshm.init_mem in the php.ini, otherwise 10000 bytes.
	 * @param int    $permissions The optional permission bits.
	 *
	 * @return CIpcShmop
	 */
	public static function instance($file, $proj = 'm', $mode = self::MODE_CREATE_READ_WRITE,
		$memorySize = self::MEMORY_SIZE, $permissions = self::PERMISSIONS
	) {
		$key = ftok($file, $proj);
		if ($key === -1) {
			throw new AzaException("Can't initialize shared memory with file [$file] and project [$proj]", 1);
		}
		return new self($key, $mode, $memorySize, $permissions);
	}


	/**
	 * Creates or open a shared memory segment
	 *
	 * @see shmop_open
	 *
	 * @param int    $key         A numeric shared memory segment ID
	 * @param string $mode        Shared memory flags (see self::MODE_* constants)
	 * @param int    $memorySize  The memory size. If not provided, default to the sysvshm.init_mem in the php.ini, otherwise 10000 bytes.
	 * @param int    $permissions The optional permission bits. Default to 0666.
	 */
	public function __construct($key, $mode = self::MODE_CREATE_READ_WRITE,
		$memorySize = self::MEMORY_SIZE, $permissions = self::PERMISSIONS
	) {
		if (!function_exists('shmop_open')) {
			throw new AzaException('You need php compiled with --enable-shmop option to work with shared memory', 1);
		}
		if (!is_numeric($key)) {
			$key = sprintf('%u', crc32($key));
		}
		$this->key         = (int)$key;
		$this->mode        = $mode;
		$this->memorySize  = $memorySize;
		$this->permissions = $permissions;
	}

	/**
	 * Disconnects from shared memory segment
	 */
	public function __destruct()
	{
		$this->detach();
	}


	/**
	 * Initializes a shared memory segment
	 *
	 * @see shmop_open
	 */
	protected function init()
	{
		if (null === $this->shmId) {
			if (!$this->shmId = shmop_open($this->key, $this->mode, $this->permissions, $this->memorySize)) {
				$msg = sprintf(
					"Can't open shared memory segment (key: %d, mode: %s, permissions: 0%o, size: %d)",
					$this->key, $this->mode, $this->permissions, $this->memorySize
				);
				throw new AzaException($msg, 1);
			}
		}
	}


	/**
	 * Disconnects from shared memory segment
	 *
	 * @see shmop_close
	 */
	public function detach()
	{
		if (null !== $this->shmId) {
			shmop_close($this->shmId);
			$this->shmId = null;
		}
	}

	/**
	 * Removes shared memory from Unix systems
	 *
	 * @see shmop_delete
	 *
	 * @throws AzaException on fail
	 *
	 * @return bool Returns true on success or false on failure.
	 */
	public function destroy()
	{
		$this->init();
		if (!shmop_delete($this->shmId)) {
			$msg = sprintf("Can't destroy shared memory segment (shmId: 0x%s)", $this->shmId);
			throw new AzaException($msg, 1);
		}
		$this->detach();
	}


	/**
	 * Returns size of the shared memory block.
	 *
	 * @see shmop_size
	 *
	 * @return int The number of bytes the shared memory block occupies.
	 */
	public function size()
	{
		$this->init();
		return shmop_size($this->shmId);
	}


	/**
	 * Returns a variable from shared memory
	 *
	 * @see shmop_read
	 *
	 * @throws AzaException on fail
	 *
	 * @param int $offset Offset from which to start reading
	 * @param int $length The number of bytes to read. Will read all memory if zero.
	 *
	 * @return mixed Data
	 */
	public function read($offset = 0, $length = 0)
	{
		$this->init();
		$res = shmop_read($this->shmId, $offset, $length);
		if (false === $res) {
			$msg = sprintf(
				"Can't read data from shared memory (shmId: 0x%s, offset: %d, length: %d)",
				$this->shmId, $offset, $length
			);
			throw new AzaException($msg, 1);
		}
		// Check for nulls
		if ("\0" === $res[strlen($res)-1]) {
			$res = rtrim($res, "\0");
		}
		if ("\0" === $res[0]) {
			$res = ltrim($res, "\0");
		}
		// Serialized data
		if ("\2" === $res[0] && "\3" === $res[strlen($res)-1]) {
			$res = substr($res, 1, -1);
			$res = self::$useIgbinary ? igbinary_unserialize($res) : unserialize($res);
		}
		return $res;
	}

	/**
	 * Inserts or updates a variable in shared memory.
	 *
	 * @see shmop_write
	 *
	 * @throws AzaException on fail
	 *
	 * @param mixed $data   A data to write into shared memory block.
	 * @param int   $offset Specifies where to start writing data inside the shared memory segment.
	 *
	 * @return int The size of the written data in bytes
	 */
	public function write($data, $offset = 0)
	{
		$this->init();
		if (!is_scalar($data)) {
			$data = self::$useIgbinary ? igbinary_serialize($data) : serialize($data);
			$data = "\2{$data}\3";
		}
		if (!$res = shmop_write($this->shmId, $data, $offset)) {
			$msg = sprintf(
				"Can't write data to shared memory (shmId: 0x%s, offset: %d, data length: %d)",
				$this->shmId, $offset, strlen($data)
			);
			throw new AzaException($msg, 1);
		}
		return $res;
	}
}

CIpcShmop::$useIgbinary = function_exists('igbinary_serialize');
