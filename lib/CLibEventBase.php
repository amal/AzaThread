<?php

/**
 * LibEventBase resourse wrapper
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.libevent
 */
class CLibEventBase
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
	 * @var CLibEventBasic[]
	 */
	public $events = array();

	/**
	 * Timers
	 *
	 * @var array[]
	 */
	public $timers = array();



	/**
	 * Create and initialize new event base
	 *
	 * @see event_base_new
	 *
	 * @param bool $init_priority Whether to init priority with default value
	 *
	 * @throws AzaException
	 */
	public function __construct($init_priority = true)
	{
//		if (!function_exists('event_base_new')) {
		if (!CShell::$hasLibevent) {
			throw new AzaException('You need to install PECL extension "Libevent" to use this class', 1);
		}
		if (!$this->resource = event_base_new()) {
			throw new AzaException('Can\'t create event base resourse (event_base_new)', 1);
		}
		$this->id = ++self::$counter;
		if ($init_priority) {
			$this->priorityInit();
		}
	}

	/**
	 * Desctructor
	 */
	public function __destruct()
	{
		if ($this->resource) {
			$this->free();
		}
	}


	/**
	 * Associate event base with an event (or buffered event).
	 *
	 * @see CLibEvent::setBase
	 * @see CLibEventBuffer::setBase
	 *
	 * @throws AzaException
	 *
	 * @param CLibEventBasic $event
	 *
	 * @return CLibEventBase
	 */
	public function setEvent($event)
	{
		$event->setBase($this);
		return $this;
	}


	/**
	 * Destroys the specified event_base and frees all the resources associated.
	 * Note that it's not possible to destroy an event base with events attached to it.
	 *
	 * @see event_base_free
	 *
	 * @return CLibEventBase
	 */
	public function free()
	{
		if ($this->resource) {
			foreach ($this->events as $e) {
				$e->free();
			}
			@event_base_free($this->resource);
			$this->resource = null;
		}
		return $this;
	}


	/**
	 * Starts event loop for the specified event base.
	 *
	 * @see event_base_loop
	 *
	 * @throws AzaException if error
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
			throw new AzaException('Can\'t start base loop (event_base_loop)', 1);
		}
		return $res;
	}

	/**
	 * Abort the active event loop immediately. The behaviour is similar to break statement.
	 *
	 * @see event_base_loopbreak
	 *
	 * @throws AzaException
	 *
	 * @return CLibEventBase
	 */
	public function loopBreak()
	{
		$this->checkResourse();
		if (!event_base_loopbreak($this->resource)) {
			throw new AzaException('Can\'t break loop (event_base_loopbreak)', 1);
		}
		return $this;
	}

	/**
	 * Exit loop after a time.
	 *
	 * @see event_base_loopexit
	 *
	 * @throws AzaException
	 *
	 * @param int $timeout Optional timeout parameter (in microseconds).
	 *
	 * @return CLibEventBase
	 */
	public function loopExit($timeout = -1)
	{
		$this->checkResourse();
		if (!event_base_loopexit($this->resource, $timeout)) {
			throw new AzaException('Can\'t set loop exit timeout (event_base_loopexit)', 1);
		}
		return $this;
	}


	/**
	 * Sets the maximum priority level of the event base.
	 *
	 * @see event_base_priority_init
	 *
	 * @throws AzaException
	 *
	 * @param int $value
	 *
	 * @return CLibEventBase
	 */
	public function priorityInit($value = self::MAX_PRIORITY)
	{
		$this->checkResourse();
		if (!event_base_priority_init($this->resource, ++$value)) {
			$msg = "Can't set the maximum priority level of the event base to $value (event_base_priority_init)";
			throw new AzaException($msg, 1);
		}
		return $this;
	}


	/**
	 * Checks event base resource.
	 *
	 * @throws AzaException if resource is already freed
	 */
	public function checkResourse()
	{
		if (!$this->resource) {
			throw new AzaException('Can\'t use event base resource. It\'s already freed.', 2);
		}
	}


	/**
	 * Adds a new named timer to the base or customize existing.
	 *
	 * @throws AzaException
	 *
	 * @param string $name Timer name
	 * @param int  $interval Interval
	 * @param callback $callback <p>
	 * Callback function to be called when the interval expires.<br/>
	 * <tt>function(CLibEventBase $event_base, string $timer_name, int $iteration, mixed $arg){}</tt><br/>
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
			throw new AzaException("Incorrect callback [$callableName] for timer ($name).", 1);
		}

		if ($notExists) {
			$event = new CLibEvent();
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
			throw new AzaException("Unknown timer \"$name\". Add timer before using.", 1);
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
		/** @var $event CLibEvent */
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
		/** @var $event CLibEvent */
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
		/** @var $event CLibEvent */
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
	 * @see CLibEvent::setTimer
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
		$res = call_user_func($timer['callback'], $this, $name, ++$timer['i'], $timer['arg']);
		if ($res) {
			$this->timerStart($name, null, null, false);
		} else {
			$timer['i'] = 0;
		}
	}
}
