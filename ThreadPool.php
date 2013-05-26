<?php

namespace Aza\Components\Thread;
use Aza\Components\CliBase\Base;
use Aza\Components\LibEvent\EventBase;
use Aza\Components\Log\Logger;
use Aza\Components\Thread\Exceptions\Exception;
use Aza\Kernel\Core;

/**
 * AzaThread pool.
 *
 * Old name - CThread.
 *
 * @project Anizoptera CMF
 * @package system.thread
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class ThreadPool
{
	/**
	 * Default pool name
	 */
	const DEFAULT_NAME = 'base';

	/**
	 * All created pools count
	 *
	 * @var int
	 */
	protected static $allPoolsCount = 0;


	#region Internal properties

	/**
	 * Maximum threads number in pool
	 */
	protected $maxThreads = 4;

	/**
	 * Internal unique pool id
	 *
	 * @var int
	 */
	protected $id;

	/**
	 * Internal pool name
	 *
	 * @var string
	 */
	protected $poolName;

	/**
	 * Thread class name
	 *
	 * @var string
	 */
	protected $threadClassName;

	/**
	 * Thread process name
	 *
	 * @var null|string
	 */
	protected $threadProcessName;

	/**
	 * Event listeners
	 */
	protected $listeners = array();

	/**
	 * Threads in pool (threadId => thread)
	 *
	 * @var Thread[]
	 */
	protected $threads = array();

	/**
	 * Waiting for job threads IDs (threadId => threadId)
	 *
	 * @var int[]
	 */
	protected $waitingForJob = array();

	/**
	 * Waiting for result fetch threads IDs (threadId => threadId)
	 *
	 * @var int[]
	 */
	protected $waitingForFetch = array();

	/**
	 * Working threads IDs (threadId => threadId)
	 *
	 * @var int[]
	 */
	protected $working = array();

	/**
	 * Initializing threads IDs (threadId => threadId)
	 *
	 * @var int[]
	 */
	protected $initializing = array();

	/**
	 * Failed threads IDs (threadId => [errorCode, errorMsg])
	 *
	 * @var array[]
	 */
	protected $failures = array();

	/**
	 * Results flags (threadId => threadId)
	 */
	protected $resultFlags = array();

	/**
	 * Received results (threadId => result)
	 */
	protected $results = array();

	/**
	 * Current threads count
	 */
	protected $threadsCount = 0;

	/**
	 * Flag for detached state.
	 * Enabled in child process after forking.
	 */
	protected $detached = false;

	/**
	 * Whether to show debugging information.
	 * DO NOT USE IN PRODUCTION!
	 *
	 * @internal
	 */
	public $debug = false;

	#endregion



	#region Initialization

	/**
	 * Thread pool initialization
	 *
	 * @param string $threadName Thread class name
	 * @param int    $maxThreads Maximum threads number in pool
	 * @param string $pName      Thread process name
	 * @param string $name       Pool name
	 * @param bool   $debug      Whether to enable debug mode
	 *
	 * @internal
	 */
	public function __construct($threadName, $maxThreads = null,
		$pName = null, $name = null, $debug = false)
	{
		$debug && $this->debug = true;

		$this->id              = ++self::$allPoolsCount;
		$this->poolName        = $name ?: self::DEFAULT_NAME;
		$this->threadClassName = $threadName;

		// @codeCoverageIgnoreStart
		$this->debug(
			"Pool of '$threadName' threads created ("
			. ltrim(spl_object_hash($this), '0') . ')'
		);
		// @codeCoverageIgnoreEnd

		if (Thread::$useForks) {
			if ($maxThreads > 0) {
				$this->maxThreads = (int)$maxThreads;
			}
		} else {
			$this->maxThreads = 1;
		}

		if ($pName) {
			$this->threadProcessName = $pName;
		}

		$this->createAllThreads();
	}

	/**
	 * Creates threads while pool has free slots
	 */
	protected function createAllThreads()
	{
		$count = &$this->threadsCount;
		$tMax  = $this->maxThreads;
		if ($count < $tMax) {
			$tName = $this->threadClassName;
			$pName = $this->threadProcessName;
			$debug = $this->debug;
			do {
				/** @var $thread Thread */
				$thread = new $tName($pName, $this, $debug);
				$count++;

				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					"Thread #{$thread->getId()} created"
				);
				// @codeCoverageIgnoreEnd
			} while ($count < $tMax);
		}
	}

	#endregion



	#region Cleanup

	/**
	 * Destruction
	 *
	 * @internal
	 */
	public function __destruct()
	{
		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			'Destructor (' . ltrim(spl_object_hash($this), '0') . ')'
		);
		// @codeCoverageIgnoreEnd
		$this->cleanup();
	}

	/**
	 * Pool cleanup
	 */
	public function cleanup()
	{
		// @codeCoverageIgnoreStart
		($debug = $this->debug) && $this->debug(
			'Cleanup (' . ltrim(spl_object_hash($this), '0') . ')'
			. ($this->detached ? ' (FORCED - redundant instance)' : '')
		);
		// @codeCoverageIgnoreEnd

		// Destroy all threads
		if (!$this->detached) {
			foreach ($this->threads as $thread) {
				$thread->cleanup();
			}
		}

		// Clean all array fields in pool
		$this->listeners =
		$this->threads =
		$this->waitingForJob =
		$this->waitingForFetch =
		$this->working =
		$this->initializing =
		$this->failures =
		$this->results = array();
	}

	/**
	 * Pool detaching (special cleanup
	 * for pool instance in child process)
	 *
	 * @internal
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	public function detach()
	{
		if (!$this->detached) {
			$this->detached = true;
			$this->cleanup();

			$this->debug && $this->debug(
				"Thread pool is detached"
			);
		} else {
			$this->debug && $this->debug(
				"Thread pool is already detached"
			);
		}
	}

	#endregion



	/**
	 * Starts job in one of the idle threads
	 *
	 * @see hasWaiting
	 * @see wait
	 *
	 * @return int ID of thread that started the job
	 *
	 * @throws Exception if no free threads in pool
	 */
	public function run()
	{
		if ($waiting = $this->waitingForJob) {
			$threadId = reset($waiting);
			$thread   = $this->threads[$threadId];

			// @codeCoverageIgnoreStart
			($debug = $this->debug) && $this->debug(
				"Starting job in thread #{$threadId}..."
			);
			// @codeCoverageIgnoreEnd

			// Use strict call for speedup
			// if number of arguments is not too big
			$args  = func_get_args();
			$count = count($args);
			if (0 === $count) {
				$thread->run();
			} else if (1 === $count) {
				$thread->run($args[0]);
			} else if (2 === $count) {
				$thread->run($args[0], $args[1]);
			} else if (3 === $count) {
				$thread->run($args[0], $args[1], $args[2]);
			} else {
				call_user_func_array(array($thread, 'run'), $args);
			}

			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				"Thread #$threadId started"
			);
			// @codeCoverageIgnoreEnd

			return $threadId;
		}

		// Strict approach
		throw new Exception('No threads waiting for the job');
	}



	#region Master waiting

	/**
	 * Waits for waiting threads in pool
	 *
	 * @param array $failed Array of failed threads
	 *
	 * @return array Returns array of results (can be empty)
	 *
	 * @throws Exception
	 */
	public function wait(&$failed = null)
	{
		if ($this->waitingForFetch) {
			return $this->getResults($failed);
		}

		$threadIds = $this->working + $this->initializing;
		if ($threadIds) {
			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				'Waiting for threads: #' . join(', #', $threadIds)
			);
			// @codeCoverageIgnoreEnd

			Thread::waitThreads($threadIds);
		} else {
			// Should not be called
			// @codeCoverageIgnoreStart
			throw new Exception(
				'Nothing to wait in pool'
			);
			// @codeCoverageIgnoreEnd
		}

		return $this->getResults($failed);
	}

	/**
	 * Returns array of results by threads
	 *
	 * @param array &$failed Array of failed threads
	 *
	 * @return array
	 */
	protected function getResults(&$failed = null)
	{
		$results = $this->results;
		$failed  = $this->failures;

		$this->waitingForJob += $this->waitingForFetch;

		// @codeCoverageIgnoreStart
		if ($this->debug) {
			$this->debug(
				$results
						? 'Fetching results for threads: #'
						  . join(', #', array_keys($results))
						: "No results to return"
			);
			$failed && $this->debug(
				'Fetching FAILED results for threads: #'
				. join(', #', array_keys($failed))
			);
			foreach ($this->waitingForFetch as $threadId) {
				$this->debug(
					"Thread #{$threadId} is marked as waiting for job"
				);
			}
		}
		// @codeCoverageIgnoreEnd

		$this->results =
		$this->resultFlags =
		$this->failures =
		$this->waitingForFetch = array();

		return $results;
	}

	#endregion



	#region Thread events dispatching

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

		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			"New listener binded on event [$event]"
		);
		// @codeCoverageIgnoreEnd
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
		// @codeCoverageIgnoreStart
		($debug = $this->debug) && $this->debug(
			"Triggering event \"$event\" on pool"
		);
		// @codeCoverageIgnoreEnd

		if (!empty($this->listeners[$event])) {
			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				"Pool has event listeners. Notify them..."
			);
			// @codeCoverageIgnoreEnd

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

	#endregion



	#region Getters/Setters

	/**
	 * Returns if pool has waiting threads
	 *
	 * @return bool
	 */
	public function hasWaiting()
	{
		return (bool)$this->waitingForJob;
	}


	/**
	 * Returns internal unique pool id
	 *
	 * @return int
	 */
	public function getId()
	{
		return $this->id;
	}

	/**
	 * Returns internal pool name
	 *
	 * @return string
	 */
	public function getPoolName()
	{
		return $this->poolName;
	}


	/**
	 * Returns thread process name
	 *
	 * @return null|string
	 */
	public function getThreadProcessName()
	{
		return $this->threadProcessName;
	}

	/**
	 * Returns thread class name
	 *
	 * @return string
	 */
	public function getThreadClassName()
	{
		return $this->threadClassName;
	}


	/**
	 * Returns pool threads
	 *
	 * @return Thread[] array of threads (threadId => thread)
	 */
	public function getThreads()
	{
		return $this->threads;
	}

	/**
	 * Returns status of all threads in pool
	 *
	 * @return string[] Array of statuses (threadId => stateName)
	 */
	public function getThreadsState()
	{
		$state = array();
		foreach ($this->threads as $threadId => $thread) {
			$state[$threadId] = $thread->getStateName();
		}
		return $state;
	}

	/**
	 * Returns statistic for all threads in pool
	 *
	 * @return array[] Array of data (threadId => data array)
	 * with fields: state, started_jobs, successful_jobs, failed_jobs
	 */
	public function getThreadsStatistic()
	{
		$state = array();
		foreach ($this->threads as $threadId => $thread) {
			$state[$threadId] = array(
				'state'           => $thread->getStateName(),
				'started_jobs'    => $thread->getStartedJobs(),
				'successful_jobs' => $thread->getSuccessfulJobs(),
				'failed_jobs'     => $thread->getFailedJobs(),
			);
		}
		return $state;
	}

	/**
	 * Returns current threads count
	 */
	public function getThreadsCount()
	{
		return $this->threadsCount;
	}


	/**
	 * Returns maximum threads number in pool.
	 *
	 * You can increase this number with
	 * {@link setMaxThreads}, but not decrease
	 *
	 * @see setMaxThreads
	 *
	 * @return int
	 */
	public function getMaxThreads()
	{
		return $this->maxThreads;
	}

	/**
	 * Sets maximum threads number
	 *
	 * @param int $value
	 */
	public function setMaxThreads($value)
	{
		// Filter value
		if ($value < $this->threadsCount) {
			$value = $this->threadsCount;
		} else if (!Thread::$useForks || $value < 1) {
			$value = 1;
		} else {
			$value = (int)$value;
		}

		// Apply new value
		if ($value !== $this->maxThreads) {
			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				"The number of threads changed: "
				. "{$this->maxThreads} => $value"
			);
			// @codeCoverageIgnoreEnd

			$this->maxThreads = (int)$value;

			$this->createAllThreads();
		}
	}

	#endregion



	#region Internal API for usage from threads

	/**
	 * Registers thread in pool
	 *
	 * @param Thread $thread
	 *
	 * @internal
	 */
	public function registerThread($thread)
	{
		$this->threads[$thread->getId()] = $thread;

		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			"Thread #{$thread->getId()} registered in pool"
		);
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Unregisters thread in pool
	 *
	 * @param int $threadId
	 *
	 * @internal
	 */
	public function unregisterThread($threadId)
	{
		if (isset($this->threads[$threadId])) {
			unset(
				$this->threads[$threadId],
				$this->waitingForJob[$threadId],
				$this->working[$threadId],
				$this->initializing[$threadId]
			);
			$this->threadsCount--;

			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				"Thread #{$threadId} removed from pool"
			);
			// @codeCoverageIgnoreEnd
		}
	}


	/**
	 * Sets processing result from thread
	 *
	 * @param int   $threadId
	 * @param mixed $result
	 *
	 * @throws Exception
	 *
	 * @internal
	 */
	public function setResultForThread($threadId, $result)
	{
		if (empty($this->working[$threadId])) {
			// @codeCoverageIgnoreStart
			// Should not be called
			// Break event loop to avoid freezes and other bugs
			$base = isset($this->threads[$threadId])
					? $this->threads[$threadId]->getEventLoop()
					: EventBase::getMainLoop(false);
			$base && $base->loopBreak();

			throw new Exception("Incorrect thread for result #$threadId");
			// @codeCoverageIgnoreEnd
		}

		$this->results[$threadId]     = $result;
		$this->resultFlags[$threadId] = $threadId;

		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			"Received result from thread #{$threadId}"
		);
		// @codeCoverageIgnoreEnd
	}


	/**
	 * Marks thread as waiting for job
	 *
	 * @param int    $threadId
	 * @param int    $errorCode
	 * @param string $errorMsg
	 *
	 * @throws Exception
	 *
	 * @internal
	 */
	public function markThreadWaiting($threadId, $errorCode = null, $errorMsg = null)
	{
		// Working thread
		if (isset($this->working[$threadId])) {
			if (empty($this->resultFlags[$threadId])) {
				$this->failures[$threadId] = array($errorCode, $errorMsg);

				// @codeCoverageIgnoreStart
				$e = new Exception();
				$this->debug && $this->debug(
					"Received fail from thread #{$threadId}: "
					."Error $errorCode - $errorMsg"
				);
				// @codeCoverageIgnoreEnd
			}

			unset($this->working[$threadId]);
			$this->waitingForFetch[$threadId] = $threadId;

			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				"Thread #{$threadId} is marked as waiting for results fetching"
			);
			// @codeCoverageIgnoreEnd

			return;
		}

		// Skipping.. async fail
		else if (isset($this->waitingForFetch[$threadId])
			|| isset($this->waitingForJob[$threadId])
		) {
			return;
		}

		// Initializing thread
		else if (isset($this->initializing[$threadId])) {
			unset($this->initializing[$threadId]);
			$this->waitingForJob[$threadId] = $threadId;

			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				"Thread #{$threadId} is marked as waiting for job"
			);
			// @codeCoverageIgnoreEnd

			return;
		}

		// @codeCoverageIgnoreStart
		// Incorrect thread - should not be called
		// Break event loop to avoid freezes and other bugs
		$base = isset($this->threads[$threadId])
				? $this->threads[$threadId]->getEventLoop()
				: EventBase::getMainLoop(false);
		$base && $base->loopBreak();

		throw new Exception(
			"Incorrect (not working) thread for waiting for the job #$threadId"
		);
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Marks thread as waiting for job
	 *
	 * @param int $threadId
	 *
	 * @throws Exception
	 *
	 * @internal
	 */
	public function markThreadWorking($threadId)
	{
		if (empty($this->waitingForJob[$threadId])) {
			// @codeCoverageIgnoreStart
			// Should not be called
			// Break event loop to avoid freezes and other bugs
			$base = isset($this->threads[$threadId])
					? $this->threads[$threadId]->getEventLoop()
					: EventBase::getMainLoop(false);
			$base && $base->loopBreak();

			throw new Exception("Incorrect thread for working #$threadId");
			// @codeCoverageIgnoreEnd
		}

		unset($this->waitingForJob[$threadId]);
		$this->working[$threadId] = $threadId;

		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			"Thread #{$threadId} is marked as working"
		);
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Marks thread as initializing
	 *
	 * @param int $threadId
	 *
	 * @internal
	 */
	public function markThreadInitializing($threadId)
	{
		$this->initializing[$threadId] = $threadId;

		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			"Thread #{$threadId} is marked as initializing"
		);
		// @codeCoverageIgnoreEnd
	}

	#endregion



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

		$time     = Base::getTimeForLog();
		$poolId   = $this->id;
		$poolName = $this->poolName;
		$pid      = posix_getpid();
		$message = "<small>{$time} [debug] [P{$poolId}.{$poolName}] "
		           ."#{$pid}:</> <info>{$message}</>";

		if (class_exists('Aza\Kernel\Core', false)
		    && $app = Core::$app
		) {
			// @codeCoverageIgnoreStart
			// TODO: Event dispatcher call for debug message?
			$app->msg($message, Logger::LVL_DEBUG);
		} else {
			// @codeCoverageIgnoreEnd
			echo preg_replace(
				'~<(?:/?[a-z][a-z0-9_=;-]+|/)>~Si', '', $message
			) . PHP_EOL;
			@ob_flush();
			@flush();
		}
	}
}
