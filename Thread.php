<?php

namespace Aza\Components\Thread;
use Aza\Components\CliBase\Base;
use Aza\Components\LibEvent\Event;
use Aza\Components\LibEvent\EventBase;
use Aza\Components\LibEvent\EventBuffer;
use Aza\Components\Log\Logger;
use Aza\Components\Socket\ASocket;
use Aza\Components\Socket\Socket;
use Aza\Components\Thread\Exceptions\Exception;
use Aza\Kernel\Core;

/**
 * Base class for AzaThread instance.
 * Powerfull thread emulation with forks and libevent.
 * Can work in synchronous mode without forks for compatibility.
 *
 * Old name - CThread.
 *
 * Inherit your "thread" from this class and implement
 * {@link process} method for basic usage.
 *
 * @project Anizoptera CMF
 * @package system.thread
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 *
 * @method mixed process() Main processing. You need to override this method. Use {@link getParam} method to get processing parameters. Returned result will be available via {@link getResult} in the master process.
 */
abstract class Thread
{
	#region Constants

	// Error codes

	/**
	 * Abnormal errors. See error message for details.
	 */
	const ERR_OTHER = 0x01;

	/**
	 * Job is not done because of child process sudden death
	 */
	const ERR_DEATH = 0x02;

	/**
	 * Job is not done because of exceeding of maximum timeout
	 * for master to wait for the job results
	 */
	const ERR_TIMEOUT_RESULT = 0x04;

	/**
	 * Job is not done because of exceeding of maximum timeout
	 * for master to wait for worker initialization (prefork)
	 */
	const ERR_TIMEOUT_INIT = 0x08;


	// Types of IPC data transfer modes

	/**
	 * Igbinary serialization (~6625 jobs per second in tests)
	 */
	const IPC_IGBINARY  = 1;

	/**
	 * Native PHP serialization (~6501 jobs per second in tests)
	 */
	const IPC_SERIALIZE = 2;


	// Thread states

	/**
	 * Terminating
	 */
	const STATE_TERM = 1;

	/**
	 * Initializing
	 */
	const STATE_INIT = 2;

	/**
	 * Waiting (ready for job, for example)
	 */
	const STATE_WAIT = 3;

	/**
	 * Working
	 */
	const STATE_WORK = 4;


	// Types of IPC packets (internal usage)
	const P_STATE  = 0x01; // State change packet
	const P_JOB    = 0x02; // New job packet
	const P_EVENT  = 0x04; // Event (from child) or read confirmation (from parent)

	// Timer names prefixes (internal usage)
	const TIMER_BASE = 'AzaThread:base:';
	const TIMER_WAIT = 'AzaThread:wait:';

	// Debug prefixes (internal usage)
	const D_INIT  = 'INIT: ';  // Thread initializing
	const D_WARN  = 'WARN: ';
	const D_INFO  = 'INFO: ';
	const D_EVENT = 'EVENT: '; // Worker events dispatching
	const D_IPC   = 'IPC: ';   // Inter-process communication
	const D_CLEAN = 'CLEAN: '; // Cleanup

	#endregion


	#region Public static settings

	/**
	 * IPC Data transfer mode (see self::IPC_*)
	 */
	public static $ipcDataMode = self::IPC_SERIALIZE;

	/**
	 * Whether threads will use forks
	 */
	public static $useForks = false;

	#endregion


	#region Settings. Overwrite in your thread class to customize.

	/**
	 * Whether the thread will wait for next tasks.
	 * Preforked threads are always multitask.
	 *
	 * @see prefork
	 */
	protected $multitask = true;

	/**
	 * Whether to listen for all POSIX signals in master.
	 * SIGCHLD is always listened.
	 */
	protected $listenMasterSignals = true;

	/**
	 * Perform pre-fork, to avoid wasting resources later.
	 * Preforked threads are always multitask.
	 *
	 * @see multitask
	 */
	protected $prefork = true;

	/**
	 * Wait for the preforking child
	 */
	protected $preforkWait = false;

	/**
	 * Whether event locking is enabled.
	 * Parent process notifies child that received sended event.
	 * It's mostly for testing purposes - do not use it in
	 * production because of performance lack.
	 */
	protected $eventLocking = false;

	/**
	 * Call {@link process} method with the specified arguments
	 * (using {@link call_user_func_array}).
	 * Creates a little performance overhead, so disabled by default.
	 */
	protected $argumentsMapping = false;

	/**
	 * Maximum timeout for master to wait for worker initialization (prefork)
	 * (in seconds, can be fractional).
	 * Set it to less than zero, to disable.
	 *
	 * @see prefork
	 * @see preforkWait
	 */
	protected $timeoutMasterInitWait = 3;

	/**
	 * Maximum timeout for master to wait for the job results
	 * (in seconds, can be fractional).
	 * Set it to less than zero, to disable.
	 */
	protected $timeoutMasterResultWait = 5;

	/**
	 * Maximum timeout for worker to wait for the new job
	 * (in seconds, can be fractional).
	 * After it spawned child will die.
	 * Set it to less than zero, to disable.
	 * Already this timeout is used with event locking.
	 */
	protected $timeoutWorkerJobWait = 600;

	/**
	 * Worker interval to check master process (in seconds, can be fractional).
	 */
	protected $intervalWorkerMasterChecks = 5;

	/**
	 * Maximum worker pipe read size in bytes.
	 * 128kb by default
	 */
	protected $childReadSize = 0x20000;

	/**
	 * Maximum master pipe read size in bytes.
	 * 128kb by default
	 */
	protected $masterReadSize = 0x20000;

	/**
	 * Whether to show debugging information
	 * DO NOT USE IN PRODUCTION!
	 *
	 * @internal
	 */
	public $debug = false;

	#endregion


	#region Internal static properties

	/**
	 * All created threads count
	 *
	 * @var int
	 */
	private static $threadsCount = 0;

	/**
	 * All threads
	 *
	 * @var Thread[]
	 */
	private static $threads = array();

	/**
	 * Threads by types
	 */
	private static $threadsByClasses = array();

	/**
	 * Threads marks by child PIDs (PID => thread id)
	 *
	 * @var int[]
	 */
	private static $threadsByPids = array();

	/**
	 * Waiting thread id
	 *
	 * @var int
	 */
	private static $waitingThread;

	/**
	 * Array of waiting threads (id => id)
	 *
	 * @var int[]
	 */
	private static $waitingThreads = array();

	/**
	 * Signal events by slots (child/parent flag)
	 *
	 * @var array[]
	 */
	private static $eventsSignals = array();

	/**
	 * Event loop
	 *
	 * @var EventBase
	 */
	private static $eventLoop;

	#endregion


	#region Internal properties

	/**
	 * Internal unique consecutive thread id
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Internal consecutive job id.
	 * In fact it's a number of the started jobs.
	 *
	 * @var int
	 */
	private $jobId = 0;

	/**
	 * Number of the successful jobs
	 *
	 * @var int
	 */
	private $successfulJobs = 0;

	/**
	 * Number of the failed jobs
	 *
	 * @var int
	 */
	private $failedJobs = 0;

	/**
	 * Owner thread pool
	 *
	 * @var ThreadPool
	 */
	private $pool;

	/**
	 * Pipes pair (master, worker)
	 *
	 * @var ASocket[]
	 */
	private $pipes;

	/**
	 * Master event
	 *
	 * @var EventBuffer
	 */
	private $masterEvent;

	/**
	 * Master read buffer
	 */
	private $masterBuffer = '';

	/**
	 * Currently receiving packet in child
	 *
	 * @var array|null
	 */
	private $masterPacket;

	/**
	 * Child event
	 *
	 * @var EventBuffer
	 */
	private $childEvent;

	/**
	 * Child read buffer
	 */
	private $childBuffer = '';

	/**
	 * Currently receiving packet in child
	 *
	 * @var array|null
	 */
	private $childPacket;

	/**
	 * Timer events names
	 *
	 * @var string[]
	 */
	private $eventsTimers = array();

	/**
	 * Event listeners
	 */
	private $listeners = array();

	/**
	 * Thread process name (if not empty)
	 *
	 * @var bool|string
	 */
	private $processName = false;

	/**
	 * Thread state. See self::STATE_* constants
	 *
	 * @var int
	 */
	private $state;

	/**
	 * Last error code
	 *
	 * @var int|null
	 */
	private $lastErrorCode;

	/**
	 * Last error message
	 *
	 * @var string|null
	 */
	private $lastErrorMsg;

	/**
	 * Whether waiting loop is enabled.
	 * Also used as flag for waiting for event read confirmation
	 */
	private $waiting = false;

	/**
	 * Flag if the thread started cleanup process
	 * (and therefore can not be used more)
	 */
	private $isCleaning = false;

	/**
	 * Current process pid
	 *
	 * @var int
	 */
	private $pid;

	/**
	 * Parent process pid
	 *
	 * @var int
	 */
	private $parent_pid;

	/**
	 * Child process pid
	 *
	 * @var int
	 */
	private $child_pid;

	/**
	 * Whether child is already forked
	 */
	private $isForked = false;

	/**
	 * Whether current process is child
	 */
	private $isChild = false;

	/**
	 * Arguments for the current job
	 */
	private $params = array();

	/**
	 * Last processing result
	 */
	private $result;

	/**
	 * ID of the started job
	 *
	 * @var int
	 */
	private $jobStarted;

	/**
	 * The status of the success of the task
	 */
	private $success = false;

	#endregion



