<?php

namespace Aza\Components\Thread\Tests;
use Aza\Components\Log\Logger;
use Aza\Components\Socket\Socket;
use Aza\Components\Thread\Thread;
use Aza\Components\Thread\ThreadPool;
use Exception;
use PHPUnit_Framework_TestCase as TestCase;
use ReflectionMethod;

/**
 * Testing thread system
 *
 * @project Anizoptera CMF
 * @package system.thread
 */
class ThreadTest extends TestCase
{
	/** @var bool */
	protected static $defUseForks;

	/** @var int */
	protected static $defIpcDataMode;


	/**
	 * {@inheritdoc}
	 */
	public static function setUpBeforeClass()
	{
		self::$defUseForks    = Thread::$useForks;
		self::$defIpcDataMode = Thread::$ipcDataMode;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function setUp()
	{
		// Set values to default
		Thread::$useForks    = self::$defUseForks;
		Thread::$ipcDataMode = self::$defIpcDataMode;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function tearDownAfterClass()
	{
		// Set values to default
		Thread::$useForks    = self::$defUseForks;
		Thread::$ipcDataMode = self::$defIpcDataMode;

		// Cleanup
		gc_collect_cycles();
	}



	/**
	 * Tests threads debugging
	 *
	 * @author amal
	 * @group unit
	 */
	public function testDebug()
	{
		$this->expectOutputString('');

		Thread::$useForks = false;

		$value = 'test12345';

		// Test thread debug output
		$thread = new TestThreadReturnFirstArgument();
		$this->assertSame(Thread::STATE_WAIT, $thread->getState());
		$ref = new ReflectionMethod($thread, 'debug');
		$ref->setAccessible(true);
		ob_start(); ob_start();
		$thread->debug = true;
		$ref->invoke($thread, $value);
		$thread->debug = false;
		ob_get_clean();
		$output = ob_get_clean();
		$this->assertContains($value, $output);
		$thread->cleanup();

		// Test thread pool debug output
		$className = get_class($thread);
		ob_start(); ob_start();
		$pool = new ThreadPool($className);
		$ref = new ReflectionMethod($pool, 'debug');
		$ref->setAccessible(true);
		$pool->debug = true;
		$ref->invoke($pool, $value);
		$pool->debug = false;
		ob_get_clean();
		$output = ob_get_clean();
		$this->assertContains($value, $output);
		$pool->cleanup();
	}


	#region Synchronous (fallback mode) tests

	/**
	 * Simple thread test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThread()
	{
		Thread::$useForks = false;
		$this->processThread(false);
	}

	/**
	 * Thread test with big data (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadWithBigData()
	{
		Thread::$useForks = false;
		$this->processThread(false, true);
	}

	/**
	 * Thread test with childs (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadWithChilds()
	{
		Thread::$useForks = false;
		$this->processThread(false, false, true);
	}

	/**
	 * Thread test with childs and big data (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadWithBigDataAndChilds()
	{
		Thread::$useForks = false;
		$this->processThread(false, true, true);
	}

	/**
	 * Thread events test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadWithEvents()
	{
		Thread::$useForks = false;
		$this->processThreadEvent(false);
	}

	/**
	 * Simple thread pool test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPool()
	{
		Thread::$useForks = false;
		$this->processPool(false);
	}

	/**
	 * Thread pool test with big data (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPoolWithBigData()
	{
		Thread::$useForks = false;
		$this->processPool(false, true);
	}

	/**
	 * Thread pool test with childs (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPoolWithChilds()
	{
		Thread::$useForks = false;
		$this->processPool(false, false, true);
	}

	/**
	 * Thread pool test with childs and big data (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPoolWithBigDataAndChilds()
	{
		Thread::$useForks = false;
		$this->processPool(false, true, true);
	}

	/**
	 * Thread pool events test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPoolWithEvents()
	{
		Thread::$useForks = false;
		$this->processPoolEvent(false);
	}

	#endregion


	#region Full feature tests

	/**
	 * Simple thread test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThread()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false);
		});
	}

	/**
	 * Thread test with big data
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithBigData()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false, true);
		});
	}

	/**
	 * Thread test with childs
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithChilds()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false, false, true);
		});
	}

	/**
	 * Thread test with childs and big data
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithBigDataAndChilds()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false, true, true);
		});
	}

	/**
	 * Thread events test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithEvents()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadEvent(false);
		});
	}

	/**
	 * Simple thread pool test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPool()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPool(false);
		});
	}

	/**
	 * Thread pool test with big data
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolWithBigData()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPool(false, true);
		});
	}

	/**
	 * Thread pool test with childs
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolWithChilds()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPool(false, false, true);
		});
	}

	/**
	 * Thread pool test with childs and big data
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolWithBigDataAndChilds()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPool(false, true, true);
		});
	}

	/**
	 * Thread pool events test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolWithEvents()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPoolEvent(false);
		});
	}

	#endregion


	#region Auxiliary (helper) test methods

	/**
	 * Helper method for testing threads in asynchronous mode
	 *
	 * @param callable $callback
	 */
	public function processAsyncTest($callback)
	{
		if (!Thread::$useForks) {
			$this->markTestSkipped(
				'You need LibEvent, PCNTL and POSIX support'
				.' with CLI sapi to fully test Threads'
			);
			return;
		}

		$ipcModes    = array(
			Thread::IPC_IGBINARY  => 'igbinary_serialize',
			Thread::IPC_SERIALIZE => false,
		);
		$socketModes = array(true, false);

		$defSocketMode = Socket::$useSockets;

		foreach ($socketModes as $socketMode) {
			Socket::$useSockets = $socketMode;

			foreach ($ipcModes as $mode => $check) {
				if ($check && !function_exists($check)) {
					continue;
				}

				Thread::$ipcDataMode = $mode;

				$callback();
			}
		}

		Socket::$useSockets  = $defSocketMode;
	}


	/**
	 * Thread
	 *
	 * @param bool $debug
	 * @param bool $bigResult
	 * @param bool $withChild
	 */
	function processThread($debug = false, $bigResult = false, $withChild = false)
	{
		$num = 10;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

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
			$this->assertSame(Thread::STATE_WAIT, $state);
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
	function processThreadErrorable($debug = false)
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
			$this->assertSame(Thread::STATE_WAIT, $state);
			if ($thread->getSuccess()) {
				$result = $thread->getResult();
				$this->assertEquals($value, $result);
				$value = ++$i;
			}
		}

		$this->assertSame($num*2, $j);

		$thread->cleanup();
	}

	/**
	 * Thread, events
	 *
	 * @param bool $debug
	 */
	function processThreadEvent($debug = false)
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
			$test->assertSame($arg, $e_arg);
			$test->assertSame(TestThreadEvents::EV_PROCESS, $event);
			$test->assertSame($last++, $e_data);
		};
		$thread->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		for ($i = 0; $i < $num; $i++) {
			$last = 0;
			$thread->run($events)->wait();
			$state = $thread->getState();
			$this->assertSame(Thread::STATE_WAIT, $state);
			$sucess = $thread->getSuccess();
			$this->assertTrue($sucess);
		}

