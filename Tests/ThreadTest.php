<?php

namespace Aza\Components\Thread\Tests;
use Aza\Components\Log\Logger;
use Aza\Components\Socket\Socket;
use Aza\Components\Thread\Thread;
use Aza\Components\Thread\ThreadPool;
use Exception;
use PHPUnit_Framework_TestCase as TestCase;

/**
 * Testing thread system
 *
 * @project Anizoptera CMF
 * @package system.thread
 */
class ThreadTest extends TestCase
{
	/**
	 * @var bool
	 */
	protected static $defForks;


	/**
	 * {@inheritdoc}
	 */
	public static function setUpBeforeClass()
	{
		self::$defForks = Thread::$useForks;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function tearDownAfterClass()
	{
		Thread::$useForks = self::$defForks;
		gc_collect_cycles();
	}


	/**
	 * Tests threads in synchronous mode
	 */
	public function testSync()
	{
		$debug = false;
		Thread::$useForks = false;


		// Sync thread
		$this->processThread($debug);

		// Sync thread with big data
		$this->processThread($debug, true);

		// Sync thread with childs
		$this->processThread($debug, false, true);

		// Sync thread with childs and big data
		$this->processThread($debug, true, true);

		// Sync events
		$this->processThreadEvent($debug);

		// Sync pool
		$this->processPool($debug);

		// Sync pool with big data
		$this->processPool($debug, true);

		// Sync pool with childs
		$this->processPool($debug, false, true);

		// Sync pool with childs and big data
		$this->processPool($debug, true, true);

		// Sync pool events
		$this->processPoolEvent($debug);


		Thread::$useForks = self::$defForks;
	}

	/**
	 * Tests threads in asynchronous mode
	 */
	public function testAsync()
	{
		if (!Thread::$useForks) {
			$this->markTestSkipped(
				'You need LibEvent, PCNTL and POSIX support'
				.' with CLI sapi to fully test Threads'
			);
			return;
		}

		$ipcModes = array(
			Thread::IPC_IGBINARY  => 'igbinary_serialize',
			Thread::IPC_SERIALIZE => false,
		);
		$sockModes     = array(true, false);
		$defDataMode   = Thread::$ipcDataMode;
		$defSocketMode = Socket::$useSockets;


		$debug = false;
		Thread::$useForks = true;


		foreach ($sockModes as $sockMode) {
			Socket::$useSockets = $sockMode;

			foreach ($ipcModes as $mode => $check) {
				if ($check && !function_exists($check)) {
					continue;
				}

				Thread::$ipcDataMode = $mode;

				// Async thread
				$this->processThread($debug);

				// Async thread with big data
				$this->processThread($debug, true);

				// Async thread with childs
				$this->processThread($debug, false, true);

				// Async thread with childs and big data
				$this->processThread($debug, true, true);

				// Async events
				$this->processThreadEvent($debug);

				// Async errorable thread
				$this->processThreadErrorable($debug);

				// Async pool
				$this->processPool($debug);

				// Async pool with big data
				$this->processPool($debug, true);

				// Async pool with childs
				$this->processPool($debug, false, true);

				// Async pool with childs and big data
				$this->processPool($debug, true, true);

				// Async pool events
				$this->processPoolEvent($debug, true);

				// Async errorable pool
				$this->processPoolErrorable($debug);
			}
		}


		Thread::$useForks    = self::$defForks;
		Thread::$ipcDataMode = $defDataMode;
		Socket::$useSockets  = $defSocketMode;
	}


	/**
	 * Thread
	 *
	 * @param bool $debug
	 * @param bool $bigResult
	 * @param bool $withChild
	 */
	function processThread($debug, $bigResult = false, $withChild = false)
	{
		$num = 10;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			     "Thread test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			     '-----------------------', PHP_EOL;
		}

		/** @var $thread Thread */
		$thread = $withChild
				? new TestThreadWithChilds($debug)
				: new TestThreadReturnFirstArgument($debug);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		for ($i = 0; $i < $num; $i++) {
			$value = $bigResult ? str_repeat($i, 100000) : $i;
			$thread->run($value)->wait();
			$state = $thread->getState();
			$this->assertEquals(Thread::STATE_WAIT, $state);
			$sucess = $thread->getSuccess();
			$this->assertTrue($sucess);
			$result = $thread->getResult();
			$this->assertEquals($value, $result);
		}

		$thread->cleanup();
	}

