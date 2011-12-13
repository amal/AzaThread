<?php

/**
 * System V shared memory wrapper.
 *
 * @link http://php.net/sem
 *
 * @uses sysvshm
 *
 * @project Anizoptera CMF
 * @package system.ipc
 * @version $Id: CIpcSharedMemory.php 2885 2011-12-11 21:34:47Z samally $
 */
class CIpcSharedMemory
{
	const MEMORY_SIZE = 524288;
	const PERMISSIONS = 0777;


	/**
	 * A numeric shared memory segment ID
	 *
	 * @var int
	 */
	protected $key;

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
	 * @var resource
	 */
	protected $shmId;

	/**
	 * Cache for names hashes
	 */
	protected static $namesCache = array();


	/**
	 * Initializes shared memory with file path and project identifier.
	 *
	 * @param string $file		Path to an accessible file.
	 * @param string $proj		Project identifier. This must be a one character string.
	 * @param int $memorySize	The memory size. If not provided, default to the sysvshm.init_mem in the php.ini, otherwise 10000 bytes.
	 * @param int $permissions	The optional permission bits.
	 *
	 * @return CIpcSharedMemory
	 */
	public static function instance($file, $proj = 'm', $memorySize = self::MEMORY_SIZE,
		$permissions = self::PERMISSIONS
	) {
		$key = ftok($file, $proj);
		if ($key === -1) {
			throw new AzaException("Can't initialize System V shared memory with file [$file] and project [$proj]", 1);
		}
		return new self($key, $memorySize, $permissions);
	}


	/**
	 * Creates or open a shared memory segment
	 *
	 * @param int $key			A numeric shared memory segment ID
	 * @param int $memorySize	The memory size. If not provided, default to the sysvshm.init_mem in the php.ini, otherwise 10000 bytes.
	 * @param int $permissions	The optional permission bits.
	 */
	public function __construct($key, $memorySize = self::MEMORY_SIZE, $permissions = self::PERMISSIONS)
	{
		if (!function_exists('shm_attach')) {
			throw new AzaException('You need php compiled with --enable-sysvshm option to work with System V shared memory', 1);
		}
		if (!is_numeric($key)) {
			$key = sprintf('%u', crc32($key));
		}
		$this->key         = (int)$key;
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
	 */
	protected function init()
	{
		if (null === $this->shmId) {
			$this->shmId = shm_attach($this->key, $this->memorySize, $this->permissions);
		}
	}

	/**
	 * Returns variable name key
	 *
	 * @param string $name The variable name.
	 *
	 * @return int
	 */
	protected function name($name)
	{
		if (!isset(self::$namesCache[$name])) {
			self::$namesCache[$name] = sprintf('%u', crc32($name));
		}
		return self::$namesCache[$name];
	}


	/**
	 * Disconnects from shared memory segment
	 */
	public function detach()
	{
		if (null !== $this->shmId) {
			shm_detach($this->shmId);
			$this->shmId = null;
		}
	}

	/**
	 * Removes shared memory from Unix systems
	 *
	 * @throws AzaException on fail
	 *
	 * @return bool Returns true on success or false on failure.
	 */
	public function destroy()
	{
		$this->init();
		if (!shm_remove($this->shmId)) {
			throw new AzaException("Can't destroy shared memory segment", 1);
		}
		$this->detach();
	}


	/**
	 * Returns a variable from shared memory
	 *
	 * @param string $name The variable name.
	 *
	 * @return mixed|null The variable with the given key or NULL if it's not set
	 */
	public function get($name)
	{
		$this->init();
		return $this->_get($this->name($name));
	}

	/**
	 * Returns a variable and deletes it from shared memory
	 *
	 * @param string $name The variable name.
	 *
	 * @return mixed the variable with the given key or null if not set
	 */
	public function getOnce($name)
	{
		$this->init();
		$key = $this->name($name);
		$value = $this->_get($key);
		$this->_delete($key);
		return $value;
	}

	/**
	 * Returns whether variable is set
	 *
	 * @param string $name The variable name.
	 *
	 * @return bool
	 */
	public function containsKey($name)
	{
		$this->init();
		return false !== $this->_get($this->name($name), false);
	}

	/**
	 * Inserts or updates a variable in shared memory.
	 *
	 * @throws AzaException on fail
	 *
	 * @param string $name	The variable name.
	 * @param mixed  $value	The variable. All variable-types (except resources) are supported.
	 */
	public function set($name, $value)
	{
		// shm_get_var return false in case of non-existed key.
		// We need a wrapper to store FALSE values
		if (false === $value) {
			$value = (object)array('__shmFalseWrapper' => true);
		}

		$this->init();
		if (!shm_put_var($this->shmId, $key = $this->name($name), $value)) {
			throw new AzaException("Can't set var '$key' ($name) into shared memory", 1);
		}
	}

	/**
	 * Removes a variable from shared memory
	 *
	 * @throws AzaException on fail
	 *
	 * @param string $name The variable name.
	 */
	public function delete($name)
	{
		$this->init();
		$this->_delete($this->name($name));
	}


	/**
	 * Returns a variable from shared memory
	 *
	 * @param int  $key     The variable key.
	 * @param bool $process Whether to process variable
	 *
	 * @return mixed|null The variable with the given key or NULL if it's not set
	 */
	protected function _get($key, $process = true)
	{
		$value = @shm_get_var($this->shmId, $key);
		if ($process) {
			return $value === false ? null : (isset($value->__shmFalseWrapper) ? false : $value);
		}
		return $value;
	}

	/**
	 * Removes a variable from shared memory
	 *
	 * @throws AzaException on fail
	 *
	 * @param int $key The variable key.
	 *
	 * @return bool
	 */
	protected function _delete($key)
	{
		if (!shm_remove_var($this->shmId, $key)) {
			throw new AzaException("Can't delete var '$key' from shared memory", 2);
		}
	}
}
