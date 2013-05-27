<?php

namespace Aza\Components\Thread;
use Closure;

/**
 * Simple API for closure thread
 *
 * @project Anizoptera CMF
 * @package system.thread
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */
class SimpleThread extends Thread
{
	/**
	 * Whether the thread will wait for next tasks.
	 * Preforked threads are always multitask.
	 *
	 * @see prefork
	 */
	protected $multitask = false;

	/**
	 * Perform pre-fork, to avoid wasting resources later.
	 * Preforked threads are always multitask.
	 *
	 * @see multitask
	 */
	protected $prefork = false;

	/**
	 * Maximum timeout for master to wait for the job results
	 * (in seconds, can be fractional).
	 * Set it to less than one, to disable.
	 */
	protected $timeoutMasterResultWait = 10;


	/**
	 * Callable
	 *
	 * @var callable
	 */
	protected $callable;



	/**
	 * Creates new thread with closure
	 *
	 * @param callable|Closure $callable <p>
	 * Thread closure (callable)
	 * </p>
	 * @param array $options [optional] <p>
	 * Thread options (array [property => value])
	 * </p>
	 * @param bool $debug [optional] <p>
	 * Whether to output debugging information
	 * </p>
	 *
	 * @return static
	 */
	public static function create($callable, array $options = null, $debug = false)
	{

		$options || $options = array();
		$options['callable'] = $callable;
		return new static(null, null, $debug, $options);
	}


	/**
	 * Prepares closure (only in PHP >= 5.4.0)
	 *
	 * @codeCoverageIgnore
	 */
	protected function onLoad()
	{
		// Prepare closure
		$callable = $this->callable;
		if ($callable instanceof Closure && method_exists($callable, 'bindTo')) {
			/** @noinspection PhpUndefinedMethodInspection */
			$callable->bindTo($this, $this);
		}
	}

	/**
	 * Callable cleanup
	 */
	protected function onCleanup()
	{
		$this->callable = null;
	}


	/**
	 * Main processing. You need to override this method.
	 * Use {@link getParam} method to get processing parameters.
	 * Returned result will be available via {@link getResult}
	 * in the master process.
	 *
	 * @internal
	 */
	function process()
	{
		return call_user_func_array(
			$this->callable, $this->getParams()
		);
	}
}
