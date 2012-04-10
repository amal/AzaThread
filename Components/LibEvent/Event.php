<?php

namespace Aza\Components\LibEvent;
use Aza\Components\LibEvent\Exceptions\Exception;
use Aza\Components\Cli\Base;

/**
 * LibEvent event resourse wrapper
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.AzaLibEvent
 * @version $Id: Event.php 3259 2012-04-10 13:00:16Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class Event extends EventBasic
{
	/**
	 * Event resource
	 *
	 * @var resource
	 */
	public $resource;

	/**
	 * @var EventBase
	 */
	public $base;


	/**
	 * Creates a new event resource.
	 *
	 * @see event_new
	 *
	 * @throws Exception
	 */
	public function __construct()
	{
		parent::__construct();
		if (!$this->resource = event_new()) {
			throw new Exception("Can't create new event resourse (event_new)");
		}
	}


	/**
	 * Adds an event to the set of monitored events.
	 *
	 * @see event_add
	 *
	 * @throws Exception if can't add event
	 *
	 * @param int $timeout Optional timeout (in microseconds).
	 *
	 * @return self
	 */
	public function add($timeout = -1)
	{
		$this->checkResourse();
		if (!event_add($this->resource, $timeout)) {
			throw new Exception("Can't add event (event_add)");
		}
		return $this;
	}

	/**
	 * Remove an event from the set of monitored events.
	 *
	 * @see event_del
	 *
	 * @throws Exception if can't delete event
	 *
	 * @return self
	 */
	public function del()
	{
		$this->checkResourse();
		if (!event_del($this->resource)) {
			throw new Exception("Can't delete event (event_del)");
		}
		return $this;
	}


	/**
	 * Associate event with an event base.
	 *
	 * @see event_base_set
	 *
	 * @throws Exception
	 *
	 * @param EventBase $event_base
	 *
	 * @return self
	 */
	public function setBase($event_base)
	{
		$this->checkResourse();
		$event_base->checkResourse();
		if (!event_base_set($this->resource, $event_base->resource)) {
			throw new Exception("Can't set event base (event_base_set)");
		}
		return parent::setBase($event_base);
	}

	/**
	 * Destroys the event and frees all the resources associated.
	 *
	 * @see event_free
	 *
	 * @return self
	 */
	public function free()
	{
		parent::free();
		if ($res = $this->resource) {
			event_del($res);
			event_free($res);
			$this->resource = null;
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
	 * @throws Exception if can't prepare event
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
	 * <br><tt>function(resource|null $fd, int $events, array $arg(Event $event, mixed $arg)){}</tt>
	 * </p>
	 * @param mixed $arg
	 *
	 * @return self
	 */
	public function set($fd, $events, $callback, $arg = null)
	{
		$this->checkResourse();
		if (!event_set($this->resource, $fd, $events, $callback, array($this, $arg))) {
			throw new Exception("Can't prepare event (event_set)");
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
	 * @throws Exception if can't prepare event
	 *
	 * @param int $signo <p>
	 * Signal number
	 * </p>
	 * @param callback $callback <p>
	 * Callback function to be called when the matching event occurs.
	 * <br><tt>function(null $fd, int $events(8:EV_SIGNAL), array $arg(Event $event, mixed $arg, int $signo)){}</tt>
	 * </p>
	 * @param bool $persist <p>
	 * Whether the event will persist until {@link event_del}() is
	 * called, otherwise the callback is invoked only once.
	 * </p>
	 * @param mixed $arg
	 *
	 * @return self
	 */
	public function setSignal($signo, $callback, $persist = true, $arg = null)
	{
		$this->checkResourse();
		$events = EV_SIGNAL;
		if ($persist) {
			$events |= EV_PERSIST;
		}
		if (!event_set($this->resource, $signo, $events, $callback, array($this, $arg, $signo))) {
			$name = Base::signalName($signo);
			throw new Exception("Can't prepare event (event_set) for $name ($signo) signal");
		}
		return $this;
	}

	/**
	 * Prepares the timer event.
	 * Use {@link add}() in callback again with interval to repeat timer.
	 *
	 * @see event_timer_set
	 *
	 * @throws Exception if can't prepare event
	 *
	 * @param callback $callback <p>
	 * Callback function to be called when the interval expires.
	 * <br><tt>function(null $fd, int $events(1:EV_TIMEOUT), array $arg(Event $event, mixed $arg)){}</tt>
	 * </p>
	 * @param mixed $arg
	 *
	 * @return self
	 */
	public function setTimer($callback, $arg = null)
	{
		$this->checkResourse();
		if (!event_timer_set($this->resource, $callback, array($this, $arg))) {
			throw new Exception("Can't prepare event (event_timer_set) for timer");
		}
		return $this;
	}
}
