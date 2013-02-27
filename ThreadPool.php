<?php

namespace Aza\Components\Thread;
use Aza\Components\Cli\Daemons\Daemon;
use Aza\Components\Log\Logger;
use Aza\Components\Thread\Exceptions\Exception;
use Aza\Kernel\Core;

/**
 * Thread pool for AzaThread (old name - CThread).
 *
 * @project Anizoptera CMF
 * @package system.thread
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class ThreadPool
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
	 * Event listeners
	 */
	protected $listeners = array();

	/**
	 * Threads in pool (id => thread)
	 *
	 * @var Thread[]
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
	public function __construct($threadName, $maxThreads = null,
		$pName = null, $debug = false, $name = 'base')
	{
		$debug && $this->debug = true;

		$this->id       = ++self::$allPoolsCount;
		$this->poolName = $name;
		$this->tName    = $threadName;

		!Thread::$useForks && $this->maxThreads = 1;
		isset($maxThreads) && $this->setMaxThreads($maxThreads);
		isset($pName)      && $this->pName = $pName;

		$this->debug(
			"Pool of '$threadName' threads created."
		);

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
				/** @var $thread Thread */
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
	 *
	 * @throws Exception
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
			$this->debug && $this->debug(
				'Waiting for threads: ' . join(', ', $w)
			);
			Thread::waitThreads($w);
		} else {
			throw new Exception(
				'Nothing to wait in pool'
			);
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
	 * Connects a listener to a given event.
	 *
	 * @see trigger
	 *
	 * @param string $event <p>
	 * An event name.
	 * </p>
	 * @param callback $listener <p>
	 * Callback to be called when the matching event occurs.
	 * <br><tt>function(string $event_name, int $thread_id, mixed $event_data, mixed $event_arg){}</tt>
	 * </p>
	 * @param mixed $arg <p>
	 * Additional argument for callback.
	 * </p>
	 */
	public function bind($event, $listener, $arg = null)
	{
		if (!isset($this->listeners[$event])) {
			$this->listeners[$event] = array();
		}
		$this->listeners[$event][] = array($listener, $arg);
		$this->debug(
			"New listener binded on event [$event]"
		);
	}

	/**
	 * Notifies all listeners of a given event.
	 *
	 * @see bind
	 *
	 * @param string $event    An event name
	 * @param int    $threadId Id of thread that caused the event
	 * @param mixed  $data     Event data for callback
	 */
	public function trigger($event, $threadId, $data = null)
	{
		$this->debug("Triggering event [$event]");
		if (!empty($this->listeners[$event])) {
			/** @var $cb callback */
			foreach ($this->listeners[$event] as $l) {
				list($cb, $arg) = $l;
				if ($cb instanceof \Closure) {
					$cb($event, $threadId, $data, $arg);
				} else {
					call_user_func(
						$cb, $event, $threadId, $data, $arg
					);
				}
			}
		}
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
		} else if (!Thread::$useForks || $value < 1) {
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

		$time     = Daemon::getTimeForLog();
		$poolId   = $this->id;
		$poolName = $this->poolName;
		$pid      = posix_getpid();
		$message = "<small>{$time} [debug] [P{$poolId}.{$poolName}] "
		           ."#{$pid}:</> <info>{$message}</>";

		if (class_exists('Aza\Kernel\Core', false) && $app = Core::$app) {
			$app->msg($message, Logger::LVL_DEBUG);
		} else {
			echo strip_tags($message), PHP_EOL;
			@ob_flush();
			@flush();
		}
	}
}
