<?php

/**
 * LibEvent resourse wrapper
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.libevent
 */
class CLibEvent extends CLibEventBasic
{
	/**
	 * Event resource
	 *
	 * @var resource
	 */
	public $resource;

	/**
	 * @var CLibEventBase
	 */
	public $base;


	/**
	 * Creates a new event resource.
	 *
	 * @see event_new
	 *
	 * @throws AzaException
	 */
	public function __construct()
	{
		parent::__construct();
		if (!$this->resource = event_new()) {
			throw new AzaException('Can\'t create new event resourse (event_new)', 1);
		}
	}


	/**
	 * Adds an event to the set of monitored events.
	 *
	 * @see event_add
	 *
	 * @throws AzaException if can't add event
	 *
	 * @param int $timeout Optional timeout (in microseconds).
	 *
	 * @return CLibEvent
	 */
	public function add($timeout = -1)
	{
		$this->checkResourse();
		if (!event_add($this->resource, $timeout)) {
			throw new AzaException("Can't add event (event_add)", 1);
		}
		return $this;
	}

	/**
	 * Remove an event from the set of monitored events.
	 *
	 * @see event_del
	 *
	 * @throws AzaException if can't delete event
	 *
	 * @return CLibEvent
	 */
	public function del()
	{
		$this->checkResourse();
		if (!event_del($this->resource)) {
			throw new AzaException("Can't delete event (event_del)", 1);
		}
		return $this;
	}


	/**
	 * Associate event with an event base.
	 *
	 * @see event_base_set
	 *
	 * @throws AzaException
	 *
	 * @param CLibEventBase $event_base
	 *
	 * @return CLibEvent
	 */
	public function setBase($event_base)
	{
		$this->checkResourse();
		$event_base->checkResourse();
		if (!event_base_set($this->resource, $event_base->resource)) {
			throw new AzaException('Can\'t set event base (event_base_set)', 1);
		}
		return parent::setBase($event_base);
	}

	/**
	 * Destroys the event and frees all the resources associated.
	 *
	 * @see event_free
	 *
	 * @return CLibEvent
	 */
	public function free()
	{
		if ($this->resource) {
			event_free($this->resource);
			$this->resource = null;
			parent::free();
		}
		return $this;
	}


	/**
	 * Prepares the event to be used in add().
	 *
	 * @see add
	 * @see event_add
	 * @see event_set
	 *
	 * @throws AzaException if can't prepare event
	 *
	 * @param resource|mixed $fd <p>
	 * Valid PHP stream resource. The stream must be castable to file descriptor,
	 * so you most likely won't be able to use any of filtered streams.
	 * </p>
	 * @param int $events <p>
	 * A set of flags indicating the desired event, can be EV_TIMEOUT, EV_READ, EV_WRITE and EV_SIGNAL.
	 * The additional flag EV_PERSIST makes the event to persist until {@link event_del}() is
	 * called, otherwise the callback is invoked only once.
	 * </p>
	 * @param callback $callback <p>
	 * Callback function to be called when the matching event occurs.
	 * <br><tt>function(resource|null $fd, int $events, array $arg(CLibEvent $event, mixed $arg)){}</tt>
	 * </p>
	 * @param mixed $arg
	 *
	 * @return CLibEvent
	 */
	public function set($fd, $events, $callback, $arg = null)
	{
		$this->checkResourse();
		if (!event_set($this->resource, $fd, $events, $callback, array($this, $arg))) {
			throw new AzaException("Can't prepare event (event_set)", 1);
		}
		return $this;
	}

	/**
	 * Prepares the event to be used in add() as signal handler.
	 *
	 * @see set
	 * @see add
	 * @see event_add
	 * @see event_set
	 *
	 * @throws AzaException if can't prepare event
	 *
	 * @param int $signo <p>
	 * Signal number
	 * </p>
	 * @param callback $callback <p>
	 * Callback function to be called when the matching event occurs.
	 * <br><tt>function(null $fd, int $events(8:EV_SIGNAL), array $arg(CLibEvent $event, mixed $arg, int $signo)){}</tt>
	 * </p>
	 * @param bool $persist <p>
	 * Whether the event will persist until {@link event_del}() is
	 * called, otherwise the callback is invoked only once.
	 * </p>
	 * @param mixed $arg
	 *
	 * @return CLibEvent
	 */
	public function setSignal($signo, $callback, $persist = true, $arg = null)
	{
		$this->checkResourse();
		$events = EV_SIGNAL;
		if ($persist) {
			$events |= EV_PERSIST;
		}
		if (!event_set($this->resource, $signo, $events, $callback, array($this, $arg, $signo))) {
			$name = CShell::signalName($signo);
			throw new AzaException("Can't prepare event (event_set) for $name ($signo) signal", 1);
		}
		return $this;
	}

	/**
	 * Prepares the timer event.
	 * Use {@link add}() in callback again with interval to repeat timer.
	 *
	 * @see event_timer_set
	 *
	 * @throws AzaException if can't prepare event
	 *
	 * @param callback $callback <p>
	 * Callback function to be called when the interval expires.
	 * <br><tt>function(null $fd, int $events(1:EV_TIMEOUT), array $arg(CLibEvent $event, mixed $arg)){}</tt>
	 * </p>
	 * @param mixed $arg
	 *
	 * @return CLibEvent
	 */
	public function setTimer($callback, $arg = null)
	{
		$this->checkResourse();
		if (!event_timer_set($this->resource, $callback, array($this, $arg))) {
			throw new AzaException("Can't prepare event (event_timer_set) for timer", 1);
		}
		return $this;
	}
}
