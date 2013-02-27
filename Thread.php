<?php

namespace Aza\Components\Cli\Thread;
use Aza\Components\Cli\Base;
use Aza\Components\LibEvent\EventBase;
use Aza\Components\LibEvent\Event;
use Aza\Components\LibEvent\EventBuffer;
use Aza\Components\Socket\Socket;
use Aza\Components\Socket\ASocket;
use Aza\Components\Cli\Thread\Exceptions\Exception;

/**
 * AzaThread (old name - CThread).
 * Powerfull thread emulation with forks and libevent.
 * Can work in synchronious mode without forks for compatibility.
 *
 * @project Anizoptera CMF
 * @package system.AzaThread
 * @version $Id: Thread.php 3253 2012-04-10 09:35:33Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
abstract class Thread
{
	#region Constants

	// Thread states
	const STATE_TERM = 1;
	const STATE_INIT = 2;
	const STATE_WAIT = 3;
	const STATE_WORK = 4;

	// Types of IPC packets
	const P_STATE  = 0x01;
	const P_JOB    = 0x02;
	const P_EVENT  = 0x04;
	const P_DATA   = 0x08;
	const P_SERIAL = 0x10;

	// Types of IPC data transfer modes
	const IPC_IGBINARY  = 1; // Igbinary serialization		(8th, 6625 jps)
	const IPC_SERIALIZE = 2; // Native PHP serialization	(8th, 6501 jps)

	// Timer names
	const TIMER_BASE = 'thread:base:';
	const TIMER_WAIT = 'thread:wait:';

	// Debug prefixes
	const D_INIT  = 'INIT: ';
	const D_WARN  = 'WARN: ';
	const D_INFO  = 'INFO: ';
	const D_IPC   = 'IPC: ';   // IPC
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


	#region Internal static properties

	/**
	 * All started threads count
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
	 * Array of waiting threads (id => id)
	 *
	 * @var int[]
	 */
	private static $waitingThreads = array();

	/**
	 * Signal events
	 *
	 * @var Event[]
	 */
	private static $eventsSignals = array();

	/**
	 * Event base
	 *
	 * @var EventBase
	 */
	private static $base;

	#endregion


	#region Internal properties

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
	 * Thread state
	 *
	 * @var int
	 */
	private $state;

	/**
	 * Whether waiting loop is enabled
	 */
	private $waiting = false;

	/**
	 * Whether event locking is enabled
	 */
	private $eventLocking = false;

	/**
	 * Cleaning flag
	 */
	private $cleaning = false;

	#endregion


	#region Internal protected properties

	/**
	 * Owner thread pool
	 *
	 * @var ThreadPool
	 */
	protected $pool;

	/**
	 * Internal thread id
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
	 * Parent process pid
	 *
	 * @var int
	 */
	protected $parent_pid;

	/**
	 * Child process pid
	 *
	 * @var int
	 */
	protected $child_pid;

	/**
	 * Whether child is already forked
	 */
	protected $isForked = false;

	/**
	 * Whether current process is child
	 */
	protected $isChild = false;

	/**
	 * Arguments for the processing
	 */
	protected $params = array();

	/**
	 * Last processing result
	 */
	protected $result;

	/**
	 * The status of the success of the task
	 */
	protected $success = false;

	#endregion


	#region Settings. Overwrite on child class to set.

	/**
	 * File for shared memory key generation.
	 * Thread class file by default.
	 */
	protected $file;

	/**
	 * Whether the thread will wait for next tasks
	 */
	protected $multitask = true;

	/**
	 * Whether to listen for signals in master.
	 * SIGCHLD is always listened.
	 */
	protected $listenMasterSignals = true;

	/**
	 * Perform pre-fork, to avoid wasting resources later
	 */
	protected $prefork = true;

	/**
	 * Wait for the preforking child
	 */
	protected $preforkWait = false;

	/**
	 * Worker initialization timeout (in seconds).
	 * Set it to less than one, to disable.
	 */
	protected $timeoutMasterInitWait = 3;

	/**
	 * Maximum master timeout to wait for the job results (in seconds).
	 * Set it to less than one, to disable.
	 */
	protected $timeoutMasterResultWait = 5;

	/**
	 * Maximum worker job waiting timeout. After it spawned child will die.
	 * Set it to less than one, to disable.
	 */
	protected $timeoutWorkerJobWait = -1;

	/**
	 * Worker interval for master checks (in seconds).
	 */
	protected $intervalWorkerChecks = 15;

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
	 */
	public $debug = false;

	#endregion



	/**
	 * Initializes base parameters
	 *
	 * @throw Exception if can't wait for the preforked thread
	 *
	 * @param bool             $debug Whether to show debugging information
	 * @param string           $pName Thread worker process name
	 * @param ThreadPool $pool  Thread pool
	 */
	public function __construct($debug = false, $pName = null, $pool = null)
	{
		$this->id = $id = ++self::$threadsCount;
		$class = get_called_class();

		self::$threadsByClasses[$class][$id] =
		self::$threads[$id] = $this;

		$debug && $this->debug       = true;
		$pool  && $this->pool        = $pool;
		$pName && $this->processName = $pName;

		$this->pid =
		$this->parent_pid =
		$this->child_pid = posix_getpid();

		$this->setState(self::STATE_INIT);

		$forks = self::$useForks;

		if ($debug = $this->debug) {
			$message = 'Thread of type "'.get_called_class().'" created.';
			$this->debug(self::D_INIT . $message);
			if (!$forks) {
				$debug && $this->debug(
					self::D_WARN . 'Sync mode (you need Forks and LibEvent support'
					.' and CLI sapi to use threads asynchronously)'
				);
			}
		}

		// Forks preparing
		if ($forks) {
			// Init shared master event base
			if (!self::$base) {
				self::$base = Base::getEventBase();
				$debug && $this->debug(self::D_INIT . 'Master event base initialized');
			}
			$base = self::$base;

			// Master signals
			if (!self::$eventsSignals) {
				if ($this->listenMasterSignals) {
					$this->registerEventSignals();
				} else {
					$signo = SIGCHLD;
					$e = new Event();
					$e->setSignal($signo, array(get_called_class(), '_mEvCbSignal'))
							->setBase($base)
							->add();
					self::$eventsSignals[$signo] = $e;
					$debug && $this->debug(
						self::D_INIT . 'Master SIGCHLD event signal handler initialized'
					);
				}
			}

			// Master timer
			$timer_name = self::TIMER_BASE . $this->id;
			$base->timerAdd(
				$timer_name, 0,
				array($this, '_mEvCbTimer'),
				null, false
			);
			$this->eventsTimers[] = $timer_name;
			$debug && $this->debug(self::D_INIT . "Master timer ($timer_name) added");
		}

		// On load hook
		$this->onLoad();

		// Preforking
		if ($forks && $this->prefork) {
			$debug && $this->debug(self::D_INFO . 'Preforking');
			if ($this->forkThread()) {
				// Parent
				if (($interval = $this->timeoutMasterInitWait) > 0) {
					$timer_name = self::TIMER_BASE . $this->id;
					self::$base->timerStart(
						$timer_name,
						$interval,
						self::STATE_INIT
					);
					$debug && $this->debug(
						self::D_INFO . "Master timer ($timer_name) started for INIT ($interval sec)"
					);
				}
				$this->preforkWait && $this->wait();
			} else {
				// Child
				$this->evWorkerLoop(true);
				$debug && $this->debug(self::D_INFO . 'Preforking: end of loop, exiting');
				$this->shutdown();
			}
		} else {
			$this->setState(self::STATE_WAIT);
		}

		// On fork hook
		$forks || $this->onFork();
	}


	/**
	 * Destruction
	 */
	public function __destruct()
	{
		$this->debug(self::D_INFO . 'Destructor');
		$this->cleanup();
	}

	/**
	 * Thread cleanup
	 */
	public function cleanup()
	{
		if ($this->cleaning) {
			return;
		}
		$this->cleaning = true;
		$this->state = self::STATE_TERM;

		($debug = $this->debug) && $this->debug(self::D_CLEAN . 'Cleanup');

		$id       = $this->id;
		$class    = get_called_class();
		$isMaster = !$this->isChild;

		// Threads pool
		if ($pool = &$this->pool) {
			unset(
				$pool->threads[$id],
				$pool->waiting[$id],
				$pool->working[$id],
				$pool->initializing[$id]
			);
			$pool->threadsCount--;
			$this->pool = null;
		}

		// Child process
		$base = self::$base;
		if ($isMaster && $this->isForked) {
			$this->stopWorker(true);
			$debug && $this->debug(self::D_CLEAN . 'Worker process terminated');

			// Check for non-triggered events
			$base && $base->resource && $base->loop(EVLOOP_NONBLOCK);
		}

		// Threads storage
		unset(self::$threads[$id]);
		unset(self::$threadsByClasses[$class][$id]);
		if (empty(self::$threadsByClasses[$class])) {
			unset(self::$threadsByClasses[$class]);
		}

		// Events
		if ($base && $base->resource) {
			foreach ($this->eventsTimers as $t) {
				$base->timerDelete($t);
			}
		}
		if (!$isMaster || !self::$threads) {
			foreach (self::$eventsSignals as $ev) {
				$ev->free();
			}
			self::$eventsSignals = array();
		}
		$this->eventsTimers = array();
		$debug && $this->debug(self::D_CLEAN . 'All events freed');

		// Master event
		if ($this->masterEvent) {
			$this->masterEvent->free();
			$this->masterEvent = null;
			$debug && $this->debug(self::D_CLEAN . 'Master event freed');
		}

		// Child event
		if ($this->childEvent) {
			$this->childEvent->free();
			$this->childEvent = null;
			$debug && $this->debug(self::D_CLEAN . 'Child event freed');
		}

		// Pipes
		if ($this->pipes) {
			$this->pipes[1]->close();
			// It's already closed after forking
			$isMaster && $this->pipes[0]->close();
			$this->pipes = null;
			$debug && $this->debug(self::D_CLEAN . 'Pipes destructed');
		}

		// Last master thread cleanup
		if ($isMaster && !self::$threads) {
			self::$base = null;
		}

		// Child cleanup
		else {
			// Event base cleanup
			self::$base = null;
			Base::cleanEventBase();
		}
	}


	/**
	 * Thread forking
	 *
	 * @throws Exception
	 *
	 * @return bool TRUE in parent, FALSE in child
	 */
	private function forkThread()
	{
		// Checks
		if (!self::$useForks) {
			throw new Exception("Can't fork thread. Forks are not supported.");
		} else if ($this->isForked) {
			throw new Exception("Can't fork thread. It is already forked.");
		}

		// Worker pipes
		$debug = $this->debug;
		if (!$this->pipes) {
			$this->pipes = Socket::pair();
			$debug && $this->debug(self::D_INIT . 'Pipes initialized');
		}

		// Forking
		$debug && $this->debug(self::D_INIT . 'Forking');
		$this->isForked = true;
		$pid = Base::fork();

		// In parent
		if ($pid) {
			self::$threadsByPids[$pid] = $this->id;
			$this->child_pid = $pid;
			$debug && $this->debug(self::D_INIT . "Forked: parent ({$this->pid})");

			// Master event
			if (!$this->masterEvent) {
				$this->masterEvent = $ev = new EventBuffer(
					$this->pipes[0]->resource,
					array($this, '_mEvCbRead'),
					null,
					function(){}
				);
				$ev->setBase(self::$base)->setPriority()->enable(EV_READ);
				$debug && $this->debug(self::D_INIT . 'Master event initialized');
			}

			return true;
		}

		// In child
		$this->isChild = true;
		$this->pid =
		$this->child_pid =
		$pid = posix_getpid();
		$debug && $this->debug(self::D_INIT . "Forked: child ($pid)");

		// Closing master pipe
		// It is not needed in the child
		$this->pipes[0]->close();
		unset($this->pipes[0]);

		// Cleanup parent events
		$this->eventsTimers = self::$eventsSignals = array();

		// Child event
		if (!$this->childEvent) {
			$this->childEvent = $ev = new EventBuffer(
				$this->pipes[1]->resource,
				array($this, '_wEvCbRead'),
				null,
				function(){}
			);
			$ev->setBase(self::$base)->setPriority()->enable(EV_READ);
			$debug && $this->debug(self::D_INIT . 'Worker event initialized');
		}

		// Process name
		if ($name = $this->processName) {
			$name .= ' (aza-php): worker';
			Base::setProcessTitle($name);
			$debug && $this->debug(
				self::D_INIT . "Child process name is changed to: $name"
			);
		}

		return false;
	}

	/**
	 * Starts processing
	 *
	 * @return Thread
	 */
	public function run()
	{
		if (!$this->isWaiting()) {
			throw new Exception(
				"Can't run thread. It is not in waiting state."
				." You need to use 'wait' method on thread instance"
				." after each run and before first run if 'preforkWait'"
				." property is not overrided to TRUE and you don't use pool."
			);
		}

		($debug = $this->debug) && $this->debug(self::D_INFO . 'Job start');
		$this->setState(self::STATE_WORK);
		$this->result  = null;
		$this->success = false;

		$args = func_get_args();

		// Emulating thread with fork
		if (self::$useForks) {
			// Thread is alive
			if ($this->isAlive()) {
				$debug && $this->debug(
					self::D_INFO . "Child is already running ({$this->child_pid})"
				);
				$this->sendPacketToChild(self::P_JOB, $args ?: null);
				$this->startMasterWorkTimeout();
			}
			// Forking
			else {
				if ($this->forkThread()) {
					// Parent
					$this->startMasterWorkTimeout();
				} else {
					// Child
					$this->setParams($args);
					$res = $this->process();
					$this->setResult($res);
					$this->multitask && $this->evWorkerLoop();
					$debug && $this->debug(
						self::D_INFO . 'Simple end of work, exiting'
					);
					$this->shutdown();
				}
			}
		}
		// Forkless compatibility
		else {
			$this->setParams($args);
			$res = $this->process();
			$this->setResult($res);
			$debug && $this->debug(self::D_INFO . 'Sync job ended');
		}

		return $this;
	}

	/**
	 * Prepares and starts worker event loop
	 *
	 * @param bool $setState Set waiting state
	 *
	 * @throws Exception
	 */
	private function evWorkerLoop($setState = false)
	{
		($debug = $this->debug) && $this->debug(self::D_INIT . "Preparing worker loop");

		if (!$this->isChild) {
			throw new Exception('Can\'t start child loop in parent');
		}

		$this->registerEventSignals();

		$base = self::$base;

		// Worker timer to check master process
		$timer = self::TIMER_BASE;
		$timerCb = array($this, '_wEvCbTimer');
		($timeout = $this->intervalWorkerChecks) > 0 || $timeout = 15;
		$base->timerAdd($timer, $timeout, $timerCb);
		$this->eventsTimers[] = $timer;
		$debug && $this->debug(
			self::D_INIT . "Worker timer ($timer) event initialized and set to $timeout"
		);

		// Worker wait timer
		if (0 < $timeout = $this->timeoutWorkerJobWait) {
			$timer = self::TIMER_WAIT;
			$base->timerAdd($timer, $timeout, $timerCb);
			$this->eventsTimers[] = $timer;
			$debug && $this->debug(
				self::D_INIT . "Worker wait timer ($timer) event initialized and set to $timeout"
			);
		}

		$setState && $this->setState(self::STATE_WAIT);

		$debug && $this->debug(self::D_INFO . 'Loop (worker) start');
		$base->loop();
		$debug && $this->debug(self::D_INFO . 'Loop (worker) end');
	}



	#region Methods for overriding!

	/**
	 * Hook called after the thread initialization,
	 * but before forking!
	 */
	protected function onLoad() {}

	/**
	 * Hook called after the thread forking (in child process)
	 */
	protected function onFork() {}

	/**
	 * Main processing.
	 *
	 * Use {@link getParam} method to get processing parameters
	 *
	 * @return mixed returned result will be available via
	 * {@link getResult} in the master process
	 */
	abstract protected function process();

	#endregion



	#region Master waiting

	/**
	 * Waits until the thread becomes waiting
	 *
	 * @throws Exception
	 *
	 * @return Thread
	 */
	public function wait()
	{
		if (self::$useForks && !$this->isWaiting()) {
			$this->debug(self::D_INFO . 'Loop (master waiting) start');
			$this->waiting = true;
			self::evMasterLoop();
			if (!$this->isWaiting()) {
				throw new Exception('Could not wait for the thread');
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
			self::stDebug(self::D_INFO . 'Loop (master theads waiting) start');
			$threadIds = (array)$threadIds;
			$threadIds = array_combine($threadIds, $threadIds);
			self::$waitingThreads = $threadIds;
			self::evMasterLoop();
			self::$waitingThreads = array();
		}
	}

	/**
	 * Starts master event loop
	 */
	private static function evMasterLoop()
	{
		($debug = self::stGetDebug()) && self::stDebug(self::D_INFO . 'Loop (master) start');
		self::$base->loop();
		$debug && self::stDebug(self::D_INFO . 'Loop (master) end');
	}

	/**
	 * Starts master work timeout
	 */
	private function startMasterWorkTimeout()
	{
		if (0 < $interval = $this->timeoutMasterResultWait) {
			$timer_name = self::TIMER_BASE . $this->id;
			self::$base->timerStart($timer_name, $interval, self::STATE_WORK);
			$this->debug(self::D_INFO . "Master timer ($timer_name) started for WORK ($interval sec)");
		}
	}

	#endregion



	#region Event system

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
	 * <br><tt>function(string $event_name, mixed $event_data, mixed $event_arg){}</tt>
	 * </p>
	 * @param mixed $arg <p>
	 * Additional argument for callback.
	 * </p>
	 */
	public function bind($event, $listener, $arg = null)
	{
		// Bind is allowed only in parent
		if (!$this->isChild) {
			if (!isset($this->listeners[$event])) {
				$this->listeners[$event] = array();
			}
			$this->listeners[$event][] = array($listener, $arg);
			$this->debug(self::D_INFO . "New listener binded on event [$event]");
		}
	}

	/**
	 * Notifies all listeners of a given event.
	 *
	 * @see bind
	 *
	 * @param string $event An event name
	 * @param mixed	 $data	Event data for callback
	 */
	public function trigger($event, $data = null)
	{
		($debug = $this->debug) && $this->debug(
			self::D_INFO . "Triggering event [$event]"
		);

		// Child
		if ($this->isChild) {
			$this->sendPacketToParent(self::P_EVENT, $event, $data);
			if (isset($data) && $this->eventLocking) {
				$debug && $this->debug(
					self::D_INFO . "Locking thread - waiting for event read confirmation"
				);
				$this->waiting = true;
			}
		}
		// Parent
		else {
			if (!empty($this->listeners[$event])) {
				/** @var $cb callback */
				foreach ($this->listeners[$event] as $l) {
					list($cb, $arg) = $l;
					if ($cb instanceof \Closure) {
						$cb($event, $data, $arg);
					} else {
						call_user_func($cb, $event, $data, $arg);
					}
				}
			}
			if ($pool = $this->pool) {
				$pool->trigger($event, $this->id, $data);
			}
		}
	}

	#endregion



	#region Getters/Setters

	/**
	 * Returns unique thread id
	 *
	 * @return int
	 */
	public function getId()
	{
		return $this->id;
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
	 * Returns thread state
	 *
	 * @return int
	 */
	public function getState()
	{
		return $this->state;
	}

	/**
	 * Returns thread state name
	 *
	 * @param int $state Integer state value. Current state will be used instead
	 *
	 * @return string
	 */
	public function getStateName($state = null)
	{
		isset($state) || $state = $this->state;
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
	 * Returns processing parameter
	 *
	 * @param int   $index   Parameter index
	 * @param mixed $default Default value if parameter isn't set
	 *
	 * @return mixed
	 */
	protected function getParam($index, $default = null)
	{
		return isset($this->params[$index]) ? $this->params[$index] : $default;
	}

	/**
	 * Returns result
	 *
	 * @return mixed
	 */
	private function isWaiting()
	{
		return $this->state === self::STATE_WAIT;
	}

	/**
	 * Checks if the child process is alive
	 *
	 * @return bool TRUE if child is alive FALSE otherwise
	 */
	private function isAlive()
	{
		return !self::$useForks
			   || $this->isForked
				  && 0 === pcntl_waitpid($this->child_pid, $s, WNOHANG);
	}


	/**
	 * Sets params for processing
	 *
	 * @param array $args
	 */
	private function setParams($args)
	{
		$this->params = $args && is_array($args)
				? $args
				: array();

		if ($this->debug) {
			$msg = $this->isChild ? 'Async processing' : 'Processing';
			if ($args && is_array($args)) {
				$msg .= ' with args';
			}
			$this->debug(self::D_INFO . $msg);
		}
	}

	/**
	 * Sets processing result
	 *
	 * @param mixed $res
	 */
	private function setResult($res = null)
	{
		$this->debug(self::D_INFO . 'Setting result');

		// Send result packet to parent
		if ($this->isChild) {
			$this->sendPacketToParent(self::P_JOB, null, $res);
		}

		// Change result
		else {
			if ($pool = $this->pool) {
				$pool->results[ $this->id ] = $res;
			}
			$this->result = $res;
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
		$state = (int)$state;

		if ($debug = $this->debug) {
			$this->debug(
				self::D_INFO . 'Changing state to: "'
				. $this->getStateName($state) . "\" ($state)"
			);
		}

		// Send state packet to parent
		if ($this->isChild) {
			$this->sendPacketToParent(self::P_STATE, $state);
		}

		// Change state
		else {
			$this->state = $state;
			$wait = (self::STATE_WAIT === $state);
			$threadId = $this->id;

			// Pool processing
			if ($pool = $this->pool) {
				// Waiting
				if ($wait) {
					if (!$this->success && empty($pool->initializing[$threadId])
						&& isset(self::$waitingThreads[$threadId])
					) {
						$pool->failed[$threadId] = $threadId;
					}
					unset(
						$pool->working[$threadId],
						$pool->initializing[$threadId]
					);
					$pool->waiting[$threadId] = $threadId;
				}
				// Other states
				else {
					unset($pool->waiting[$threadId]);
					if ($state & self::STATE_WORK) {
						$pool->working[$threadId] = $threadId;
						unset($pool->initializing[$threadId]);
					} else if ($state & self::STATE_INIT) {
						$pool->initializing[$threadId] = $threadId;
						unset($pool->working[$threadId]);
					}
				}
			}

			// Waiting
			if ($wait && self::$useForks) {
				$base = self::$base;
				$timer_name = self::TIMER_BASE . $threadId;
				$base->timerStop($timer_name);
				$debug && $this->debug(self::D_INFO . "Master timer ($timer_name) stopped");

				// One waiting thread
				if ($this->waiting) {
					$this->waiting = false;
					$debug && $this->debug(self::D_INFO . 'Loop (master waiting) end');
					$base->loopExit();
				}

				// Several waiting threads
				else if (isset(self::$waitingThreads[$threadId])) {
					$debug && $this->debug(self::D_INFO . 'Loop (master theads waiting) end');
					$base->loopExit();
				}
			}
		}
	}

	#endregion



	#region Pipe events callbacks

	/**
	 * Worker read event callback
	 *
	 * @see evWorkerLoop
	 * @see EventBuffer::setCallback
	 *
	 * @access private
	 *
	 * @throws Exception
	 *
	 * @param resource $buf  Buffered event
	 * @param array    $args
	 */
	public function _wEvCbRead($buf, $args)
	{
		($debug = $this->debug) && $this->debug(
			self::D_INFO . 'Worker pipe read event'
		);

		// Receive packets
		$packets = $this->readPackets(
			$args[0],
			$this->childReadSize,
			$this->childBuffer,
			$this->childPacket
		);
		if (!$packets) {
			return;
		}

		// Restart waiting timeout
		$base = self::$base;
		if ($base->timerExists($timer = self::TIMER_WAIT)) {
			$base->timerStart($timer);
		}

		foreach ($packets as $p) {
			$packet = $p['packet'];
			$data   = $p['data'];

			$debug && $this->debug(self::D_IPC . " => Packet: [$packet]");

			// Job packet
			if ($packet & self::P_JOB) {
				if ($packet & self::P_DATA) {
					$data = $this->peekPacketData($data, $packet);
					$debug && $this->debug(self::D_IPC . ' => Packet: job with arguments');
				} else {
					$debug && $this->debug(self::D_IPC . ' => Packet: job');
					$data = array();
				}
				$this->setParams($data);
				$this->setResult($this->process());
			}

			// Event read confirmation
			else if ($packet & self::P_EVENT) {
				$debug && $this->debug(
					self::D_IPC . ' => Packet: event read confirmation. Unlocking thread'
				);
				$this->waiting = false;
			}

			// Unknown packet
			else {
				$base->loopBreak();
				throw new Exception("Unknown packet [$packet]");
			}
		}
	}

	/**
	 * Worker timer event callback
	 *
	 * @see evWorkerLoop
	 * @see EventBase::timerAdd
	 *
	 * @access private
	 *
	 * @param string $name
	 */
	public function _wEvCbTimer($name)
	{
		$die = false;

		// Worker wait
		if ($name === self::TIMER_WAIT) {
			$this->debug(self::D_WARN . 'Timeout (worker waiting) exceeded, exiting');
			$die = true;
		}
		// Worker check
		elseif (!Base::getProcessIsAlive($this->parent_pid)) {
			$this->debug(self::D_WARN . 'Parent is dead, exiting');
			$die = true;
		}

		if ($die) {
			self::$base->loopBreak();
			$this->shutdown();
		}
	}


	/**
	 * Master read event callback
	 *
	 * @see __construct
	 * @see EventBuffer::setCallback
	 *
	 * @access private
	 *
	 * @param resource $buf  Buffered event
	 * @param array    $args
	 */
	public function _mEvCbRead($buf, $args)
	{
		($debug = $this->debug) && $this->debug(
			self::D_INFO . 'Master pipe read event'
		);

		$packets = $this->readPackets(
			$args[0],
			$this->masterReadSize,
			$this->masterBuffer,
			$this->masterPacket
		);
		if (!$packets) {
			return;
		}

		foreach ($packets as $p) {
			$threadId = $this->id;
			$packet   = $p['packet'];
			$value    = $p['value'];
			$data     = $p['data'];

			$debug && $this->debug(self::D_IPC . " <= Packet: [$packet]");

			if (!isset(self::$threads[$threadId])) {
				self::$base->loopBreak();
				throw new Exception(
					"Packet [$packet:$value] for unknown thread #$threadId"
				);
			}

			$thread = self::$threads[$threadId];

			// Packet data
			if (self::P_DATA & $packet) {
				$data = $this->peekPacketData($data, $packet);
				$debug && $this->debug(self::D_IPC . ' <= Packet: data received');
			} else {
				$data = null;
			}

			// State packet
			if (self::P_STATE & $packet) {
				$debug && $this->debug(self::D_IPC . ' <= Packet: state');
				$thread->setState($value);
			}

			// Event packet
			else if (self::P_EVENT & $packet) {
				$debug && $this->debug(self::D_IPC . ' <= Packet: event');
				if ($thread->eventLocking) {
					$debug && $this->debug(
						self::D_IPC . " => Sending event read confirmation"
					);
					$thread->sendPacketToChild(self::P_EVENT);
				}
				$thread->trigger($value, $data);
			}

			// Job packet
			else if (self::P_JOB & $packet) {
				$debug && $this->debug(self::D_IPC . ' <= Packet: job ended');
				$thread->setResult($data);
			}

			// Unknown packet
			else {
				self::$base->loopBreak();
				throw new Exception("Unknown packet [$packet]");
			}
		}
	}

	/**
	 * Master timer event callback
	 *
	 * @see __construct
	 * @see EventBase::timerAdd
	 *
	 * @access private
	 *
	 * @param string $name      Timer name
	 * @param mixed  $arg       Additional timer argument
	 * @param int    $iteration Iteration number
	 *
	 * @return bool
	 */
	public function _mEvCbTimer($name, $arg, $iteration)
	{
		$this->debug(self::D_WARN . "Master timeout exceeded ({$name} - {$arg})");

		$killed = $this->stopWorker();

		$arg = (int)$arg;
		if (self::STATE_WORK & $arg) {
			if ($killed) {
				throw new Exception(
					"Exceeded timeout: thread work ({$this->timeoutMasterResultWait} sec.)"
				);
			}
		} else if (self::STATE_INIT & $arg) {
			throw new Exception(
				"Exceeded timeout: thread initialization ({$this->timeoutMasterInitWait} sec.)"
			);
		} else {
			throw new Exception("Unknown timeout ({$name} ({$iteration}) - {$arg})");
		}
	}

	#endregion



	#region Working with data packets

	/**
	 * Sends packet to parent
	 *
	 * @param int    $packet Integer packet type (see self::P_* constants)
	 * @param string $value  Packet value (without ":" character)
	 * @param mixed  $data   Mixed packet data
	 */
	private function sendPacketToParent($packet, $value = '', $data = null)
	{
		$debug = $this->debug;

		// Waiting for read confirmation
		if ($this->waiting) {
			if ($debug) {
				$this->debug(self::D_INFO . "Thread is locked. Waiting for read confirmation");
				$this->debug(self::D_INFO . 'Loop (worker) start once');
			}
			self::$base->loop(EVLOOP_ONCE);
			$debug && $this->debug(self::D_INFO . 'Loop (worker) end once');
			if ($this->waiting) {
				$error = "Can't send packet to parent. Child is waiting for event read confirmation.";
				$debug && $this->debug(self::D_WARN . $error);
				self::$base->loopBreak();
				throw new Exception($error);
			}
		}

		$this->childEvent->write(
			$this->preparePacket($packet, $data, $value, false)
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
		if (!$curPacket && '' != $buffer) {
			$error = "Unexpected read buffer";
			$debug && $this->debug(self::D_WARN . $error);
			self::$base->loopBreak();
			throw new Exception($error);
		}

		$buf = '';
		while ('' != $str = $e->read($maxReadSize)) {
			$buf .= $str;
		}
		if ('' === $buf) {
			return array();
		}
		$debug && $this->debug(
			self::D_IPC . "    Read ".strlen($buf)."b; ".strlen($buffer)."b in buffer"
		);
		$buffer .= $buf;
		unset($str, $buf);


		$packets = array();
		do {
			if (!$curPacket) {
				if ("\x80" !== $buffer[0]) {
					$error = "Packet must start with 0x80 character";
				} else if (strlen($buffer) < 7) {
					$error = "Packet must contain at least 7 characters";
				}
				if (!empty($error)) {
					$debug && $this->debug(self::D_WARN . $error);
					self::$base->loopBreak();
					throw new Exception($error);
				}
				$curPacket = unpack('Cpacket/CvalueLength/NdataLength', substr($buffer, 1, 6));
				$curPacket['value'] =
				$curPacket['data'] = '';
				$buffer = substr($buffer, 7);
				$debug && $this->debug(
					self::D_IPC . "    Packet started [{$curPacket['packet']}]; "
					.($curPacket['dataLength']-$curPacket['valueLength'])."b data;"
					." {$curPacket['valueLength']}b value"
				);
			} else {
				$debug && $this->debug(
					self::D_IPC . "    Packet continue [{$curPacket['packet']}];"
					." {$curPacket['dataLength']}b data;"
					." {$curPacket['valueLength']}b value;"
					." ".strlen($buffer)."b read"
				);
			}

			if ($dataLen = $curPacket['dataLength']) {
				if (strlen($buffer) < $dataLen) {
					return $packets;
				}
				if ($valLen = $curPacket['valueLength']) {
					$curPacket['value'] = substr($buffer, 0, $valLen);
				}
				$_dataLen = $dataLen;
				if ($dataLen -= $valLen) {
					$curPacket['data'] = substr($buffer, $valLen, $dataLen);
				}
				$buffer = substr($buffer, $_dataLen);
			} else {
				$valLen = 0;
			}

			// Debugging
			if ($debug) {
				$rDataLen = strlen($curPacket['data']);
				$rValLen  = strlen($curPacket['value']);
				if ($dataLen != $rDataLen) {
					$error = "Packet data length header ({$dataLen})"
						." does not match the actual length of the data"
						." ({$rDataLen})";
				} else if ($valLen != $rValLen) {
					$error = "Packet value length header ({$valLen})"
						." does not match the actual length of the value"
						." ({$rValLen})";
				}
				if (!empty($error)) {
					$this->debug(self::D_WARN . $error);
					self::$base->loopBreak();
					throw new Exception($error);
				}
				$this->debug(
					self::D_IPC . "    Packet completed [{$curPacket['packet']}];"
					." {$dataLen}b data; {$valLen}b value;"
					." ".strlen($buffer)."b left in buffer"
				);
			}

			$packets[] = $curPacket;
			$curPacket = null;

		} while($buffer);

		$debug && $this->debug(
			self::D_IPC . '    Packets received: ' . count($packets) . ''
		);

		return $packets;
	}

	/**
	 * Peeks packet data
	 *
	 * @throws Exception
	 *
	 * @param mixed $data   Raw data
	 * @param int   $packet Packet type
	 *
	 * @return string
	 */
	private function peekPacketData($data, $packet)
	{
		$mode = self::$ipcDataMode;

		// Serialization
		if (($igbinary = self::IPC_IGBINARY === $mode) || self::IPC_SERIALIZE === $mode) {
			if (self::P_SERIAL & $packet) {
				// Igbinary/PHP unserialize
				$data = $igbinary
						? igbinary_unserialize($data)
						: unserialize($data);
			}
		} else {
			$error = "Unknown IPC mode ($mode).";
			$this->debug(self::D_WARN . $error);
			self::$base->loopBreak();
			throw new Exception($error);
		}

		return $data;
	}

	/**
	 * Prepares IPC packet
	 *
	 * @throws Exception
	 *
	 * @param int    $packet  Integer packet type (see self::P_* constants)
	 * @param string $data    Packet data
	 * @param string $value   Packet value
	 * @param bool   $toChild Packet is for child
	 *
	 * @return string
	 */
	private function preparePacket($packet, $data, $value = '', $toChild = true)
	{
		// Prepare data
		$postfix   = '';
		if (isset($data)) {
			$packet |= self::P_DATA;
			$mode    = self::$ipcDataMode;
			$postfix = ' (with data)';

			if (($igbinary = self::IPC_IGBINARY === $mode) || self::IPC_SERIALIZE === $mode) {
				if (is_scalar($data)) {
					$data = is_bool($data) ? (string)(int)$data : (string)$data;
				} else {
					$packet |= self::P_SERIAL;
					// Igbinary/PHP serialize
					$data = $igbinary
							? igbinary_serialize($data)
							: serialize($data);
				}
			} else {
				$error = "Unknown IPC mode ($mode).";
				$this->debug(self::D_WARN . $error);
				self::$base->loopBreak();
				throw new Exception($error);
			}
		} else {
			$data = '';
		}

		// Check value
		if (0xFF < $valLength = strlen($value = (string)$value)) {
			$error = "Packet value is too long ($valLength). Maximum length is 255 characters.";
			$this->debug(self::D_WARN . $error);
			self::$base->loopBreak();
			throw new Exception($error);
		}

		// Build packet
		$dataLen = strlen($data);
		$_packet =  "\x80"
					. pack('CCN', $packet, $valLength, $dataLen+$valLength)
					. $value
					. $data;

		// Debugging
		if ($this->debug) {
			if ($toChild) {
				$arr = '=>';
				$n   = 'child';
			} else {
				$arr = '<=';
				$n   = 'parent';
			}
			$this->debug(
				self::D_IPC . " {$arr} Sending packet{$postfix} to {$n} [{$packet}]; "
				. strlen($_packet) ."b length with {$dataLen}b"
				." in data and {$valLength}b in value"
			);
		}

		return $_packet;
	}

	#endregion



	#region Signals handling

	/**
	 * Sends signal to parent
	 *
	 * @param int $signo Signal's number
	 */
	private function sendSignalToParent($signo = SIGUSR1)
	{
		$this->_sendSignal($signo, $this->parent_pid);
	}

	/**
	 * Sends signal to child
	 *
	 * @param int $signo Signal's number
	 */
	private function sendSignalToChild($signo = SIGUSR1)
	{
		$this->_sendSignal($signo, $this->child_pid);
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
	private function _sendSignal($signo, $pid)
	{
		if ($this->debug) {
			$name = Base::signalName($signo);
			if ($pid === $this->child_pid) {
				$arrow = '=>';
				$n     = 'child';
			} else {
				$arrow = '<=';
				$n     = 'parent';
			}
			$this->debug(
				self::D_IPC . " $arrow Sending signal to the $n - $name ($signo) ($this->pid => $pid)");
		}
		posix_kill($pid, $signo);
	}


	/**
	 * Register signals.
	 */
	private function registerEventSignals()
	{
		if (self::$eventsSignals) {
			throw new Exception('Signal events are already registered');
		}
		$base = self::$base;
		$i = 0;
		$cb = $this->isChild
				? array($this, '_evCbSignal')
				: array(get_called_class(), '_mEvCbSignal');
		foreach (Base::$signals as $signo => $name) {
			if ($signo === SIGKILL || $signo === SIGSTOP) {
				continue;
			}
			$ev = new Event();
			$ev->setSignal($signo, $cb)
				->setBase($base)
				->add();
			self::$eventsSignals[$signo] = $ev;
			$i++;
		}
		$this->debug(self::D_INIT . "Signals event handlers registered ($i)");
	}


	/**
	 * Called when a signal caught through libevent.
	 *
	 * @access private
	 *
	 * @param null  $fd
	 * @param int   $events
	 * @param array $arg
	 */
	public function _evCbSignal($fd, $events, $arg)
	{
		$this->signalHandler($arg[2]);
	}

	/**
	 * Called when the signal is caught
	 *
	 * @param int $signo Signal's number
	 */
	private function signalHandler($signo)
	{
		// Settings
		$name = Base::signalName($signo, $found);
		if ($debug = $this->debug) {
			$prefix = $this->isChild ? '=>' : '<=';
			$this->debug(self::D_IPC . " {$prefix} Caught $name ($signo) signal");
		}

		// Handler
		if (method_exists($this, $name)) {
			$this->$name($signo);
		}
		// Skipped signals:
		//  SIGCHLD  - Child processes terminates or stops
		//  SIGWINCH - Window size change
		//  SIGINFO  - Information request
		else if (SIGCHLD === $signo || SIGWINCH === $signo || 28 === $signo) {
			return;
		}
		// Default action - shutdown
		else {
			$debug && $this->debug(self::D_INFO . 'Unhandled signal, exiting');
			self::$base->loopBreak();
			$this->shutdown();
		}
	}


	/**
	 * Called when a signal caught in master through libevent.
	 *
	 * @access private
	 *
	 * @param null  $fd
	 * @param int   $events
	 * @param array $arg
	 */
	public static function _mEvCbSignal($fd, $events, $arg)
	{
		self::mSignalHandler($arg[2]);
	}

	/**
	 * Called when the signal is caught in master
	 *
	 * @param int $signo Signal's number
	 */
	private static function mSignalHandler($signo)
	{
		// Settings
		$name = Base::signalName($signo);
		if ($debug = self::stGetDebug($thread)) {
			$prefix = $thread->isChild ? '=>' : '<=';
			self::stDebug(self::D_IPC . " {$prefix} Caught $name ($signo) signal");
		}
		$name  = "m{$name}";
		$class = get_called_class();

		// Handler
		if ($exists = method_exists($class, $name)) {
			$class::$name($signo);
		}
		// Skipped signals:
		//  SIGWINCH - Window size change
		//  SIGINFO  - Information request
		else if (SIGWINCH === $signo || 28 === $signo) {
			return;
		}
		// Default action - shutdown
		else {
			$debug && self::stDebug(self::D_INFO . 'Unhandled signal, exiting');
			self::$base->loopBreak();
			exit;
		}
	}


	/**
	 * Master SIGCHLD handler - Child processes terminates or stops.
	 */
	protected static function mSigChld()
	{
		$debug = self::stGetDebug($thread);
		while (0 < $pid = pcntl_waitpid(-1, $status, WNOHANG|WUNTRACED)) {
			$debug && self::stDebug(self::D_INFO . "SIGCHLD is for pid #{$pid}");
			if ($pid > 0 && isset(self::$threadsByPids[$pid])) {
				if (isset(self::$threads[$threadId = self::$threadsByPids[$pid]])) {
					$thread = self::$threads[$threadId];
					$debug && self::stDebug(self::D_INFO . "SIGCHLD is for thread #{$threadId}");
					if (!$thread->cleaning) {
						$thread->stopWorker();
					}
				}
			}
		}
	}

	#endregion



	#region Shutdown


	/**
	 * Attempts to stop the thread worker process
	 *
	 * @param bool $wait
	 * @param int  $signo - SIGINT|SIGTSTP|SIGTERM|SIGSTOP|SIGKILL
	 *
	 * @return bool TRUE on success and FALSE otherwise
	 */
	protected function stopWorker($wait = false, $signo = SIGTERM)
	{
		$res = false;
		if ($this->isForked) {
			if ($this->isAlive()) {
				if ($debug = $this->debug) {
					$do = ($signo == SIGSTOP || $signo == SIGKILL) ? 'Kill' : 'Stop';
					$this->debug(self::D_INFO . "$do worker");
				}
				$this->sendSignalToChild($signo);
				if ($wait) {
					$debug && $this->debug(self::D_INFO . 'Waiting for the child');
					$pid = $this->child_pid;
					if ($signo == SIGSTOP) {
						$i = 15;
						usleep(1000);
						do {
							$st = pcntl_waitpid($pid, $status, WNOHANG|WUNTRACED);
							if ($st) {
								break;
							}
							usleep(100000);
						} while (--$i > 0);
						if (!$st) {
							return $this->stopWorker(true, SIGKILL);
						}
					} else {
						pcntl_waitpid($pid, $status, WUNTRACED);
					}
				}
				$res = true;
			}
			$this->isForked = false;
		}
		if (!$this->cleaning) {
			$this->setState(self::STATE_WAIT);
		}
		return $res;
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
		return $this->stopWorker($wait, SIGKILL);
	}


	/**
	 * Shutdowns the child process properly
	 */
	protected function shutdown()
	{
		if ($this->isChild) {
			$this->debug(self::D_INFO . 'Child exit');
			$this->cleanup();
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
	 * @param string $message
	 * @param self   $thread
	 */
	protected static function stDebug($message, $thread = null)
	{
		if ($thread) {
			$id = $thread->id;
		} elseif (self::stGetDebug($thread)) {
			$id = '-';
		} else {
			return;
		}

		$time = Base::getTime();
		$role = $thread->isChild ? 'W' : '-'; // Master|Worker
		$message = "{$time} [debug] [T{$id}.{$role}] #{$thread->pid}: {$message}";

		echo $message;
		@ob_flush(); @flush();
	}

	/**
	 * Returns instance debug status for static calls
	 *
	 * @throws Exception
	 *
	 * @param Thread $thread
	 *
	 * @return bool
	 */
	private static function stGetDebug(&$thread = null)
	{
		if (__CLASS__ === $class = get_called_class()) {
			$class = key(self::$threadsByClasses);
		}
		if (empty(self::$threadsByClasses[$class])) {
			throw new Exception("Couldn't find threads of type $class");
		}
		$thread = reset(self::$threadsByClasses[$class]);
		return $thread->debug;
	}

	#endregion
}


// IPC data transfer mode
function_exists('igbinary_serialize')
	&& Thread::$ipcDataMode = Thread::IPC_IGBINARY;

// Forks
Thread::$useForks = Base::$hasForkSupport && Base::$hasLibevent;
