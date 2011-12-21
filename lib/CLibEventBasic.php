<?php

/**
 * LibEvent "basic" event functionality
 *
 * @link http://www.wangafu.net/~nickm/libevent-book/
 *
 * @uses libevent
 *
 * @project Anizoptera CMF
 * @package system.libevent
 */
abstract class CLibEventBasic
{
	/**
	 * Unique event IDs counter
	 *
	 * @var int
	 */
	private static $counter = 0;

	/**
	 * Unique event ID
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
	 * @var CLibEventBase
	 */
	public $base;


	/**
	 * Creates a new event resource.
	 *
	 * @throws AzaException if Libevent isn't available
	 */
	public function __construct()
	{
//		if (!function_exists('event_base_new')) {
		if (!CShell::$hasLibevent) {
			throw new AzaException('You need to install PECL extension "Libevent" to use this class', 2);
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
	 * Associate buffered event with an event base
	 *
	 * @param CLibEventBase $event_base
	 *
	 * @return CLibEventBasic
	 */
	public function setBase($event_base)
	{
		$this->base = $event_base;
		$event_base->events[$this->id] = $this;
		return $this;
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
	 * Checks event resource.
	 *
	 * @throws AzaException if resource is already freed
	 */
	protected function checkResourse()
	{
		if (!$this->resource) {
			throw new AzaException('Can\'t use event resource. It\'s already freed.', 2);
		}
	}
}