	/**
	 * Thread, random errors
	 *
	 * @param bool $debug
	 */
	function processThreadErrorable($debug)
	{
		$num = 10;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			     "Thread errorable test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			     '-----------------------', PHP_EOL;
		}

		$thread = new TestThreadReturnArgErrors($debug);

		$i = $j = 0;
		$value = $i;

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		while ($num > $i) {
			$j++;
			$thread->run($value, $j)->wait();
			$state = $thread->getState();
			$this->assertEquals(Thread::STATE_WAIT, $state);
			if ($thread->getSuccess()) {
				$result = $thread->getResult();
				$this->assertEquals($value, $result);
				$value = ++$i;
			}
		}

		$this->assertEquals($num*2, $j);

		$thread->cleanup();
	}

	/**
	 * Thread, events
	 *
	 * @param bool $debug
	 */
	function processThreadEvent($debug)
	{
		$events = 15;
		$num = 3;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			     "Thread events test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			     '-----------------------', PHP_EOL;
		}

		$thread = new TestThreadEvents($debug);

		$test = $this;
		$arg = mt_rand(12, 987);
		$last = 0;
		$cb = function($event, $e_data, $e_arg) use ($arg, $test, &$last) {
			/** @var $test TestCase */
			$test->assertEquals($arg, $e_arg);
			$test->assertEquals(TestThreadEvents::EV_PROCESS, $event);
			$test->assertEquals($last++, $e_data);
		};
		$thread->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		for ($i = 0; $i < $num; $i++) {
			$last = 0;
			$thread->run($events)->wait();
			$state = $thread->getState();
			$this->assertEquals(Thread::STATE_WAIT, $state);
			$sucess = $thread->getSuccess();
			$this->assertTrue($sucess);
		}

		$this->assertEquals($events, $last);

		$thread->cleanup();
	}


	/**
	 * Pool
	 *
	 * @param bool $debug
	 * @param bool $bigResult
	 * @param bool $withChild
	 *
	 * @throws Exception
	 */
	function processPool($debug, $bigResult = false, $withChild = false)
	{
		$num     = 100;
		$threads = 4;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			     "Thread pool test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			     '-----------------------', PHP_EOL;
		}

		$thread = $withChild
				? 'TestThreadWithChilds'
				: 'TestThreadReturnFirstArgument';
		$thread = __NAMESPACE__ . '\\' . $thread;

		$pool = new ThreadPool($thread, $threads, null, $debug);

		$jobs = array();

		$i = 0;
		$left = $num;
		$maxI = ceil($num * 1.5);
		$worked = array();
		do {
			while ($pool->hasWaiting() && $left > 0) {
				$arg = mt_rand(100, 2000);
				if ($bigResult) {
					$arg = str_repeat($arg, 10000);
				}
				if (!$threadId = $pool->run($arg)) {
					throw new Exception('Pool slots error');
				}
				$this->assertTrue(!isset($jobs[$threadId]));
				$jobs[$threadId] = $arg;
				$worked[$threadId] = true;
				$left--;
			}
			if ($results = $pool->wait()) {
				foreach ($results as $threadId => $res) {
					$num--;
					$this->assertTrue(isset($jobs[$threadId]));
					$this->assertEquals($jobs[$threadId], $res);
					unset($jobs[$threadId]);
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertEquals(0, $num);

		$this->assertEquals(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$pool->cleanup();
		$this->assertEquals(0, $pool->threadsCount);
		$this->assertEmpty($pool->threads);
		$this->assertEmpty($pool->waiting);
		$this->assertEmpty($pool->working);
		$this->assertEmpty($pool->initializing);
		$this->assertEmpty($pool->failed);
		$this->assertEmpty($pool->results);
	}

	/**
	 * Pool, events
	 *
	 * @param bool $debug
	 * @param bool $async
	 *
	 * @throws Exception
	 */
	function processPoolEvent($debug, $async = false)
	{
		$events  = 5;
		$num     = 15;
		$threads = 3;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			     "Thread pool events test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			     '-----------------------', PHP_EOL;
		}

		$pool = new ThreadPool(
			__NAMESPACE__ . '\TestThreadEvents',
			$threads, null, $debug
		);

		$test = $this;
		$arg  = mt_rand(12, 987);
		$jobs = $worked = array();
		$cb = function($event, $threadId, $e_data, $e_arg) use ($arg, $test, &$jobs, &$async) {
			/** @var $test TestCase */
			$test->assertEquals($arg, $e_arg);
			$test->assertEquals(TestThreadEvents::EV_PROCESS, $event);
			if ($async) {
				$test->assertTrue(isset($jobs[$threadId]));
				$test->assertEquals($jobs[$threadId]++, $e_data);
			} else {
				if (!isset($jobs[$threadId])) {
					$jobs[$threadId] = 0;
				}
				$test->assertEquals($jobs[$threadId]++, $e_data);
			}
		};
		$pool->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);


		$i = 0;
		$left = $num;
		$maxI = ceil($num * 1.5);
		do {
			while ($pool->hasWaiting() && $left > 0) {
				if (!$threadId = $pool->run($events)) {
					throw new Exception('Pool slots error');
				}
				if ($async) {
					$this->assertTrue(!isset($jobs[$threadId]));
					$jobs[$threadId] = 0;
				}
				$worked[$threadId] = true;
				$left--;
			}
			if ($results = $pool->wait()) {
				foreach ($results as $threadId => $res) {
					$num--;
					$this->assertTrue(isset($jobs[$threadId]));
					$this->assertEquals($events, $jobs[$threadId]);
					unset($jobs[$threadId]);
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertEquals(0, $num);

		$this->assertEquals(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$pool->cleanup();
		$this->assertEquals(0, $pool->threadsCount);
		$this->assertEmpty($pool->threads);
		$this->assertEmpty($pool->waiting);
		$this->assertEmpty($pool->working);
		$this->assertEmpty($pool->initializing);
		$this->assertEmpty($pool->failed);
		$this->assertEmpty($pool->results);
	}

	/**
	 * Pool, errors
	 *
	 * @param bool $debug
	 *
	 * @throws Exception
	 */
	function processPoolErrorable($debug)
	{
		$num     = 10;
		$threads = 2;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			     "Errorable thread pool test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			     '-----------------------', PHP_EOL;
		}

		$pool = new ThreadPool(
			__NAMESPACE__ . '\TestThreadReturnArgErrors',
			$threads, null, $debug
		);

		$jobs = $worked = array();

		$i = 0;
		$j = 0;
		$left = $num;
		$maxI = ceil($num * 2.5);
		do {
			while ($pool->hasWaiting() && $left > 0) {
				$arg = mt_rand(1000000, 20000000);
				if (!$threadId = $pool->run($arg, $j)) {
					throw new Exception('Pool slots error');
				}
				$this->assertTrue(!isset($jobs[$threadId]));
				$jobs[$threadId] = $arg;
				$worked[$threadId] = true;
				$left--;
				$j++;
			}
			if ($results = $pool->wait($failed)) {
				foreach ($results as $threadId => $res) {
					$num--;
					$this->assertTrue(isset($jobs[$threadId]));
					$this->assertEquals($jobs[$threadId], $res);
					unset($jobs[$threadId]);
				}
			}
			if ($failed) {
				foreach ($failed as $threadId) {
					$this->assertTrue(isset($jobs[$threadId]));
					unset($jobs[$threadId]);
					$left++;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertEquals(0, $num);

		$this->assertEquals(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$pool->cleanup();
		$this->assertEquals(0, $pool->threadsCount);
		$this->assertEmpty($pool->threads);
		$this->assertEmpty($pool->waiting);
		$this->assertEmpty($pool->working);
		$this->assertEmpty($pool->initializing);
		$this->assertEmpty($pool->failed);
		$this->assertEmpty($pool->results);
	}
}



/**
 * Test thread
 */
class TestThreadReturnFirstArgument extends Thread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadReturnArgErrors extends Thread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		if (1 & (int)$this->getParam(1)) {
			// Emulate terminating
			posix_kill($this->pid, SIGKILL);
			exit;
		}
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadNothing extends Thread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
	}
}

/**
 * Test thread
 */
class TestThreadWithChilds extends Thread
{


	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		$res = `echo 1`;
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadReturn extends Thread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		return array(123456789, 'abcdefghigklmnopqrstuvwxyz', 123.7456328);
	}
}

/**
 * Test thread
 */
class TestThreadWork extends Thread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		$r = null;
		$i = 1000;
		while ($i--) {
			$r = mt_rand(0, PHP_INT_MAX) * mt_rand(0, PHP_INT_MAX);
		}
		return $r;
	}
}

/**
 * Test thread
 */
class TestThreadEvents extends Thread
{
	const EV_PROCESS = 'process';


	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		$events = $this->getParam(0);
		for ($i = 0; $i < $events; $i++) {
			$this->trigger(self::EV_PROCESS, $i);
		}
	}
}