		$this->assertSame($events, $last);

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
	function processPool($debug = false, $bigResult = false, $withChild = false)
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

		$this->assertSame(0, $num);

		$this->assertSame(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$pool->cleanup();
		$this->assertSame(0, $pool->threadsCount);
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
	function processPoolEvent($debug = false, $async = false)
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
			$test->assertSame($arg, $e_arg);
			$test->assertSame(TestThreadEvents::EV_PROCESS, $event);
			if ($async) {
				$test->assertTrue(isset($jobs[$threadId]));
				$test->assertSame($jobs[$threadId]++, $e_data);
			} else {
				if (!isset($jobs[$threadId])) {
					$jobs[$threadId] = 0;
				}
				$test->assertSame($jobs[$threadId]++, $e_data);
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
					$this->assertSame($events, $jobs[$threadId]);
					unset($jobs[$threadId]);
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertSame(0, $num);

		$this->assertSame(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$pool->cleanup();
		$this->assertSame(0, $pool->threadsCount);
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

		$this->assertSame(0, $num);

		$this->assertSame(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$pool->cleanup();
		$this->assertSame(0, $pool->threadsCount);
		$this->assertEmpty($pool->threads);
		$this->assertEmpty($pool->waiting);
		$this->assertEmpty($pool->working);
		$this->assertEmpty($pool->initializing);
		$this->assertEmpty($pool->failed);
		$this->assertEmpty($pool->results);
	}

	#endregion
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
		/** @noinspection PhpUnusedLocalVariableInspection */
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
