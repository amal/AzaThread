<?php

namespace Aza\Components\LibEvent;
use Aza\Components\LibEvent\Exceptions\Exception;
use Aza\Components\Cli\Base;

/**
 * LibEvent "basic" event functionality
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.AzaLibEvent
 * @version $Id: EventBasic.php 3259 2012-04-10 13:00:16Z samally $
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
abstract class EventBasic
{
	/**
	 * Unique event IDs counter
	 *
	 * @var int
	 */
	private static $counter = 0;

	/**
	 * Unique (for current process) event ID
	 *
	 * @var int
	 */
	public $id;

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
	 * @throws Exception if Libevent isn't available
	 */
	public function __construct()
	{
		if (!Base::$hasLibevent) {
			throw new Exception(
				'You need to install PECL extension "Libevent" to use this class'
			);
		}
		$this->id = ++self::$counter;
	}


	/**
	 * Desctructor
	 */
	public function __destruct()
	{
		$this->resource && $this->free();
	}

	/**
	 * Destroys the event and frees all the resources associated.
	 */
	public function free()
	{
		if ($this->base) {
			unset($this->base->events[$this->id]);
			$this->base = null;
		}
	}


	/**
	 * Associate buffered event with an event base
	 *
	 * @param EventBase $event_base
	 *
	 * @return static
	 */
	public function setBase($event_base)
	{
		$this->base = $event_base;
		$event_base->events[$this->id] = $this;
		return $this;
	}


	/**
	 * Checks event resource.
	 *
	 * @throws Exception if resource is already freed
	 */
	protected function checkResourse()
	{
		if (!$this->resource) {
			throw new Exception("Can't use event resource. It's already freed.");
		}
	}
}
