<?php

// TODO: Events through pool

/**
 * Thread pool.
 *
 * @project Anizoptera CMF
 * @package system.thread
 * @version $Id: CThreadPool.php 2901 2011-12-15 07:27:58Z samally $
 */
class CThreadPool
{
	/**
	 * All started pools count
	 *
	 * @var int
	 */
	protected static $allPoolsCount = 0;

	/**
	 * Maximum threads number in pool
	 */
	protected $maxThreads = 4;

	/**
	 * Internal pool/thread id
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Current process pid
	 *
	 * @var int
	 */
	protected $pid;

	/**
	 * Current pool name
	 */
	protected $poolName;

	/**
	 * Thread name
	 */
	protected $tName;

	/**
	 * Thread process name
	 */
	protected $pName;

	/**
	 * Waiting number
	 */
	protected $waitNumber;

	/**
	 * Threads in pool (id => thread)
	 *
	 * @var CThread[]
	 */
	public $threads = array();

	/**
	 * Waiting threads IDs (id => id)
	 *
	 * @var int[]
	 */
	public $waiting = array();

	/**
	 * Working threads IDs (id => id)
	 *
	 * @var int[]
	 */
	public $working = array();

	/**
	 * Initializing threads IDs (id => id)
	 *
	 * @var int[]
	 */
	public $initializing = array();

	/**
	 * Failed threads IDs (id => id)
	 *
	 * @var int[]
	 */
	public $failed = array();

	/**
	 * Received results (id => result)
	 */
	public $results = array();

	/**
	 * Current threads count
	 */
	public $threadsCount = 0;

	/**
	 * Whether to show debugging information
	 *
	 * @var bool
	 */
	public $debug = false;


	/**
	 * Thread pool initialization
	 *
	 * @param string $threadName	Thread class name
	 * @param int    $maxThreads	Maximum threads number in pool
	 * @param string $pName			Thread process name
	 * @param bool   $debug			Whether to enable debug mode
	 * @param string $name			Pool name
	 */
	public function __construct($threadName, $maxThreads = null, $pName = null, $debug = false, $name = 'base')
	{
		$debug && $this->debug = true;

		$this->id       = ++self::$allPoolsCount;
		$this->pid      = posix_getpid();
		$this->poolName = $name;
		$this->tName    = $threadName;

		if (!CThread::$useForks) {
			$this->maxThreads = 1;
		}
		if (null !== $maxThreads) {
			$this->setMaxThreads($maxThreads);
		}

		if (null !== $pName) {
			$this->pName = $pName;
		}

		$this->debug("Pool of '$threadName' threads created.");

		$this->createAllThreads();
	}

	/**
	 * Destruction
	 */
	public function __destruct()
	{
		$this->debug('Destructor');
		$this->cleanup();
	}


	/**
	 * Pool cleanup
	 */
	public function cleanup()
	{
		$this->debug('Cleanup');
		foreach ($this->threads as $thread) {
			$thread->cleanup();
		}
	}


	/**
	 * Creates threads while has free slots
	 */
	protected function createAllThreads()
	{
		if (($count = &$this->threadsCount) < ($tMax = $this->maxThreads)) {
			do {
				/** @var $thread CThread */
				$thread = $this->tName;
				$thread = new $thread($this->debug, $this->pName, $this);
				$id = $thread->getId();
				$this->threads[$id] = $thread;
				$count++;
				$this->debug("Thread #$id created");
			} while ($count < $tMax);
		}
	}


	/**
	 * Starts one of free threads
	 *
	 * @return int|bool Thread ID or FALSE of no free threads in pool
	 */
	public function run()
	{
		$this->createAllThreads();
		if ($this->hasWaiting()) {
			$threadId = reset($this->waiting);
			$thread = $this->threads[$threadId];
			$args = func_get_args();
			if (($count = count($args)) === 0) {
				$thread->run();
			} else if ($count === 1) {
				$thread->run($args[0]);
			} else if ($count === 2) {
				$thread->run($args[0], $args[1]);
			} else if ($count === 3) {
				$thread->run($args[0], $args[1], $args[2]);
			} else {
				call_user_func_array(array($thread, 'run'), $args);
			}
			$this->waitNumber--;
			$this->debug("Thread #$threadId started");
			return $threadId;
		}
		return false;
	}


	/**
	 * Waits for waiting threads in pool
	 *
	 * @param array $failed Array of failed threads
	 *
	 * @return array|bool Returns array of results or FALSE if no results
	 */
	public function wait(&$failed = null)
	{
		$this->waitNumber = null;
		if ($this->results || $this->failed) {
			return $this->getResults($failed);
		}
		if (($w = $this->working) || $this->initializing) {
			if ($this->initializing) {
				$w += $this->initializing;
			}
			$this->debug && $this->debug('Waiting for threads: ' . join(', ', $w));
			CThread::waitThreads($w);
		} else {
			throw new AzaException('Nothing to wait in pool');
		}
		return $this->getResults($failed);
	}


	/**
	 * Returns if pool has waiting threads
	 *
	 * @return bool
	 */
	public function hasWaiting()
	{
		if ($this->waitNumber === null && $this->waiting) {
			$this->waitNumber = count($this->waiting);
			return true;
		} else {
			return $this->waitNumber > 0;
		}
	}

	/**
	 * Returns array of results by threads or false
	 *
	 * @param array $failed Array of failed threads
	 *
	 * @return array|bool
	 */
	protected function getResults(&$failed = null)
	{
		if ($res = $this->results) {
			$this->results = array();
		} else {
			$res = false;
		}
		$failed = $this->failed;
		$this->failed = array();
		return $res;
	}

	/**
	 * Returns state of all threads in pool
	 *
	 * @return array
	 */
	protected function getState()
	{
		$state = array();
		foreach ($this->threads as $threadId => $thread) {
			$state[$threadId] = $thread->getStateName();
		}
		return $state;
	}


	/**
	 * Sets maximum threads number
	 *
	 * @param int $value
	 */
	public function setMaxThreads($value)
	{
		if ($value < $this->threadsCount) {
			$value = $this->threadsCount;
		} else if (!CThread::$useForks || $value < 1) {
			$value = 1;
		}
		$this->maxThreads = (int)$value;
	}


	/**
	 * Debug logging
	 *
	 * @param string $message
	 */
	protected function debug($message)
	{
		if (!$this->debug) {
			return;
		}

		$time = CShell::getLogTime();
		$message = "{$time} [debug] [P{$this->id}.{$this->poolName}] #{$this->pid}: {$message}";

		echo $message;
		@ob_flush(); @flush();
	}
}
