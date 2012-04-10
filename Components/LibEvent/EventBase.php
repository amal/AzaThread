<?php

namespace Aza\Components\LibEvent;
use Aza\Components\LibEvent\Exceptions\Exception;
use Aza\Components\Cli\Base;

/**
 * LibEvent base resourse wrapper
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.AzaLibEvent
 * @version $Id: EventBase.php 3259 2012-04-10 13:00:16Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class EventBase
{
	/**
	 * Default priority
	 *
	 * @see priorityInit
	 */
	const MAX_PRIORITY = 30;


	/**
	 * Unique base IDs counter
	 *
	 * @var int
	 */
	private static $counter = 0;

	/**
	 * Unique base ID
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Event base resource
	 *
	 * @var resource
	 */
	public $resource;

	/**
	 * Events
	 *
	 * @var Event[]|EventBuffer[]
	 */
	public $events = array();

	/**
	 * Array of timers settings
	 *
	 * @var array[]
	 */
	protected $timers = array();



	/**
	 * Initializes instance
	 *
	 * @see event_base_new
	 *
	 * @throws Exception
	 *
	 * @param bool $initPriority Whether to init priority with default value
	 */
	public function __construct($initPriority = true)
	{
		$this->init($initPriority);
		$this->id = ++self::$counter;
	}

	/**
	 * Create and initialize new event base
	 *
	 * @see event_base_new
	 *
	 * @throws Exception
	 *
	 * @param bool $initPriority Whether to init priority with default value
	 */
	protected function init($initPriority = true)
	{
		if (!Base::$hasLibevent) {
			throw new Exception('You need to install PECL extension "Libevent" to use this class');
		} else if (!$this->resource = event_base_new()) {
			throw new Exception("Can't create event base resourse (event_base_new)");
		}
		$initPriority && $this->priorityInit();
	}

	/**
	 * Reinitializes event base and cleans all
	 * attached events and timers without destroying
	 * resources in parent process.
	 *
	 * Use this method after fork in child!
	 *
	 * @param bool $initPriority Whether to init priority with default value
	 *
	 * @return self
	 */
	public function reinitialize($initPriority = true)
	{
		foreach ($this->events as $e) {
			if ($e instanceof Event) {
				$e->free();
			}
			// We do not free the buffered events -
			// it damages the resources in the parent.
			// PHP will free instances and resources on the end of the process
		}
		$this->events = $this->timers = array();
		$this->init($initPriority);
		return $this;
	}


	/**
	 * Desctructor
	 */
	public function __destruct()
	{
		$this->resource && $this->free();
	}

	/**
	 * Destroys the specified event_base and frees all the resources associated.
	 * Note that it's not possible to destroy an event base with events attached to it.
	 *
	 * @see event_base_free
	 *
	 * @return self
	 */
	public function free()
	{
		if ($this->resource) {
			$this->freeAttachedEvents();
			@event_base_free($this->resource);
			$this->resource = null;
		}
		return $this;
	}

	/**
	 * Frees all attached timers and events
	 *
	 * @return self
	 */
	public function freeAttachedEvents()
	{
		foreach ($this->events as $e) {
			$e->free();
		}
		$this->events =
		$this->timers = array();
		return $this;
	}


	/**
	 * Associate event base with an event (or buffered event).
	 *
	 * @see Event::setBase
	 * @see EventBuffer::setBase
	 *
	 * @throws Exception
	 *
	 * @param EventBasic $event
	 *
	 * @return self
	 */
	public function setEvent($event)
	{
		$event->setBase($this);
		return $this;
	}


	/**
	 * Starts event loop for the specified event base.
	 *
	 * @see event_base_loop
	 *
	 * @throws Exception if error
	 *
	 * @param int $flags Optional parameter, which can take any combination of EVLOOP_ONCE and EVLOOP_NONBLOCK.
	 *
	 * @return int Returns 0 on success, 1 if no events were registered.
	 */
	public function loop($flags = 0)
	{
		$this->checkResourse();
		$res = event_base_loop($this->resource, $flags);
		if ($res === -1) {
			throw new Exception("Can't start base loop (event_base_loop)");
		}
		return $res;
	}

	/**
	 * Abort the active event loop immediately. The behaviour is similar to break statement.
	 *
	 * @see event_base_loopbreak
	 *
	 * @throws Exception
	 *
	 * @return self
	 */
	public function loopBreak()
	{
		$this->checkResourse();
		if (!event_base_loopbreak($this->resource)) {
			throw new Exception("Can't break loop (event_base_loopbreak)");
		}
		return $this;
	}

	/**
	 * Exit loop after a time.
	 *
	 * @see event_base_loopexit
	 *
	 * @throws Exception
	 *
	 * @param int $timeout Optional timeout parameter (in microseconds).
	 *
	 * @return self
	 */
	public function loopExit($timeout = -1)
	{
		$this->checkResourse();
		if (!event_base_loopexit($this->resource, $timeout)) {
			throw new Exception("Can't set loop exit timeout (event_base_loopexit)");
		}
		return $this;
	}


	/**
	 * Sets the maximum priority level of the event base.
	 *
	 * @see event_base_priority_init
	 *
	 * @throws Exception
	 *
	 * @param int $value
	 *
	 * @return self
	 */
	public function priorityInit($value = self::MAX_PRIORITY)
	{
		$this->checkResourse();
		if (!event_base_priority_init($this->resource, ++$value)) {
			throw new Exception(
				"Can't set the maximum priority level of the event base"
				." to {$value} (event_base_priority_init)"
			);
		}
		return $this;
	}


	/**
	 * Checks event base resource.
	 *
	 * @throws Exception if resource is already freed
	 */
	public function checkResourse()
	{
		if (!$this->resource) {
			throw new Exception("Can't use event base resource. It's already freed.");
		}
	}


	/**
	 * Adds a new named timer to the base or customize existing.
	 *
	 * @throws Exception
	 *
	 * @param string $name Timer name
	 * @param int  $interval Interval
	 * @param callback $callback <p>
	 * Callback function to be called when the interval expires.<br/>
	 * <tt>function(string $timer_name, mixed $arg, int $iteration, EventBase $event_base){}</tt><br/>
	 * If callback will return FALSE timer will not be added again for next iteration.
	 * </p>
	 * @param mixed $arg   Additional timer argument
	 * @param bool  $start Whether to start timer
	 * @param int   $q     Interval multiply factor
	 */
	public function timerAdd($name, $interval = null, $callback = null, $arg = null, $start = true, $q = 1000000)
	{
		$notExists = !isset($this->timers[$name]);

		if (($notExists || $callback) && !is_callable($callback, false, $callableName)) {
			throw new Exception("Incorrect callback '{$callableName}' for timer ({$name}).");
		}

		if ($notExists) {
			$event = new Event();
			$event->setTimer(array($this, '_onTimer'), $name)
					->setBase($this);
			$this->timers[$name] = array(
				'name'     => $name,
				'callback' => $callback,
				'event'    => $event,
				'interval' => $interval,
				'arg'      => $arg,
				'q'        => $q,
				'i'        => 0,
			);
		} else {
			$timer = &$this->timers[$name];
			$event = $timer['event'];
			$event->del();
			if ($callback) {
				$timer['callback'] = $callback;
			}
			if ($interval > 1) {
				$timer['interval'] = $interval;
			}
			if ($arg !== null) {
				$timer['arg'] = $arg;
			}
			$timer['i'] = 0;
		}

		if ($start) {
			$this->timerStart($name);
		}
	}

	/**
	 * Starts timer
	 *
	 * @param string $name           Timer name
	 * @param int    $interval       Interval
	 * @param mixed  $arg            Additional timer argument
	 * @param bool   $resetIteration Whether to reset iteration counter
	 */
	public function timerStart($name, $interval = null, $arg = null, $resetIteration = true)
	{
		if (!isset($this->timers[$name])) {
			throw new Exception("Unknown timer '{$name}'. Add timer before using.");
		}
		$timer = &$this->timers[$name];
		if ($resetIteration) {
			$timer['i'] = 0;
		}
		if ($arg !== null) {
			$timer['arg'] = $arg;
		}
		if ($interval > 1) {
			$timer['interval'] = $interval;
		}
		/** @var $event Event */
		$event = $timer['event'];
		$event->add($timer['interval'] * $timer['q']);
	}

	/**
	 * <p>Stops timer when it's started and waiting.</p>
	 *
	 * <p>Don't call from timer callback.
	 * Return FALSE instead - see {@link timerAdd}().</p>
	 *
	 * @param string $name Timer name
	 */
	public function timerStop($name)
	{
		if (!isset($this->timers[$name])) {
			return;
		}
		$timer = &$this->timers[$name];
		/** @var $event Event */
		$event = $timer['event'];
		$event->del();
		$timer['i'] = 0;
	}

	/**
	 * Completely destroys timer
	 *
	 * @param string $name Timer name
	 */
	public function timerDelete($name)
	{
		if (!isset($this->timers[$name])) {
			return;
		}
		$timer = &$this->timers[$name];
		/** @var $event Event */
		$event = $timer['event'];
		$event->free();
		unset($this->timers[$name]);
	}

	/**
	 * Return whther timer with such name exists in the base
	 *
	 * @param string $name Timer name
	 *
	 * @return bool
	 */
	public function timerExists($name)
	{
		return isset($this->timers[$name]);
	}

	/**
	 * Timer callback
	 *
	 * @see Event::setTimer
	 *
	 * @param null  $fd
	 * @param int   $event EV_TIMEOUT
	 * @param array $args
	 */
	public function _onTimer($fd, $event, $args)
	{
		$name = $args[1];

		// Skip deleted timers
		if (!isset($this->timers[$name])) {
			return;
		}

		// Invoke callback
		$timer = &$this->timers[$name];
		$res = call_user_func($timer['callback'], $name, $timer['arg'], ++$timer['i'], $this);
		if ($res) {
			$this->timerStart($name, null, null, false);
		} else {
			$timer['i'] = 0;
		}
	}
}
