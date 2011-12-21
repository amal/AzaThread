<?php

/**
 * System V semaphore wrapper
 *
 * @link http://php.net/sem
 *
 * @uses sysvsem
 *
 * @project Anizoptera CMF
 * @package system.ipc
 */
class CIpcSemaphore
{
	const PERMISSIONS = 0777;


	/**
	 * A numeric semaphore memory segment ID.
	 *
	 * @var int
	 */
	protected $key;

	/**
	 * The number of processes that can acquire the semaphore simultaneously is set to max_acquire.
	 *
	 * @var int
	 */
	protected $maxAcquire;

	/**
	 * The semaphore permissions. Actually this value is set only if the process finds it is the only process currently attached to the semaphore.
	 *
	 * @var int
	 */
	protected $permissions;

	/**
	 * Specifies if the semaphore should be automatically released on request shutdown.
	 *
	 * @var int
	 */
	protected $autoRelease;

	/**
	 * A semaphore identifier.
	 *
	 * @var resource
	 */
	protected $semId;


	/**
	 * Initializes semaphore with file path and project identifier.
	 *
	 * @param string $file		Path to an accessible file.
	 * @param string $proj		Project identifier. This must be a one character string.
	 * @param int $max_acquire	The number of processes that can acquire the semaphore simultaneously is set to max_acquire.
	 * @param int $permissions	The semaphore permissions. Actually this value is set only if the process finds it is the only process currently attached to the semaphore.
	 * @param int $auto_release	Specifies if the semaphore should be automatically released on request shutdown.
	 *
	 * @return CIpcSemaphore
	 */
	public static function instance($file, $proj = 's', $max_acquire = 1, $permissions = self::PERMISSIONS, $auto_release = 1)
	{
		$key = ftok($file, $proj);
		if ($key === -1) {
			throw new AzaException("Can't initialize semaphore with file [$file] and project [$proj]", 1);
		}
		return new self($key, $max_acquire, $permissions, $auto_release);
	}


	/**
	 * Creates or open a semaphore
	 *
	 * @param int $key			A numeric semaphore memory segment ID.
	 * @param int $max_acquire	The number of processes that can acquire the semaphore simultaneously is set to max_acquire.
	 * @param int $permissions	The semaphore permissions. Actually this value is set only if the process finds it is the only process currently attached to the semaphore.
	 * @param int $auto_release	Specifies if the semaphore should be automatically released on request shutdown.
	 */
	public function __construct($key, $max_acquire = 1, $permissions = self::PERMISSIONS, $auto_release = 1)
	{
		if (!function_exists('sem_get')) {
			throw new AzaException('You need php compiled with --enable-sysvsem option to work with semaphore', 1);
		}
		if (!is_numeric($key)) {
			$key = sprintf('%u', crc32($key));
		}
		$this->key         = (int)$key;
		$this->permissions = $permissions;
		$this->maxAcquire  = $max_acquire;
		$this->autoRelease = $auto_release;
	}

	/**
	 * Initializes a semaphore
	 *
	 * @throws AzaException on fail
	 */
	protected function init()
	{
		if (null === $this->semId) {
			if (!$this->semId = sem_get($this->key, $this->maxAcquire, $this->permissions, $this->autoRelease)) {
				throw new AzaException("Can't create semaphore [$this->key, $this->maxAcquire, $this->permissions, $this->autoRelease]", 1);
			}
		}
	}


	/**
	 * Removes a semaphore
	 *
	 * @throws AzaException on fail
	 */
	public function remove()
	{
		$this->init();
		@sem_remove($this->semId);
		$this->semId = null;
	}


	/**
	 * Acquire a semaphore. If can't do it now, waits until the semaphore is released.
	 *
	 * @return bool
	 */
	public function acquire()
	{
		$this->init();
		return sem_acquire($this->semId);
	}

	/**
	 * Release a semaphore.
	 *
	 * @return bool
	 */
	public function release()
	{
		$this->init();
		return sem_release($this->semId);
	}
}