	/**
	 * Initializes base parameters
	 *
	 * @param null       $pName   Thread worker process name
	 * @param ThreadPool $pool    Thread pool instance (if needed)
	 * @param bool       $debug   Whether to output debugging information
	 * @param array      $options Thread options (array [property => value])
	 *
	 * @throw Exception if can't wait for the preforked thread
	 */
	public function __construct($pName = null,
		$pool = null, $debug = false, array $options = null)
	{
		// Set options
		if ($options) {
			foreach ($options as $option => $value) {
				$this->$option = $value;
			}
		}

		// Prepare and save settings
		$this->id = $id = ++self::$threadsCount;
		$class = get_called_class();

		self::$threadsByClasses[$class][$id] =
		self::$threads[$id] = $this;

		$debug && $this->debug       = true;
		$pName && $this->processName = $pName;

		if ($pool) {
			$this->pool = $pool;
			$pool->registerThread($this);
		}

		if ($forks = self::$useForks) {
			$this->pid =
			$this->parent_pid =
			$this->child_pid = posix_getpid();
		}

		// Debug can be enabled in class property, so read value
		// @codeCoverageIgnoreStart
		if ($debug = $this->debug) {
			$this->debug(
				self::D_INIT . 'Thread of type "'.get_called_class().'" created ('
				. ltrim(spl_object_hash($this), '0') . ')'
			);
			$forks || $this->debug(
				self::D_WARN . 'Sync mode (you need Forks and LibEvent support'
				.' and CLI sapi to use threads asynchronously)'
			);
		}
		// @codeCoverageIgnoreEnd

		// Update state
		$this->setState(self::STATE_INIT);

		// Forks preparing
		$base = self::$eventLoop;
		if ($forks) {
			// Init shared master event loop
			if (!$base) {
				self::$eventLoop = $base = Base::getEventBase();
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_INIT . 'Master event loop initialized'
				);
				// @codeCoverageIgnoreEnd
			}

			// Listening for signals in master
			$this->registerEventSignals($this->listenMasterSignals);

			// Basic master timeout initialization
			$timer_name = self::TIMER_BASE . $this->id;
			$base->timerAdd(
				$timer_name, 0,
				array($this, '_mEvCbTimer'),
				null, false
			);
			$this->eventsTimers[] = $timer_name;
			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_INIT . "[Timer] Master timer ($timer_name) is added"
			);
			// @codeCoverageIgnoreEnd
		}

		// On load hook
		// @codeCoverageIgnoreStart
		$debug && $this->debug(
			self::D_INIT . "Call on load hook"
		);
		// @codeCoverageIgnoreEnd
		$this->onLoad();

		// Preforking
		if ($forks && $this->prefork) {
			// Preforked threads are always multitask.
			$this->multitask = true;

			$debug && $this->debug(self::D_INFO . 'Preforking...');

			// Code for parent process
			if ($this->forkThread()) {
				// Worker initialization timeout
				if (0 < $interval = $this->timeoutMasterInitWait) {
					$timer_name = self::TIMER_BASE . $this->id;
					$base->timerStart(
						$timer_name,
						$interval,
						self::STATE_INIT
					);
					// @codeCoverageIgnoreStart
					$debug && $this->debug(
						self::D_INFO . "[Timer] Master timer ($timer_name) "
						."started for INIT ($interval sec)"
					);
					// @codeCoverageIgnoreEnd
				}

				// Wait for the preforking child
				if ($this->preforkWait) {
					$this->wait();
				}
			}

			// Code for child process
			else {
				// @codeCoverageIgnoreStart
				// Start main (infinite in theory) worker loop
				$this->evWorkerLoop(true);

				// Child ended it's work, terminating
				$debug && $this->debug(
					self::D_INFO . 'Preforking: end of loop, terminating'
				);
				$this->shutdown();
				// @codeCoverageIgnoreEnd
			}
		} else {
			// Immediately set waiting state (ready for job)
			$this->setState(self::STATE_WAIT);

			// Call "on fork" hook for synchronous fallback mode
			if (!$forks) {
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_INIT . "Call on fork hook (synchronous fallback mode)"
				);
				// @codeCoverageIgnoreEnd
				$this->onFork();
			}
		}
	}



	/**
	 * Starts job processing.
	 * Specify any arguments for the job in call.
	 *
	 * You need to overwrite {@link process()} method
	 * to specify your job logic.
	 *
	 * @return $this
	 *
	 * @throws Exception
	 */
	public function run()
	{
		if (self::STATE_WAIT !== $this->state) {
			// Should not be called
			// @codeCoverageIgnoreStart
			throw new Exception(
				"Can't run thread. It is not in waiting state."
				." You need to use 'wait' method on thread instance"
				." after each run and before first run if 'preforkWait'"
				." property is not overrided to TRUE and you don't use pool."
			);
			// @codeCoverageIgnoreEnd
		}

		$this->jobStarted = $jobId = ++$this->jobId;

		// @codeCoverageIgnoreStart
		($debug = $this->debug) && $this->debug(
			self::D_INFO . " >>> Job start (j{$jobId})"
		);
		// @codeCoverageIgnoreEnd

		// Set state
		$this->setState(self::STATE_WORK);

		// Reset info
		$this->result =
		$this->lastErrorCode =
		$this->lastErrorMsg = null;
		$this->success = false;

		// Job arguments
		$args = func_get_args();

		// Emulating thread with fork
		if (self::$useForks) {
			// Thread is alive
			if ($this->isAlive()) {
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_INFO . "Child is already running ({$this->child_pid})"
				);
				// @codeCoverageIgnoreEnd
				$this->sendPacketToChild(self::P_JOB, array($jobId, $args));
				$this->startMasterWorkTimeout();
			}
			// Forking
			else {
				// Safety net, just in case
				$this->isForked && $this->stopWorker();

				if ($this->forkThread()) {
					// Code for parent process
					$this->startMasterWorkTimeout(true);
				} else {
					// @codeCoverageIgnoreStart
					// Code for child process

					// Register signals events
					$this->registerEventSignals();

					// Process job
					$this->callProcess($jobId, $args);
					$this->state = self::STATE_WAIT; // For consistence

					// Start main (infinite in theory) worker loop
					$this->multitask
						&& $this->evWorkerLoop();

					$debug && $this->debug(
						self::D_INFO . 'Simple end of work, exiting'
					);
					// Child ended it's work, terminating
					$this->shutdown();
					// @codeCoverageIgnoreEnd
				}
			}
		}

		// Synchronous fallback mode
		else {
			$this->callProcess($jobId, $args);
			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_INFO . 'Sync job ended'
			);
			// @codeCoverageIgnoreEnd
		}

		return $this;
	}



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
			self::D_CLEAN . 'Destructor ('
			. ltrim(spl_object_hash($this), '0') . ')'
		);
		// @codeCoverageIgnoreEnd

		$this->cleanup();
	}

	/**
	 * Thread cleanup.
	 *
	 * @param bool $forced [optional] <p>
	 * Enable forced mode. Used for unnecessary
	 * thread instances in child process.
	 * </p>
	 */
	public function cleanup($forced = false)
	{
		if ($this->isCleaning) {
			return;
		}
		$this->isCleaning = true;
		$this->state      = self::STATE_TERM;
		$notForced        = !$forced;

		// @codeCoverageIgnoreStart
		($debug = $this->debug) && $this->debug(
			self::D_CLEAN . 'Cleanup (' . ltrim(spl_object_hash($this), '0') . ')'
			. ($forced ? ' (FORCED - redundant instance)' : '')
		);
		// @codeCoverageIgnoreEnd

		$id       = $this->id;
		$class    = get_called_class();
		$isMaster = !$this->isChild;


		// On cleanup hook
		if ($notForced) {
			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_INIT . "Call on cleanup hook"
			);
			// @codeCoverageIgnoreEnd
			$this->onCleanup();
		}


		// External event listeners
		$this->listeners = array();


		// Threads pool
		if ($pool = $this->pool) {
			// Basic cleanup
			if ($notForced) {
				$pool->unregisterThread($id);

				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_CLEAN . 'Thread is removed from pool'
				);
				// @codeCoverageIgnoreEnd
			}

			// Forced cleanup
			else {
				// Called only in child
				// @codeCoverageIgnoreStart
				$pool->detach();
				// @codeCoverageIgnoreEnd
			}
		}
		$this->pool = null;
		unset($pool);


		// Stop child process
		$base = self::$eventLoop;
		if ($notForced && $isMaster && $this->isForked) {
			// TODO: Don't wait, check after cleanup?
			$this->stopWorker();
			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_CLEAN . 'Worker process terminated'
			);
			// @codeCoverageIgnoreEnd
		}


		// Threads storage
		unset(self::$threads[$id]);
		unset(self::$threadsByPids[$this->child_pid]);
		unset(self::$threadsByClasses[$class][$id]);
		if (empty(self::$threadsByClasses[$class])) {
			unset(self::$threadsByClasses[$class]);
		}


		// Timer events
		if ($notForced && $base && $base->resource) {
			// Check for non-triggered events in loop
			$base->loop(EVLOOP_NONBLOCK);
			foreach ($this->eventsTimers as $t) {
				$base->timerDelete($t);
			}
			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_CLEAN . 'Timer events cleaned'
			);
			// @codeCoverageIgnoreEnd
		}
		$this->eventsTimers = array();


		// Pipe events (master/child)
		if ($notForced) {
			// @codeCoverageIgnoreStart
			if ($this->masterEvent) {
				$this->masterEvent->free();
				$debug && $notForced &&  $this->debug(
					self::D_CLEAN . 'Master pipe event cleaned'
					. " (e{$this->masterEvent->id})"
				);
			}
			if ($this->childEvent) {
				$this->childEvent->free();
				$debug && $notForced &&  $this->debug(
					self::D_CLEAN . 'Child pipe event cleaned'
					. " (e{$this->childEvent->id})"
				);
			}
			// @codeCoverageIgnoreEnd
		}
		$this->masterEvent = $this->childEvent = null;


		// Pipes
		if ($this->pipes) {
			// @codeCoverageIgnoreStart
			if ($debug) {
				$ids = '';
				if (isset($this->pipes[0])) {
					$ids .= 'p'.$this->pipes[0]->id.', ';
				}
				$ids .= 'p'.$this->pipes[1]->id;
			}
			// @codeCoverageIgnoreEnd

			$this->pipes[1]->close();
			// It's already closed in child after forking
			isset($this->pipes[0])
				&& $this->pipes[0]->close();
			$this->pipes = null;

			// @codeCoverageIgnoreStart
			/** @noinspection PhpUndefinedVariableInspection */
			$debug && $this->debug(
				self::D_CLEAN . "Pipes closed ($ids)"
			);
			// @codeCoverageIgnoreEnd
		}


		if ($notForced) {
			// Signal events
			$slotName = (int)$isMaster;
			if (!$isMaster || !self::$threads) {
				// @codeCoverageIgnoreStart
				if (!empty(self::$eventsSignals[$slotName = (int)$isMaster])) {
					/** @var Event $ev */
					foreach (self::$eventsSignals[$slotName] as $ev) {
						$ev->free();
					}
					$debug && $this->debug(
						self::D_CLEAN . 'Signal events freed ('
						. ($isMaster ? 'last master' : 'child')
						. ')'
					);
				} else if ($debug) {
					$this->debug(
						self::D_CLEAN . 'Signal events not found ('
						. ($isMaster ? 'last master' : 'child')
						. ')'
					);
				}
				// @codeCoverageIgnoreEnd
				unset(self::$eventsSignals[$slotName]);
			}
			// @codeCoverageIgnoreStart
			if ($debug && $isMaster && !empty(self::$eventsSignals[$slotName])) {
				$this->debug(
					self::D_CLEAN . 'Signal events saved (another threads available)'
				);
			}
			// @codeCoverageIgnoreEnd


			// Last thread (master) - detached event loop
			if ($isMaster && !self::$threads) {
				self::$eventLoop = null;
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_CLEAN . 'Last thread (master) - event loop detached'
				);
				// @codeCoverageIgnoreEnd
			}


			// Child - event loop cleanup
			// @codeCoverageIgnoreStart
			else if (!$isMaster) {
				// Event loop cleanup
				self::$eventLoop = null;
				Base::cleanEventBase();
				$debug && $this->debug(
					self::D_CLEAN . 'Child - event loop cleaned'
				);
			}
			// @codeCoverageIgnoreEnd
		}


		// Processing data
		$this->params = array();
		$this->result = null;


		// @codeCoverageIgnoreStart
		$debug && $this->debug(
			self::D_CLEAN . 'Cleanup ended ('
			. ltrim(spl_object_hash($this), '0') . ')'
		);
		// @codeCoverageIgnoreEnd
	}

	#endregion



	#region Internal processing


	/**
	 * Thread forking.
	 *
	 * @throws Exception if can't fork thread
	 *
	 * @return bool TRUE in parent, FALSE in child
	 */
	protected function forkThread()
	{
		// Checks
		if (!self::$useForks) {
			// Should not be called
			// @codeCoverageIgnoreStart
			throw new Exception(
				"Can't fork thread. Forks are not supported."
			);
			// @codeCoverageIgnoreEnd
		} else if ($this->isForked) {
			// Should not be called
			// @codeCoverageIgnoreStart
			throw new Exception(
				"Can't fork thread. It is already forked."
			);
			// @codeCoverageIgnoreEnd
		}

		// Worker pipes
		$debug = $this->debug;
		if (!$this->pipes) {
			$this->pipes = Socket::pair();

			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_INIT . "Pipes initialized "
				."(p{$this->pipes[0]->id}, p{$this->pipes[1]->id})"
			);
			// @codeCoverageIgnoreEnd
		}

		// Forking
		// @codeCoverageIgnoreStart
		$debug && $this->debug(
			self::D_INIT . 'Forking'
		);
		// @codeCoverageIgnoreEnd

		// Code for parent process
		if ($pid = Base::fork()) {
			$this->isForked = true;
			self::$threadsByPids[$pid] = $this->id;
			$this->child_pid = $pid;

			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_INIT . "Forked: parent ({$this->pid})"
			);
			// @codeCoverageIgnoreEnd

			// Master event
			if (!$this->masterEvent) {
				$this->masterEvent = $ev = new EventBuffer(
					$this->pipes[0]->resource,
					array($this, '_mEvCbRead'),
					null,
					array($this, '_mEvCbError')
				);
				$ev->setBase(self::$eventLoop)->setPriority()->enable();

				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_INIT . 'Master pipe read event initialized'
					. " (e{$this->masterEvent->id})"
				);
				// @codeCoverageIgnoreEnd
			}

			return true;
		}

		// Code for child process
		// @codeCoverageIgnoreStart
		$this->isForked =
		$this->isChild = true;
		$this->pid =
		$this->child_pid =
		$pid = posix_getpid();
		$debug && $this->debug(
			self::D_INIT . "Forked: child ($pid)"
		);

		// Closing master pipe
		// It is not needed in the child
		$this->pipes[0]->close();
		$debug && $this->debug(
			self::D_INIT . "Master pipe closed "
			. "(p{$this->pipes[0]->id})"
		);
		unset($this->pipes[0]);

		// Cleanup parent events
		$this->eventsTimers =
		self::$eventsSignals = array();

		// Cleanup event listeners (needed only in parent)
		$this->listeners = array();

		// Cleanup master event
		$this->masterEvent = null;

		// Cleanup redundant thread instances
		$curThreadId = $this->id;
		foreach (self::$threads as $threadId => $thread) {
			$threadId === $curThreadId
				|| $thread->cleanup(true);
		}
		unset($thread, $threadId, $curThreadId);
		$debug && $this->debug(
			self::D_INIT . "Redundant (after forking)"
			. " instances and data cleaned"
		);

		// Cleanup redundant pool instance
		if ($pool = $this->pool) {
			$pool->detach();
		}
		$this->pool = null;

		// Child event
		$this->childEvent = $ev = new EventBuffer(
			$this->pipes[1]->resource,
			array($this, '_wEvCbRead'),
			null,
			array($this, '_wEvCbError')
		);
		$ev->setBase(self::$eventLoop)->setPriority()->enable();
		$debug && $this->debug(
			self::D_INIT . 'Worker pipe read event initialized'
			. " (e{$this->childEvent->id})"
		);

		// Process name
		if ($name = $this->processName) {
			$name .= ' (aza-php): worker';
			Base::setProcessTitle($name);
			$debug && $this->debug(
				self::D_INIT . "Child process name is changed to [$name]"
			);
		}

		// On fork hook
		$debug && $this->debug(
			self::D_INIT . "Call on fork hook"
		);
		$this->onFork();

		return false;
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Wrapper for {@link process} call
	 *
	 * @see process
	 *
	 * @param int   $jobId
	 * @param array $arguments
	 */
	private function callProcess($jobId ,$arguments)
	{
		// Prepares and sets arguments for processing
		$this->params = $arguments;

		// @codeCoverageIgnoreStart
		if ($this->debug) {
			$msg = $this->isChild
					? 'Async processing'
					: 'Processing';
			if ($arguments) {
				$msg .= ' with args';
			}
			$this->debug(self::D_INFO . $msg);
		}
		// @codeCoverageIgnoreEnd

		// Calls processing and set result
		// Uses so short syntax for speedup
		$this->setResult(
			$jobId,
			$arguments && $this->argumentsMapping
					// Arguments mapping
					? call_user_func_array(
						array($this, 'process'), $arguments
					)
					// Default call
					: $this->process()
		);
	}

	/**
	 * Prepares and starts worker event loop
	 *
	 * @param bool $prefork Set waiting state
	 *
	 * @throws Exception if called in parent
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	private function evWorkerLoop($prefork = false)
	{
		($debug = $this->debug) && $this->debug(
			self::D_INIT . "Preparing worker loop"
		);

		if (!$this->isChild) {
			throw new Exception(
				"Can't start child loop in parent"
			);
		}

		$prefork && $this->registerEventSignals();

		$base = self::$eventLoop;

		// Worker timer to check master process
		$timer_name = self::TIMER_BASE;
		$timerCb    = array($this, '_wEvCbTimer');
		($timeout = $this->intervalWorkerMasterChecks) > 0 || $timeout = 5;
		$base->timerAdd($timer_name, $timeout, $timerCb);
		$this->eventsTimers[] = $timer_name;
		$debug && $this->debug(
			self::D_INIT . "[Timer] Worker interval to check master process"
			." ($timer_name) is added and started ($timeout sec)"
		);

		// Worker wait timer
		if (0 < $timeout = $this->timeoutWorkerJobWait) {
			$timer_name = self::TIMER_WAIT;
			$base->timerAdd($timer_name, $timeout, $timerCb);
			$this->eventsTimers[] = $timer_name;
			$debug && $this->debug(
				self::D_INIT . "[Timer] Worker job wait interval ($timer_name) "
				."is added and started ($timeout sec)"
			);
		}

		$prefork && $this->setState(self::STATE_WAIT);

		$debug && $this->debug(self::D_INFO . 'Loop (worker) start');
		$base->loop();
		$debug && $this->debug(self::D_INFO . 'Loop (worker) end');
	}

	#endregion



	#region Methods for overriding!

	/**
	 * Hook called after thread initialization, but before forking!
	 * Override if you need custom logic here.
	 *
	 * @see __construct
	 */
	protected function onLoad() {}

	/**
	 * Hook called after thread forking (only in child process).
	 * Override if you need custom logic here.
	 *
	 * It's already called after initialization
	 * in synchronous fallback mode
	 *
	 * @see forkThread
	 */
	protected function onFork() {}

	/**
	 * Hook called before shutdown (only in child process).
	 * Override if you need custom shutdown logic.
	 *
	 * It's not called in synchronous fallback mode
	 *
	 * @see shutdown
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	protected function onShutdown() {}

	/**
	 * Hook called before full cleanup.
	 * Override if you need custom cleanup logic.
	 *
	 * @see cleanup
	 */
	protected function onCleanup() {}

	/**
	 * Main processing.
	 *
	 * Use {@link getParam} method to get processing parameters
	 *
	 * @return mixed returned result will be available via
	 * {@link getResult} in the master process
	 */
	//abstract protected function process();

	#endregion



	#region Master waiting

	/**
	 * Waits until the thread becomes waiting
	 *
	 * @throws Exception
	 *
	 * @return $this
	 */
	public function wait()
	{
		if (self::$useForks && self::STATE_WAIT !== $this->state) {
			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				self::D_INFO . "Loop (master waiting) start"
			);
			// @codeCoverageIgnoreEnd

			// Save thread id and start loop
			$this->waiting = true;
			self::$waitingThread = $this->id;
			self::evMasterLoop();
			self::$waitingThread = null;

			if (self::STATE_WAIT !== $this->state) {
				// Should not be called
				// @codeCoverageIgnoreStart
				throw new Exception(
					"Could not wait for the thread (state: "
					. $this->getStateName() . ") [#{$this->id}]"
				);
				// @codeCoverageIgnoreEnd
			}
		}
		return $this;
	}

	/**
	 * Waits until one of specified threads becomes waiting
	 *
	 * @param int|int[] $threadIds
	 */
	public static function waitThreads($threadIds)
	{
		if (self::$useForks && $threadIds) {
			// @codeCoverageIgnoreStart
			self::stGetDebug() && self::stDebug(
				self::D_INFO . 'Loop (master threads waiting) start: ['
					. join(', ', (array)$threadIds) . ']',
				true
			);
			// @codeCoverageIgnoreEnd

			// Save thread ids and start loop
			self::$waitingThreads = array_combine(
				$threadIds = (array)$threadIds,
				$threadIds
			);
			self::evMasterLoop();
			self::$waitingThreads = array();
		}
	}


	/**
	 * Starts master event loop
	 *
	 * @throws Exception
	 */
	private static function evMasterLoop()
	{
		if (!$base = self::$eventLoop) {
			// Should not be called
			// @codeCoverageIgnoreStart
			throw new Exception(
				"Can't start loop (master). It'is cleaned"
			);
			// @codeCoverageIgnoreEnd
		}

		// @codeCoverageIgnoreStart
		($debug = self::stGetDebug()) && self::stDebug(
			self::D_INFO . 'Loop (master) start',
			true
		);
		// @codeCoverageIgnoreEnd

		$base->loop();

		// @codeCoverageIgnoreStart
		$debug && self::stDebug(
			self::D_INFO . 'Loop (master) end',
			true
		);
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Starts master work timeout
	 *
	 * @param bool $addInitWait [optional] <p>
	 * Add masterInitWait timeout to the waiting time.
	 * Used if child is not alive.
	 * </p>
	 */
	private function startMasterWorkTimeout($addInitWait = false)
	{
		if (0 < $timeout = $this->timeoutMasterResultWait) {
			$addInitWait
				&& 0 < $this->timeoutMasterInitWait
				&& $timeout += $this->timeoutMasterInitWait;

			self::$eventLoop->timerStart(
				self::TIMER_BASE . $this->id,
				$timeout,
				self::STATE_WORK
			);

			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				self::D_INFO . "[Timer] Master timer ("
				. self::TIMER_BASE . $this->id
				.") started for WORK ($timeout sec)"
			);
			// @codeCoverageIgnoreEnd
		}
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
	 * <br><tt>function(string $event_name,
	 * mixed $event_data, mixed $event_arg){}</tt>
	 * </p>
	 * @param mixed $arg <p>
	 * Additional argument for callback.
	 * </p>
	 *
	 * @return $this
	 */
	public function bind($event, $listener, $arg = null)
	{
		// Bind is allowed only in parent
		if (!$this->isChild) {
			if (!isset($this->listeners[$event])) {
				$this->listeners[$event] = array();
			}
			$this->listeners[$event][] = array($listener, $arg);
			// @codeCoverageIgnoreStart
			if ($this->debug) {
				is_callable($listener, true, $callable_name);
				$this->debug(
					self::D_EVENT . "New external listener binded on "
					."thread event \"$event\" - [$callable_name]"
				);
			}
			// @codeCoverageIgnoreEnd
		}
		return $this;
	}

	/**
	 * Notifies all listeners of a given event.
	 *
	 * @see bind
	 *
	 * @param string $event An event name
	 * @param mixed  $data Event data for callback
	 *
	 * @throws Exception  if event read confirmation is not received from parent
	 * @throws \Exception rethrows catched exceptions
	 */
	public function trigger($event, $data = null)
	{
		// @codeCoverageIgnoreStart
		($debug = $this->debug) && $this->debug(
			self::D_EVENT . "Triggering event \"$event\""
		);
		// @codeCoverageIgnoreEnd

		// Child
		// @codeCoverageIgnoreStart
		if ($this->isChild) {
			$debug && $this->debug(
				self::D_EVENT . "Send event packet to parent"
			);

			// Add packet to buffer
			$this->sendPacketToParent(
				self::P_EVENT, array($event, $data)
			);

			// Enable deferred waiting for read confirmation
			// It will start on next try to send packet to parent
			$this->eventLocking && $this->waiting = true;

			// Flush (send) the buffer
			$debug && $this->debug(
				self::D_EVENT . "Flush buffer to notify parent immidiatly"
			);
			self::$eventLoop->loop(EVLOOP_NONBLOCK);
		}
		// @codeCoverageIgnoreEnd

		// Parent
		else {
			try {
				if (!empty($this->listeners[$event])) {
					// @codeCoverageIgnoreStart
					$debug && $this->debug(
						self::D_INFO . "Thread has event listeners. Notify them..."
					);
					// @codeCoverageIgnoreEnd

					/** @var $cb callback */
					foreach ($this->listeners[$event] as $l) {
						list($cb, $arg) = $l;
						if ($cb instanceof \Closure) {
							$cb($event, $data, $arg);
						} else {
							call_user_func(
								$cb, $event, $data, $arg
							);
						}
					}
				}
				if ($pool = $this->pool) {
					$pool->trigger($event, $this->id, $data);
				}
			} catch (\Exception $e) {
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_WARN . "Exception is catched in listener. "
					. "Break loop and rethrow exception: " . PHP_EOL . $e
				);
				// @codeCoverageIgnoreEnd

				// Break event loop to avoid freezes and other bugs
				self::$eventLoop && self::$eventLoop->loopBreak();

				throw $e;
			}
		}

		// @codeCoverageIgnoreStart
		$debug && $this->debug(
			self::D_EVENT . "Done - event \"$event\" triggered"
		);
		// @codeCoverageIgnoreEnd
	}

	#endregion



	#region Getters

	/**
	 * Returns internal unique thread id
	 *
	 * @return int
	 */
	public function getId()
	{
		return $this->id;
	}


	/**
	 * Returns current process pid
	 *
	 * @return int
	 */
	public function getPid()
	{
		return $this->pid;
	}

	/**
	 * Returns parent process pid
	 *
	 * @return int
	 */
	public function getParentPid()
	{
		return $this->parent_pid;
	}

	/**
	 * Returns child process pid
	 *
	 * @return int
	 */
	public function getChildPid()
	{
		return $this->child_pid;
	}


	/**
	 * Returns whether current process is child
	 *
	 * @return bool
	 */
	public function getIsChild()
	{
		return $this->isChild;
	}

	/**
	 * Returns whether child is already forked
	 *
	 * @return bool
	 */
	public function getIsForked()
	{
		return $this->isForked;
	}

	/**
	 * Returns if the thread started cleanup process
	 * (and therefore can not be used more).
	 *
	 * @return bool
	 */
	public function getIsCleaning()
	{
		return $this->isCleaning;
	}


	/**
	 * Returns success of the processing.
	 * Processing is not successful if thread
	 * dies when worked or working timeout exceeded.
	 *
	 * @return bool
	 */
	public function getSuccess()
	{
		return $this->success;
	}

	/**
	 * Returns result
	 *
	 * @return mixed
	 */
	public function getResult()
	{
		return $this->result;
	}


	/**
	 * Returns thread status
	 *
	 * @return int
	 */
	public function getState()
	{
		return $this->state;
	}

	/**
	 * Returns thread status name
	 *
	 * @param int $state [optional] <p>
	 * Integer state value. Current state will be used instead.
	 * </p>
	 *
	 * @return string
	 */
	public function getStateName($state = null)
	{
		if (!$state) {
			$state = $this->state;
		}
		return self::STATE_WAIT === $state
				? 'WAIT'
				: (self::STATE_WORK === $state
						? 'WORK'
						: (self::STATE_INIT === $state
								? 'INIT'
								: (self::STATE_TERM === $state
										? 'TERM'
										: 'UNKNOWN')));
	}


	/**
	 * Returns number of the started jobs
	 *
	 * @return int
	 */
	public function getStartedJobs()
	{
		return $this->jobId;
	}

	/**
	 * Returns number of the successful jobs
	 *
	 * @return int
	 */
	public function getSuccessfulJobs()
	{
		return $this->successfulJobs;
	}

	/**
	 * Returns number of the failed jobs
	 *
	 * @return int
	 */
	public function getFailedJobs()
	{
		return $this->failedJobs;
	}


	/**
	 * Returns last error code
	 *
	 * @return int|null
	 */
	public function getLastErrorCode()
	{
		return $this->lastErrorCode;
	}

	/**
	 * Returns last error message
	 *
	 * @return null|string
	 */
	public function getLastErrorMsg()
	{
		return $this->lastErrorMsg;
	}


	/**
	 * Returns thread event loop
	 *
	 * @return EventBase|null
	 */
	public function getEventLoop()
	{
		return self::$eventLoop;
	}



	/**
	 * Returns arguments for the current job
	 *
	 * @return array
	 */
	protected function getParams()
	{
		return $this->params;
	}

	/**
	 * Returns argument for the current job
	 *
	 * @param int $index <p>
	 * Argument index
	 * </p>
	 * @param mixed $default [optional] <p>
	 * Default value if parameter isn't set
	 * </p>
	 *
	 * @return mixed
	 */
	protected function getParam($index, $default = null)
	{
		return isset($this->params[$index])
				? $this->params[$index]
				: $default;
	}

	#endregion



	#region Internal getters/setters

	/**
	 * Checks if the child process is alive
	 *
	 * @return bool TRUE if child is alive FALSE otherwise
	 */
	private function isAlive()
	{
		return $this->isForked && 0 === pcntl_waitpid(
			$this->child_pid, $s, WNOHANG
		);
	}


	/**
	 * Sets processing result
	 *
	 * @param mixed $jobId
	 * @param mixed $result
	 */
	private function setResult($jobId, $result)
	{
		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			self::D_INFO . 'Setting result'
		);
		// @codeCoverageIgnoreEnd

		// Send result packet to parent
		// @codeCoverageIgnoreStart
		if ($this->isChild) {
			$this->sendPacketToParent(
				self::P_JOB, array($jobId, $result)
			);
		}
		// @codeCoverageIgnoreEnd

		// Change result
		else {
			if ($pool = $this->pool) {
				$pool->setResultForThread($this->id, $result);
			}
			$this->result  = $result;
			$this->success = true;
			$this->setState(self::STATE_WAIT);
		}
	}

	/**
	 * Sets thread state
	 *
	 * @param int $state One of self::STATE_*
	 */
	private function setState($state)
	{
		$this->state = $state;

		// @codeCoverageIgnoreStart
		if ($debug = $this->debug) {
			$this->debug(
				self::D_INFO . 'Changing state to: "'
				. $this->getStateName($state) . "\" ($state)"
			);
		}
		// @codeCoverageIgnoreEnd

		// Send state packet to parent (in child)
		// @codeCoverageIgnoreStart
		if ($this->isChild) {
			$this->sendPacketToParent(self::P_STATE, $state);
		}
		// @codeCoverageIgnoreEnd

		// Change state (in parent)
		else {
			$threadId = $this->id;

			// Waiting
			if ($wait = (self::STATE_WAIT === $state)) {
				if ($this->jobStarted) {
					if ($this->success) {
						$this->successfulJobs++;
					} else {
						$this->failedJobs++;

						// Should not be called
						// @codeCoverageIgnoreStart
						if (!$this->lastErrorCode) {
							$this->lastErrorCode = self::ERR_OTHER;
							$this->lastErrorMsg  = 'Worker stopped';
						}
						// @codeCoverageIgnoreEnd
					}

					$this->jobStarted = null;

					// Mark thread as stopped and clean resources
					// if not multitask
					$this->multitask
						|| $this->stopWorker();
				}

				// Forked thread and event loop
				if (self::$useForks) {
					// Stop result waiting timer
					$base = self::$eventLoop;
					$base->timerStop($timer_name = self::TIMER_BASE . $threadId);
					// @codeCoverageIgnoreStart
					$debug && $this->debug(
						self::D_INFO . "[Timer] Master timer ($timer_name) stopped"
					);
					// @codeCoverageIgnoreEnd

					// One waiting thread
					if ($this->waiting) {
						$this->waiting = false;

						// Only break loop for waiting thread
						if (!($waitingThread = self::$waitingThread)
						    || $threadId === $waitingThread
						) {
							// @codeCoverageIgnoreStart
							$debug && $this->debug(
								self::D_INFO . "Loop (master waiting) end"
							);
							// @codeCoverageIgnoreEnd
							$base->loopBreak();
						} else {
							// @codeCoverageIgnoreStart
							$debug && $this->debug(
								self::D_INFO . "Master waiting ended "
								. "not breaking loop"
							);
							// @codeCoverageIgnoreEnd
						}
					}

					// Several waiting threads
					else if (isset(self::$waitingThreads[$threadId])) {
						// @codeCoverageIgnoreStart
						$debug && $this->debug(
							self::D_INFO . 'Loop (master threads waiting) end'
						);
						// @codeCoverageIgnoreEnd
						$base->loopBreak();
					}
				}
			}

			// Pool processing
			if ($pool = $this->pool) {
				// Waiting
				if ($wait) {
					$pool->markThreadWaiting(
						$threadId,
						$this->lastErrorCode,
						$this->lastErrorMsg
					);
				}
				// Working
				else if (self::STATE_WORK & $state) {
					$pool->markThreadWorking($threadId);
				}
				// Initializing
				else if (self::STATE_INIT & $state) {
					$pool->markThreadInitializing($threadId);
				}
			}
		}
	}

	#endregion



	#region Event loop callbacks

	/**
	 * Worker read event callback
	 *
	 * @see evWorkerLoop
	 * @see EventBuffer::setCallback
	 *
	 * @internal
	 *
	 * @throws Exception
	 *
	 * @param resource $buf  Buffered event
	 * @param array    $args
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	public function _wEvCbRead($buf, $args)
	{
		($debug = $this->debug) && $this->debug(
			self::D_INFO . "Worker pipe read event; $buf"
		);

		// Receive packets
		if (!$packets = $this->readPackets(
			$args[0],
			$this->childReadSize,
			$this->childBuffer,
			$this->childPacket
		)) {
			return;
		}

		// Handle packets
		$base = self::$eventLoop;
		foreach ($packets as $p) {
			$packet = $p['packet'];

			$debug && $this->debug(sprintf(
				"%s => Packet: [0x%x]", self::D_IPC, $packet
			));

			// Job packet
			if (self::P_JOB & $packet) {
				list($jobId, $args) = $this->peekPacketData($p['data']);

				$debug && $this->debug(
					self::D_IPC . ' => Packet: job'
					. ($args ? ' with arguments' : '')
				);

				$this->callProcess($jobId, $args);
			}

			// Event read confirmation
			else if (self::P_EVENT & $packet) {
				$debug && $this->debug(
					self::D_IPC . ' => Packet: event read confirmation '
					.'received. Unlocking thread'
				);
				$this->waiting = false;
				$base->loopBreak();
			}

			// Unknown packet (should not be called)
			else {
				$base->loopBreak();
				throw new Exception(sprintf(
					"Unknown IPC packet [0x%x]", $packet
				));
			}
		}

		// Restart waiting timeout
		$timer = self::TIMER_WAIT;
		if ($base->timerExists($timer)) {
			$base->timerStart($timer);
			$debug && $this->debug(
				self::D_INFO . '[Timer] Job waiting interval restarted'
			);
		}
	}

	/**
	 * Worker error event callback
	 *
	 * @see forkThread
	 * @see EventBuffer::setCallback
	 *
	 * @internal
	 *
	 * @param resource $buf   Buffered event
	 * @param int      $what  Error info
	 *
	 * @codeCoverageIgnore Should not be called
	 */
	public function _wEvCbError($buf, $what)
	{
		if ($this->debug) {
			$this->evErrorDebug($buf, $what);
		}
	}

	/**
	 * Worker timer event callback
	 *
	 * @see evWorkerLoop
	 * @see EventBase::timerAdd
	 *
	 * @internal
	 *
	 * @param string $name Timer name
	 * @param mixed  $arg  Timer argument (not used)
	 * @param int    $i    Timer iteration (for intervals)
	 *
	 * @return bool true to restrst worker interval
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	public function _wEvCbTimer($name, $arg, $i)
	{
		$die = false;

		// Worker wait
		if (self::TIMER_WAIT === $name) {
			$this->debug && $this->debug(
				self::D_WARN . '[Timer] Timeout (worker waiting, '
				. $this->timeoutWorkerJobWait .' sec) exceeded, exiting'
			);
			$die = true;
		}

		// Worker check
		else if (!Base::getProcessIsAlive($this->parent_pid)) {
			$this->debug && $this->debug(
				self::D_WARN . 'Parent is dead, exiting'
			);
			$die = true;
		}

		// Break event loop and die
		if ($die) {
			self::$eventLoop->loopBreak();
			$this->shutdown();
			return false;
		}

		// Restart interval
		$this->debug && $this->debug(
			self::D_INFO . "[Timer] Restart worker "
			. "interval ($name; $i iteration; arg: [$arg])"
		);
		return true;
	}


	/**
	 * Master read event callback
	 *
	 * @see forkThread
	 * @see EventBuffer::setCallback
	 *
	 * @internal
	 *
	 * @param resource $buf  Buffered event
	 * @param array    $args
	 *
	 * @throws Exception
	 */
	public function _mEvCbRead($buf, $args)
	{
		// @codeCoverageIgnoreStart
		($debug = $this->debug) && $this->debug(
			self::D_INFO . "Master pipe read event; $buf"
		);
		// @codeCoverageIgnoreEnd

		if (!$packets = $this->readPackets(
			$args[0],
			$this->masterReadSize,
			$this->masterBuffer,
			$this->masterPacket
		)) {
			return;
		}

		foreach ($packets as $p) {
			$packet = $p['packet'];

			// @codeCoverageIgnoreStart
			$debug && $this->debug(sprintf(
				"%s <= Packet: [0x%x]", self::D_IPC, $packet
			));
			// @codeCoverageIgnoreEnd

			// Check thread id
			$threadId = $this->id;
			if (!isset(self::$threads[$threadId])) {
				// Should not be called
				// @codeCoverageIgnoreStart
				self::$eventLoop->loopBreak();
				throw new Exception(sprintf(
					"Packet [0x%x] for unknown thread #%d",
					$packet, $threadId
				));
				// @codeCoverageIgnoreEnd
			}
			$thread = self::$threads[$threadId];

			// Packet data
			$data = $this->peekPacketData($p['data']);

			// State packet
			if (self::P_STATE & $packet) {
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_IPC . ' <= Packet: state'
				);
				// @codeCoverageIgnoreEnd
				$thread->setState((int)$data);
			}

			// Event packet
			else if (self::P_EVENT & $packet) {
				// @codeCoverageIgnoreStart
				$debug && $this->debug(
					self::D_IPC . ' <= Packet: event'
				);
				// @codeCoverageIgnoreEnd
				if ($thread->eventLocking) {
					// @codeCoverageIgnoreStart
					$debug && $this->debug(
						self::D_IPC . " => [eventLocking] Sending event read confirmation"
					);
					// @codeCoverageIgnoreEnd
					$thread->sendPacketToChild(self::P_EVENT);
				}
				$thread->trigger($data[0], $data[1]);
			}

			// Job (result) packet
			else if (self::P_JOB & $packet) {
				$jobId = $data[0];

				// @codeCoverageIgnoreStart

				// Discard duplicate packet.
				// Very strange situation, but sometimes job result packet
				// can be duplicated (when child dies for example)
				if ($this->success) {
					$debug && $this->debug(
						self::D_WARN . '<= Packet: job ended. Discarded (duplicate)'
					);
				}
				// Discard orphaned packet. It can occur if child sent a packet
				// and died unexpectedly, so a job is marked as failed
				else if ($jobId != $this->jobStarted) {
					$debug && $this->debug(
						self::D_WARN . '<= Packet: job ended. Discarded (orphaned)'
					);
				}
				// @codeCoverageIgnoreEnd

				// Normal job end
				else {
					// @codeCoverageIgnoreStart
					$debug && $this->debug(
						self::D_IPC . ' <= Packet: job ended'
					);
					// @codeCoverageIgnoreEnd

					$thread->setResult($jobId, $data[1]);
				}
			}

			// Unknown packet (should not be called)
			else {
				// @codeCoverageIgnoreStart
				self::$eventLoop->loopBreak();
				throw new Exception(sprintf(
					"Unknown IPC packet [0x%x]", $packet
				));
				// @codeCoverageIgnoreEnd
			}
		}
	}

	/**
	 * Master error event callback
	 *
	 * @see forkThread
	 * @see EventBuffer::setCallback
	 *
	 * @internal
	 *
	 * @param resource $buf   Buffered event
	 * @param int      $what  Error info
	 *
	 * @codeCoverageIgnore Should not be called
	 */
	public function _mEvCbError($buf, $what)
	{
		if ($this->debug) {
			$this->evErrorDebug($buf, $what);
		}
	}

	/**
	 * Master timer event callback
	 *
	 * @see __construct
	 * @see EventBase::timerAdd
	 *
	 * @internal
	 *
	 * @param string $name Timer name
	 * @param mixed  $arg  Additional timer argument
	 * @param int    $i    Iteration number
	 *
	 * @return bool
	 *
	 * @throws Exception
	 */
	public function _mEvCbTimer($name, $arg, $i)
	{
		// Job results wait timeout
		if (self::STATE_WORK & ($arg = (int)$arg)) {
			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				self::D_WARN . "[Timer] Master work wait timeout "
				. "exceeded; " . $this->timeoutMasterResultWait
				. "sec ($name; $i iteration; arg: [$arg])"
			);
			// @codeCoverageIgnoreEnd

			$code = self::ERR_TIMEOUT_RESULT;
			$msg  = "Exceeded timeout: thread work "
			        ."({$this->timeoutMasterResultWait} sec.)";
		}
		// Worker initialization wait timeout
		else if (self::STATE_INIT & $arg) {
			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				self::D_WARN . "[Timer] Master initialization wait "
				. "timeout exceeded; " . $this->timeoutMasterInitWait
				. "sec ($name; $i iteration; arg: [$arg])"
			);
			// @codeCoverageIgnoreEnd

			// TODO: Check if child is really not initialized?
			$code = self::ERR_TIMEOUT_INIT;
			$msg  = "Exceeded timeout: thread initialization "
			        ."({$this->timeoutMasterInitWait} sec.)";
		}
		// Unknown timeout (should not be called)
		else {
			// @codeCoverageIgnoreStart
			$this->debug && $this->debug(
				self::D_WARN . "[Timer] Unknown timeout exceeded "
				. "($name; $i iteration; arg: [$arg])"
			);
			// @codeCoverageIgnoreEnd

			// @codeCoverageIgnoreStart
			$code = self::ERR_OTHER;
			$msg  = "Unknown timeout ($name; $i iteration; arg: [$arg])";
			// @codeCoverageIgnoreEnd
		}

		// Stop worker and set state to WAIT
		$this->lastErrorCode = $code;
		$this->lastErrorMsg  = $msg;
		$this->stopWorker();
	}


	/**
	 * Debugging for buffer events errors
	 *
	 * @see _wEvCbError
	 * @see _mEvCbError
	 *
	 * @param resource $buf   Buffered event
	 * @param int      $what  Error info
	 *
	 * @codeCoverageIgnore Should not be called
	 */
	private function evErrorDebug($buf, $what)
	{
		$this->debug(sprintf(
			self::D_WARN . "Pipe read error event [0x%x]; $buf",
			$what
		));

		if ($what & EventBuffer::E_EOF) {
			$this->debug(
				self::D_WARN . "    Buffer EOF error"
			);
		}
		if ($what & EventBuffer::E_ERROR) {
			$this->debug(
				self::D_WARN . "    Buffer common error"
			);
		}
		if ($what & EventBuffer::E_TIMEOUT) {
			$this->debug(
				self::D_WARN . "    Buffer timeout error"
			);
		}
	}

	#endregion



	#region Working with data packets

	/**
	 * Sends packet to parent
	 *
	 * @param int $packet <p>
	 * Integer packet type (see self::P_* constants)
	 * </p>
	 * @param mixed $data [optional] <p>
	 * Mixed packet data
	 * </p>
	 *
	 * @throws Exception if can't send packet to parent
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	private function sendPacketToParent($packet, $data = null)
	{
		// Deferred waiting for read confirmation
		if ($this->waiting) {
			($debug = $this->debug) && $this->debug(
				self::D_INFO . "[eventLocking] Child is locked. "
				."Waiting for event read confirmation "
				."({$this->timeoutWorkerJobWait} seconds maximum)"
			);

			// Start event loop with timeout
			self::$eventLoop->loopExit(
				(int)($this->timeoutWorkerJobWait * 1000000)
			)->loop();

			// Confirmation is not received
			if ($this->waiting) {
				$error = "Can't send packet to parent. Parent "
				         ."event read confirmation is not received.";
				$debug && $this->debug(self::D_WARN . $error);
				self::$eventLoop->loopBreak();
				throw new Exception($error);
			}
		}

		$this->childEvent->write(
			$this->preparePacket($packet, $data, false)
		);
	}

	/**
	 * Sends packet to child
	 *
	 * @param int   $packet Integer packet type (see self::P_* constants)
	 * @param mixed $data   Mixed packet data
	 */
	private function sendPacketToChild($packet, $data = null)
	{
		$this->masterEvent->write(
			$this->preparePacket($packet, $data)
		);

		// Flush (send) the buffer
		// @codeCoverageIgnoreStart
		$this->debug && $this->debug(
			self::D_IPC . "Flush buffer to notify child immidiatly"
			. " (e{$this->masterEvent->id})"
		);
		// @codeCoverageIgnoreEnd
		self::$eventLoop->loop(EVLOOP_NONBLOCK);
	}


	/**
	 * Reads packets from pipe with buffered event
	 *
	 * @throws Exception
	 *
	 * @param EventBuffer $e <p>
	 * Buffered event
	 * </p>
	 * @param int $maxReadSize <p>
	 * Data size to read at once in bytes.
	 * </p>
	 * @param string $buffer <p>
	 * Read buffer.
	 * </p>
	 * @param null|array $curPacket <p>
	 * Read buffer.
	 * </p>
	 *
	 * @return string[] Array of packets
	 */
	private function readPackets($e, $maxReadSize, &$buffer, &$curPacket)
	{
		$debug = $this->debug;

		// @codeCoverageIgnoreStart
		if (!$curPacket && '' != $buffer) {
			// Should not be called
			$error = "Unexpected read buffer (".strlen($buffer)." bytes)";
			$debug && $this->debug(self::D_WARN . $error);
			if ($this->isCleaning) {
				$e->readAllClean();
				return array();
			}
			self::$eventLoop->loopBreak();
			throw new Exception($error);
		}
		// @codeCoverageIgnoreEnd

		$buf = $e->readAll($maxReadSize);

		// @codeCoverageIgnoreStart
		$debug && $this->debug(
			self::D_IPC . "    Read ".strlen($buf)."b; "
			. strlen($buffer)."b in buffer"
		);
		// @codeCoverageIgnoreEnd

		// TODO: Limit the maximum buffer length?
		$buffer .= $buf;

		// @codeCoverageIgnoreStart
		if ('' === $buffer) {
			// Should not be called
			return array();
		}
		// @codeCoverageIgnoreEnd

		$packets = array();
		do {
			if (!$curPacket) {
				// @codeCoverageIgnoreStart
				// Orphaned buffer (should not be called normally)
				if ("\x80" !== $buffer[0]) {
					$pos = strpos($buffer, "\x80");
					$debug && $this->debug(
						self::D_WARN . "Packet must start with 0x80 character ("
						. strlen($buffer) . " bytes in buffer)"
						. "; next packet position: " . ($pos === false ? '-' : $pos)
					);
					if (false === $pos) {
						$buffer = '';
						break;
					}
					$buffer = substr($buffer, $pos);
				}
				// @codeCoverageIgnoreEnd

				$curPacket = unpack(
					'Cpacket/NdataLength',
					substr($buffer, 1, 5)
				);
				$buffer = substr($buffer, 6);

				// @codeCoverageIgnoreStart
				$debug && $this->debug(sprintf(
					"%s    Packet started [0x%x]; "
						."%d bytes data",
					self::D_IPC, $curPacket['packet'],
					$curPacket['dataLength']
				));
				// @codeCoverageIgnoreEnd
			} else {
				// @codeCoverageIgnoreStart
				$debug && $this->debug(sprintf(
					"%s    Packet continue [0x%x]; %d bytes data; "
						. "%d bytes read",
					self::D_IPC, $curPacket['packet'],
					$curPacket['dataLength'], strlen($buffer)
				));
				// @codeCoverageIgnoreEnd
			}

			if (($bufferLen = strlen($buffer))
			    < ($dataLen = $curPacket['dataLength'])
			) {
				return $packets;
			} else if ($dataLen) {
				if ($dataLen === $bufferLen) {
					$curPacket['data'] = $buffer;
					$buffer            = '';
				} else {
					$curPacket['data'] = substr($buffer, 0, $dataLen);
					$buffer            = substr($buffer, $dataLen);
				}
			} else {
				// Should not be called
				// @codeCoverageIgnoreStart
				$curPacket['data'] = '';
				// @codeCoverageIgnoreEnd
			}

			// Debugging
			// @codeCoverageIgnoreStart
			if ($debug) {
				if ($dataLen != $rDataLen = strlen($curPacket['data'])) {
					self::$eventLoop->loopBreak();
					$error = "Packet data length header ({$dataLen})"
					         ." does not match the actual length of the data"
					         ." ({$rDataLen})";
					$this->debug(self::D_WARN . $error);
					throw new Exception($error);
				}
				$this->debug(sprintf(
					"%s    Packet completed [0x%x]; %d bytes data; "
						. "%d bytes left in buffer",
					self::D_IPC, $curPacket['packet'],
					$dataLen, strlen($buffer)
				));
			}
			// @codeCoverageIgnoreEnd

			$packets[] = $curPacket;
			$curPacket = null;

		} while($buffer);

		// @codeCoverageIgnoreStart
		$debug && $this->debug(
			self::D_IPC . '    Packets received: ' . count($packets)
		);
		// @codeCoverageIgnoreEnd

		return $packets;
	}

	/**
	 * Peeks packet data
	 *
	 * @param mixed $data Raw data
	 *
	 * @return string
	 */
	private function peekPacketData($data)
	{
		return (self::IPC_IGBINARY === self::$ipcDataMode)
				? igbinary_unserialize($data)
				: unserialize($data);
	}

	/**
	 * Prepares IPC packet
	 *
	 * @throws Exception
	 *
	 * @param int    $packet  Integer packet type (see self::P_* constants)
	 * @param string $data    Packet data
	 * @param bool   $toChild Packet is for child
	 *
	 * @return string
	 */
	private function preparePacket($packet, $data, $toChild = true)
	{
		// Prepare data
		$data = '' != $data
				? ((self::IPC_IGBINARY === self::$ipcDataMode)
						? igbinary_serialize($data)
						: serialize($data))
				: '';

		// Build packet
		$_packet = "\x80" . pack('CN', $packet, strlen($data)) . $data;

		// Debugging
		// @codeCoverageIgnoreStart
		if ($this->debug) {
			if ($toChild) {
				$arr = '=>';
				$n   = 'child';
			} else {
				$arr = '<=';
				$n   = 'parent';
			}
			$this->debug(sprintf(
				"%s    %s Sending packet%s to %s [0x%x]",
				self::D_IPC, $arr,
				'' == $data ? '' : ' (with data)',
				$n, $packet
			));
			$this->debug(sprintf(
				"%s    %s %d bytes length (%d bytes data)",
				self::D_IPC, $arr, strlen($_packet), strlen($data)
			));
		}
		// @codeCoverageIgnoreEnd

		return $_packet;
	}

	#endregion



	#region POSIX/Unix signals handling

	/**
	 * Sends signal to parent
	 *
	 * @param int $signo Signal's number
	 *
	 * @return $this
	 */
	public function sendSignalToParent($signo = SIGUSR1)
	{
		$this->sendSignal($signo, $this->parent_pid);

		return $this;
	}

	/**
	 * Sends signal to child
	 *
	 * @param int $signo Signal's number
	 *
	 * @return $this
	 */
	public function sendSignalToChild($signo = SIGUSR1)
	{
		if ($this->isForked) {
			$this->sendSignal($signo, $this->child_pid);
		}
		return $this;
	}

	/**
	 * Sends signal to child
	 *
	 * @see sendSignalToParent
	 * @see sendSignalToChild
	 *
	 * @param int $signo Signal's number
	 * @param int $pid   Target process pid
	 */
	private function sendSignal($signo, $pid)
	{
		// @codeCoverageIgnoreStart
		if ($this->debug) {
			$signame = Base::getSignalName($signo);
			if ($pid === $this->child_pid) {
				$arrow = '=>';
				$n     = 'child';
			} else {
				$arrow = '<=';
				$n     = 'parent';
			}
			$this->debug(
				self::D_IPC . " $arrow Sending signal to the "
				."$n - $signame ($signo) ($this->pid => $pid)"
			);
		}
		// @codeCoverageIgnoreEnd

		posix_kill($pid, $signo);
	}


	/**
	 * Registers event handlers for all POSIX/Unix signals
	 *
	 * @param bool $allSignals [optional] <p>
	 * Whether to register handlers for all signals
	 * or only for SIGCHLD (we need it to know if
	 * child is dead).
	 * </p>
	 *
	 * @see Base::$signals
	 *
	 * @throws Exception if signal events are already registered
	 */
	private function registerEventSignals($allSignals = true)
	{
		/**
		 * Basically we register event handlers for all POSIX signals.
		 * In other case we need at least SIGCHLD handler to
		 * know if child is dead.
		 */
		$signals = $allSignals ? Base::$signals : array(SIGCHLD);

		/**
		 * Different callbacks for parent and child process
		 *
		 * @var callable $cb
		 */
		$class = get_called_class();
		$cb    = $this->isChild
				? array($this, '_evCbSignal')
				: array($class, '_mEvCbSignal');

		// Prepare slot
		if (empty(self::$eventsSignals[$slotName = (int)!$this->isChild])) {
			self::$eventsSignals[$slotName] = array();
		}

		$i    = 0;
		$base = self::$eventLoop;
		foreach ($signals as $signo => $name) {
			// Ignore SIGKILL and SIGSTOP - we can not handle them.
			if (SIGKILL === $signo || SIGSTOP === $signo) {
				continue;
			}

			// If handler is not already registered
			else if (!isset(self::$eventsSignals[$slotName][$signo])) {
				self::$eventsSignals[$slotName][$signo] = $ev = new Event();
				$ev->setSignal($signo, $cb)->setBase($base)->add();
				$i++;
			}
		}

		// @codeCoverageIgnoreStart
		/** @noinspection PhpUndefinedVariableInspection */
		$this->debug && $this->debug(
			self::D_INIT . "Signals event handlers are registered ($i, last - $name)"
		);
		// @codeCoverageIgnoreEnd
	}


	/**
	 * Called when a signal caught in child.
	 *
	 * @internal
	 *
	 * @param null  $fd
	 * @param int   $events
	 * @param array $arg
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	public function _evCbSignal($fd, $events, $arg)
	{
		// Signal's number and name
		$signo   = $arg[2];
		$signame = Base::getSignalName($signo);

		($debug = $this->debug) && $this->debug(
			self::D_IPC . " => Caught $signame ($signo) signal in child; "
			. "Event code [".sprintf('0x%x',$events)."]; descriptor [$fd]"
		);

		// Handler
		if (method_exists($this, $signame)) {
			$debug && $this->debug(
				self::D_IPC . "    <= Signal  $signame ($signo) has handler, call..."
			);
			$this->$signame($signo);
		}

		// Skipped signals:
		//  SIGCHLD  - Child processes terminates or stops
		//  SIGWINCH - Window size change
		//  SIGINFO  - Information request
		else if (SIGCHLD === $signo || SIGWINCH === $signo
		         || 28 /* SIGINFO */ === $signo
		) {
			$debug && $this->debug(
				self::D_IPC . "    => Skip $signame ($signo) signal"
			);
		}

		// Default action - shutdown
		else {
			$debug && $this->debug(
				self::D_WARN . "Unhandled signal $signame ($signo), exiting"
			);
			self::$eventLoop->loopBreak();
			$this->shutdown();
		}
	}

	/**
	 * Called when a signal caught in master.
	 *
	 * @internal
	 *
	 * @param null  $fd
	 * @param int   $events
	 * @param array $arg
	 *
	 * @throws Exception
	 */
	public static function _mEvCbSignal($fd, $events, $arg)
	{
		// Signal's number and name
		$signo   = $arg[2];
		$signame = Base::getSignalName($signo);

		// @codeCoverageIgnoreStart
		($debug = self::stGetDebug()) && self::stDebug(
			self::D_IPC . " <= Caught $signame ($signo) signal in parent; "
				. "Event code [".sprintf('0x%x',$events)."]; descriptor [$fd]",
			true
		);
		// @codeCoverageIgnoreEnd

		// Special SIGCHLD handler - for terminated child processes.
		$handled = false;
		if (SIGCHLD === $signo) {
			// Get forked childs status
			while (0 < $pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED)) {
				// @codeCoverageIgnoreStart
				$debug && self::stDebug(
					self::D_IPC . "    <= SIGCHLD is for pid #{$pid}."
						. " exit code: " . pcntl_wexitstatus($status),
					true
				);
				// @codeCoverageIgnoreEnd

				// Looking for a thread by PID
				if (isset(self::$threadsByPids[$pid])
					&& isset(self::$threads[$threadId = self::$threadsByPids[$pid]])
				) {
					$thread = self::$threads[$threadId];

					// @codeCoverageIgnoreStart
					$debug && self::stDebug(
						self::D_IPC . "    <= SIGCHLD is for thread #{$threadId}",
						$thread
					);
					// @codeCoverageIgnoreEnd

					// Stop worker and set state to WAIT
					$thread->lastErrorCode = self::ERR_DEATH;
					$thread->lastErrorMsg  = "Worker is dead";
					$thread->stopWorker();
				}

				// Thread is not found
				// @codeCoverageIgnoreStart
				else if ($debug) {
					$debug && self::stDebug(
						self::D_WARN . "    <= SIGCHLD target thread is not found",
						true
					);
				}
				// @codeCoverageIgnoreEnd
			}
			$handled = true;
			// @codeCoverageIgnoreStart
			$debug && self::stDebug(
				self::D_IPC . "    <= SIGCHLD handled",
				true
			);
			// @codeCoverageIgnoreEnd
		}

		// Call defined signal handlers
		$method  = "m{$signame}";
		foreach (self::$threadsByClasses as $threadClass => $threads) {
			if (method_exists($threadClass, $method)) {
				// @codeCoverageIgnoreStart
				$debug && self::stDebug(
					self::D_IPC . "    <= Signal ($signame) has handler, call... (class $threadClass)",
					true
				);
				// @codeCoverageIgnoreEnd
				$threadClass::$method($signo);
				$handled = true;
			}
		}

		// Signal is handled, return
		if ($handled) {
			return;
		}

		// Skipped signals:
		//  SIGWINCH - Window size change
		//  SIGINFO  - Information request
		else if (SIGWINCH === $signo || 28 /* SIGINFO */ === $signo) {
			// @codeCoverageIgnoreStart
			$debug && self::stDebug(
				self::D_IPC . "    <= Skip unhandled $signame ($signo) signal. ",
				true
			);
			// @codeCoverageIgnoreEnd
			return;
		}

		// Default action - shutdown (normally should not be called)
		// @codeCoverageIgnoreStart
		$debug && self::stDebug(
			self::D_WARN . "Unhandled signal $signame ($signo), exiting",
			true
		);
		self::$eventLoop->loopBreak();
		exit;
		// @codeCoverageIgnoreEnd
	}

	#endregion



	#region Shutdown

	/**
	 * Attempts to stop the thread worker process.
	 * Sets thread state to WAIT.
	 *
	 * @param bool $wait  Whether to wait for child death
	 * @param int  $signo SIGINT|SIGTSTP|SIGTERM|SIGSTOP|SIGKILL
	 *
	 * @return bool FALSE if worker is already stopped TRUE otherwise
	 */
	protected function stopWorker($wait = false, $signo = SIGTERM)
	{
		$debug  = $this->debug;
		$result = false;

		// Stop worker
		if ($this->isForked) {
			if ($this->isAlive()) {
				// @codeCoverageIgnoreStart
				if ($debug) {
					$do = (SIGKILL == $signo || SIGSTOP == $signo)
							? 'Kill'
							: 'Stop';
					$this->debug(
						self::D_INFO . "$do worker"
					);
				}
				// @codeCoverageIgnoreEnd

				$this->sendSignalToChild($signo);

				// @codeCoverageIgnoreStart
				if ($wait) {
					$debug && $this->debug(
						self::D_INFO . 'Waiting for the child'
					);
					$pid = $this->child_pid;
					// TODO: Always check that child is really dead (with timeout)?
					if (SIGSTOP === $signo) {
						$i = 15;
						usleep(1000);
						do {
							$st = pcntl_waitpid(
								$pid, $status, WNOHANG|WUNTRACED
							);
							if ($st) {
								break;
							}
							usleep(100000);
						} while (--$i > 0);
						if (!$st) {
							$debug && $this->debug(
								self::D_INFO . 'Waiting failed.. kill child'
							);
							return $this->stopWorker(
								true, SIGKILL
							);
						}
					} else {
						pcntl_waitpid(
							$pid, $status, WUNTRACED
						);
					}
				}
				// @codeCoverageIgnoreEnd
				$result = true;
			}
			// @codeCoverageIgnoreStart
			else if ($debug) {
				$this->debug(
					self::D_INFO . 'Worker is already stopped'
				);
			}
			// @codeCoverageIgnoreEnd

			// Cleanup
			$this->isForked = false;
			unset(self::$threadsByPids[$this->child_pid]);
			$this->child_pid = $this->pid;
		}

		// Cleanup. Buffered event can be damaged after child death
		if ($e = $this->masterEvent) {
			try {
				$e->readAllClean();
			} catch (\Exception $ex) {}
			$e->free();

			// @codeCoverageIgnoreStart
			$debug && $this->debug(
				self::D_INFO . 'Master pipe read event cleaned'
				. " (e{$e->id})"
			);
			// @codeCoverageIgnoreEnd

			$this->masterEvent = null;
		}

		// Enable waiting state
		if (!$this->isCleaning) {
			$this->setState(self::STATE_WAIT);
		}

		return $result;
	}

	/**
	 * Attempts to kill the thread worker process
	 *
	 * @param bool $wait
	 *
	 * @return bool TRUE on success and FALSE otherwise
	 */
	protected function killWorker($wait = false)
	{
		// @codeCoverageIgnoreStart
		return $this->stopWorker($wait, SIGKILL);
		// @codeCoverageIgnoreEnd
	}


	/**
	 * Shutdowns the child process properly.
	 *
	 * @see onShutdown
	 *
	 * @codeCoverageIgnore Called only in child (can't get coverage from another process)
	 */
	protected function shutdown()
	{
		if ($this->isChild) {
			// On shutdown hook
			($debug = $this->debug) && $this->debug(
				self::D_INIT . "Call on shutdown hook"
			);
			$this->onShutdown();

			$debug && $this->debug(self::D_INFO . 'Child exit (shutdown)');
			$this->cleanup();

			// TODO: Event dispatcher call for shutdown?
			class_exists('Aza\Kernel\Core', false)
					&& Core::stopApplication(true);

			exit;
		}
	}

	#endregion



	#region Debug

	/**
	 * Debug logging
	 *
	 * @param string $message
	 */
	protected function debug($message)
	{
		$this->debug && self::stDebug($message, $this);
	}

	/**
	 * Static debug logging
	 *
	 * @param string      $message
	 * @param Thread|bool $thread  Set to true for non-one-thread debug
	 */
	protected static function stDebug($message, $thread = null)
	{
		// Get thread id (and check debug mode)
		// @codeCoverageIgnoreStart
		if (true === $thread) {
			$id = '*';
		} else if ($thread) {
			$id = $thread->id;
		} else if (self::stGetDebug($thread)) {
			$id = '-';
		} else {
			return;
		}
		// @codeCoverageIgnoreEnd

		// Prepare message
		$time = Base::getTimeForLog();
		if (true === $thread) {
			// non-one-thread debug
			// @codeCoverageIgnoreStart
			$role = '-';
			// @codeCoverageIgnoreEnd
		} else if ($thread) {
			// Master|Worker
			$role = $thread->isChild ? 'W' : '-';
		} else {
			// Unknown (called in destructor or something similar)
			// @codeCoverageIgnoreStart
			$role = '~';
			// @codeCoverageIgnoreEnd
		}
		$pid = posix_getpid();
		$message = "<small>{$time} [debug] [T{$id}.{$role}] "
		           ."#{$pid}:</> <info>{$message}</>";

		// Output message
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

	/**
	 * Returns instance debug status for static calls
	 *
	 * @param Thread $thread
	 *
	 * @return bool
	 */
	private static function stGetDebug(&$thread = null)
	{
		static $debug;

		if (empty(self::$threadsByClasses[$class = get_called_class()])) {
			if (!self::$threads) {
				// Couldn't find threads of type $class
				// Called in destructor or something similar
				// @codeCoverageIgnoreStart
				return $debug;
				// @codeCoverageIgnoreEnd
			}
			$thread = end(self::$threads);
		} else {
			$thread = end(self::$threadsByClasses[$class]);
		}

		return $debug = $thread->debug;
	}

	#endregion
}


// IPC data transfer mode
function_exists('igbinary_serialize')
	&& Thread::$ipcDataMode = Thread::IPC_IGBINARY;

// Forks
Thread::$useForks = Base::$hasForkSupport && Base::$hasLibevent;
