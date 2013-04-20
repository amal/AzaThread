<?php

namespace Aza\Components\Thread\Tests;
use Aza\Components\CliBase\Base;
use Aza\Components\Log\Logger;
use Aza\Components\Socket\Socket;
use Aza\Components\Thread\Exceptions\Exception;
use Aza\Components\Thread\Thread;
use Aza\Components\Thread\ThreadPool;
use InvalidArgumentException;
use PHPUnit_Framework_TestCase as TestCase;
use ReflectionMethod;
use ReflectionProperty;

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
		$debug = false;

		Thread::$useForks = false;
		$this->processThreadEvent($debug);


		// Non-closure test
		$thread = new TestThreadEvents($debug);

		$thread->bind(
			TestThreadEvents::EV_PROCESS,
			array($this, 'processEvent')
		);

		$count = $this->getCount();
		$thread->run(1)->wait();

		$this->assertSame($count+3, $this->getCount());
		$this->assertSame(Thread::STATE_WAIT, $thread->getState());
		$this->assertTrue($thread->getSuccess());

		$thread->cleanup();
	}

	/**
	 * Thread arguments mapping test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadArgumentsMapping()
	{
		Thread::$useForks = false;
		$this->processThreadArgumentsMapping(false);
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
		$debug = false;

		Thread::$useForks = false;
		$this->processPoolEvent($debug);


		// Non-closure test
		$pool = new ThreadPool(
			__NAMESPACE__ . '\TestThreadEvents',
			1, null, $debug
		);

		$pool->bind(
			TestThreadEvents::EV_PROCESS,
			array($this, 'processEvent')
		);

		$pool->run(1);
		$pool->wait();

		$pool->cleanup();
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
		}, true);
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
	 * Errorable thread test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadErrorable()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadErrorable(false);
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
		}, true);


		// Some other pool tests
		$threads = 2;
		$thread  = __NAMESPACE__ . '\TestThreadReturnAllArguments';
		$pool    = new ThreadPool($thread, $threads);

		$state = $pool->getState();
		$this->assertSame($threads, count($state));
		foreach ($state as $s) {
			$this->assertSame('INIT', $s);
		}

		$this->assertFalse($pool->run());
		$this->assertFalse($pool->run('example'));

		$data = array(
			array(),
			array(1),
			array(1, 2),
			array(1, 2, 3),
			array(1, 2, 3, 4),
			array(1, 2, 3, 4, 5),
		);
		$jobs   = array();
		$i      = 0;
		$num    = count($data);
		$left   = $num;
		$maxI   = ceil($num * 1.5);
		$worked = array();
		do {
			while ($pool->hasWaiting() && $left > 0) {
				$args = array_shift($data);
				if (!$threadId = call_user_func_array(array($pool, 'run'), $args)) {
					throw new Exception('Pool slots error');
				}
				$this->assertTrue(!isset($jobs[$threadId]));
				$jobs[$threadId] = $args;
				$worked[$threadId] = true;
				$left--;
			}
			if ($results = $pool->wait()) {
				foreach ($results as $threadId => $res) {
					$this->assertTrue(isset($jobs[$threadId]));
					$this->assertEquals($jobs[$threadId], $res);
					unset($jobs[$threadId]);
					$num--;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$pool->cleanup();

		$this->assertSame(0, $num);

		$state = $pool->getState();
		$this->assertSame(0, count($state));
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
		}, true);
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
		}, true);
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
		}, true);
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
		}, true);
	}

	/**
	 * Errorable thread pool test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolErrorable()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPoolErrorable(false);
		}, true);
	}

	/**
	 * Thread arguments mapping test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testThreadArgumentsMapping()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadArgumentsMapping(false);
		}, true);
	}

	/**
	 * Test for exception in events triggering
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testExceptionInEventListener()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$thread = new TestThreadEvents();

			$thread->bind(
				TestThreadEvents::EV_PROCESS,
				function($event, $e_data, $e_arg) use ($testCase) {
					$testCase->assertSame(null, $e_arg);
					$testCase->assertSame(TestThreadEvents::EV_PROCESS, $event);
					$testCase->assertEquals(0, $e_data);

					throw new InvalidArgumentException('Example Message');
				}
			);

			try {
				$thread->wait()->run(1)->wait();
			} catch (InvalidArgumentException $e) {
				/** @var $testCase TestCase */
				$catched = true;
				$testCase->assertTrue($e instanceof InvalidArgumentException);
				$testCase->assertContains(
					'Example Message', $e->getMessage()
				);
				unset($e);
			}
			$testCase->assertFalse(empty($catched));

			$thread->cleanup();
		}, true);
	}

	/**
	 * Prefork wait timeout test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testPreforkWaitTimeout()
	{
		$debug    = false;
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase, $debug) {
			if ($debug) {
				echo '-------------------------', PHP_EOL,
				"Prefork wait timeout test ", PHP_EOL,
				'-------------------------', PHP_EOL;
			}

			try {
				$thread = new TestPreforkWaitTimeout($debug);
			} catch (Exception $e) {
				/** @var $testCase TestCase */
				$catched = true;
				$testCase->assertTrue($e instanceof Exception);
				$testCase->assertContains(
					'Exceeded timeout: thread initialization',
					$e->getMessage()
				);
				unset($e);
			}
			$testCase->assertFalse(empty($catched));
			$testCase->assertTrue(empty($thread));
		}, true);
	}

	/**
	 * Result wait timeout test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testResultWaitTimeout()
	{
		$debug    = false;
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase, $debug) {
			if ($debug) {
				echo '-------------------------', PHP_EOL,
				"Result wait timeout test ", PHP_EOL,
				'-------------------------', PHP_EOL;
			}

			try {
				$thread = new TestResultWaitTimeout($debug);
				$thread->wait()->run()->wait();
			} catch (Exception $e) {
				/** @var $testCase TestCase */
				$catched = true;
				$testCase->assertTrue($e instanceof Exception);
				$testCase->assertContains(
					'Exceeded timeout: thread work',
					$e->getMessage()
				);
				unset($e);
			}

			$testCase->assertFalse(empty($catched));
			$testCase->assertFalse(empty($thread));
			$testCase->assertFalse($thread->getSuccess());
			$testCase->assertSame(Thread::STATE_WAIT, $thread->getState());

			$thread->cleanup();
		}, true);
	}

	/**
	 * Job wait timeout test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testJobWaitTimeout()
	{
		$debug    = false;
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase, $debug) {
			if ($debug) {
				echo '-------------------------', PHP_EOL,
				"Job wait timeout test ", PHP_EOL,
				'-------------------------', PHP_EOL;
			}

			$thread = new TestJobWaitTimeout($debug);

			// To certainly meet the timeout
			usleep(2);

			$thread->wait()->run()->wait();

			$testCase->assertFalse($thread->getSuccess());
			$testCase->assertSame(Thread::STATE_WAIT, $thread->getState());

			$thread->cleanup();
		}, true);
	}

	/**
	 * Parent check timeout test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testParentCheckTimeout()
	{
		$debug    = false;
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase, $debug) {
			if ($debug) {
				echo '-------------------------', PHP_EOL,
				"Parent check timeout test ", PHP_EOL,
				'-------------------------', PHP_EOL;
			}

			$thread = new TestParentCheckTimeout($debug);

			$childChildPid = $childPid = null;
			$thread->bind(
				TestParentCheckTimeout::EV_PID,
				function($event, $e_data) use (&$childPid, &$childChildPid, $testCase) {
					$testCase->assertSame(TestParentCheckTimeout::EV_PID, $event);
					$childPid      = $e_data[0];
					$childChildPid = $e_data[1];
				}
			)->wait()->run()->wait();

			// PIDs
			$testCase->assertNotEmpty($childPid);
			$testCase->assertNotEmpty($childChildPid);

			// Child is dead
			$isAliveChild = posix_kill($childPid, 0);
			$testCase->assertFalse($isAliveChild);

			// Child's child can be alive in that moment, so wait a litle
			usleep(20000);
			$i = 10;
			do {
				$isAliveChildChild = posix_kill($childChildPid, 0);
				if (!$isAliveChildChild) {
					break;
				}
				usleep(10000);
				$i--;
			} while ($i > 0);
			$testCase->assertFalse($isAliveChildChild);
		}, true);
	}

	/**
	 * Thread events with locking test
	 *
	 * @author amal
	 * @group integrational
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithEventsAndLocking()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadEvent(false, true);
		});
	}

	#endregion



	#region Auxiliary (helper) test methods

	/**
	 * Helper method for testing threads in asynchronous mode
	 *
	 * @param callable $callback
	 * @param bool     $simple   More simple test
	 *
	 * @throws \Exception
	 */
	public function processAsyncTest($callback, $simple = false)
	{
		if (!Thread::$useForks) {
			$this->markTestSkipped(
				'You need LibEvent, PCNTL and POSIX support'
				.' with CLI sapi to fully test Threads'
			);
			return;
		}

		if ($simple) {
			$ipcModes    = array(Thread::$ipcDataMode => false);
			$socketModes = array(Socket::$useSockets);
		} else {
			$ipcModes    = array(
				Thread::IPC_IGBINARY  => 'igbinary_serialize',
				Thread::IPC_SERIALIZE => false,
			);
			$socketModes = array(true, false);
		}

		$defSocketMode = Socket::$useSockets;

		foreach ($socketModes as $socketMode) {
			Socket::$useSockets = $socketMode;

			foreach ($ipcModes as $mode => $check) {
				if ($check && !function_exists($check)) {
					continue;
				}

				Thread::$ipcDataMode = $mode;

				try {
					if (false === $callback()) {
						break 2;
					}
				} catch (\Exception $e) {
					if ($base = Base::getEventBase(false)) {
						$base->loopBreak();
					}
					throw $e;
				}
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

		if (Thread::$useForks) {
			$this->assertNotEmpty($thread->getEventBase());
		} else {
			$this->assertEmpty($thread->getEventBase());
		}

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

		$this->assertEmpty($thread->getEventBase());

		$this->assertSame('TERM', $thread->getStateName());
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
	 * @param bool $withLocking
	 *
	 * @throws \Exception
	 */
	function processThreadEvent($debug = false, $withLocking = false)
	{
		$events = 11;
		$num = 3;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread events test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = $withLocking
				? new TestThreadEventsWithLocking($debug)
				: new TestThreadEvents($debug);

		$test = $this;
		$arg = mt_rand(12, 987);
		$last = 0;
		$cb = function($event, $e_data, $e_arg) use ($arg, $test, &$last) {
			$test->assertSame($arg, $e_arg);
			$test->assertSame(TestThreadEvents::EV_PROCESS, $event);
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
			$this->assertSame(Thread::STATE_WAIT, $state);
			$sucess = $thread->getSuccess();
			$this->assertTrue($sucess);
		}

		$this->assertSame($events, $last);

		$thread->cleanup();
	}

	/**
	 * Arguments mapping
	 *
	 * @param bool $debug
	 */
	function processThreadArgumentsMapping($debug = false)
	{
		$num = 3;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = new TestThreadArgumentsMapping($debug);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		for ($i = 0; $i < $num; $i++) {
			$thread->run(123, 456, 789)->wait();
			$state = $thread->getState();
			$this->assertSame(Thread::STATE_WAIT, $state);
			$sucess = $thread->getSuccess();
			$this->assertTrue($sucess);
			$result = $thread->getResult();
			$this->assertEquals(array(123, 456, 789), $result);
		}

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
		$threads       = 2;
		$targetThreads = $threads+2;
		$num           = $targetThreads*5;

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

		$ref = new ReflectionProperty($pool, 'maxThreads');
		$ref->setAccessible(true);

		if (Thread::$useForks) {
			$this->assertSame($threads, $pool->threadsCount);
			$this->assertSame($threads, $ref->getValue($pool));

			// We can not set number of threads lower than
			// number of already created threads
			$pool->setMaxThreads($threads-1);
			$this->assertSame($threads, $pool->threadsCount);
			$this->assertSame($threads, $ref->getValue($pool));

			// But we can set more. And they are not created immediately
			$pool->setMaxThreads($targetThreads);
			$this->assertSame($threads, $pool->threadsCount);
			$this->assertSame($targetThreads, $ref->getValue($pool));
		} else {
			$this->assertSame(1, $pool->threadsCount);
			$this->assertSame(1, $ref->getValue($pool));

			$pool->setMaxThreads(8);
			$this->assertSame(1, $pool->threadsCount);
			$this->assertSame(1, $ref->getValue($pool));

			$pool->setMaxThreads(0);
			$this->assertSame(1, $pool->threadsCount);
			$this->assertSame(1, $ref->getValue($pool));
		}

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
					$this->assertTrue(isset($jobs[$threadId]));
					$this->assertEquals($jobs[$threadId], $res);
					unset($jobs[$threadId]);
					$num--;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertSame(0, $num);

		$this->assertSame(
			$pool->threadsCount, count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$state = $pool->getState();

		if (Thread::$useForks) {
			$this->assertSame($targetThreads, $pool->threadsCount);
			$this->assertSame($targetThreads, count($state));
		} else {
			$this->assertSame(1, $pool->threadsCount);
			$this->assertSame(1, count($state));
		}

		foreach ($state as $s) {
			$this->assertSame('WAIT', $s);
		}

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
		$events  = 3;
		$num     = 6;
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
		$num     = 6;
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


	/**
	 * Event callback for tests
	 */
	function processEvent($event, $e_data, $e_arg)
	{
		$this->assertSame(TestThreadEvents::EV_PROCESS, $event);
		$this->assertEquals(0, $e_arg);
		$this->assertNotSame(null, $e_data);
	}

	#endregion
}



#region Test mocks

/**
 * Test thread
 */
class TestThreadArgumentsMapping extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	protected $argumentsMapping = true;

	/**
	 * {@inheritdoc}
	 */
	public function process($a, $b, $c)
	{
		return array($a, $b, $c);
	}
}

/**
 * Test thread
 */
class TestThreadReturnFirstArgument extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadReturnAllArguments extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		return $this->params;
	}
}

/**
 * Test thread
 */
class TestThreadReturnArgErrors extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	public function process()
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
class TestThreadWithChilds extends Thread
{
	/**
	 * Enable prefork waiting to test it
	 */
	protected $preforkWait = true;

	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		/** @noinspection PhpUnusedLocalVariableInspection */
		$res = `echo 1`;
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadEvents extends Thread
{
	const EV_PROCESS = 'process';

	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		$events = $this->getParam(0);
		for ($i = 0; $i < $events; $i++) {
			$this->trigger(self::EV_PROCESS, $i);
		}
	}
}

/**
 * Test thread
 */
class TestThreadEventsWithLocking extends TestThreadEvents
{
	/**
	 * {@inheritdoc}
	 */
	protected $eventLocking = true;
}

/**
 * Test thread
 */
class TestPreforkWaitTimeout extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	protected $preforkWait = true;

	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterInitWait = 0.000001;

	/**
	 * {@inheritdoc}
	 */
	protected function onFork()
	{
		// To certainly meet the timeout
		usleep(2);
	}

	/**
	 * {@inheritdoc}
	 */
	public function process() {}
}

/**
 * Test thread
 */
class TestResultWaitTimeout extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterResultWait = 0.000001;

	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		// To certainly meet the timeout
		usleep(2);
	}
}

/**
 * Test thread
 */
class TestJobWaitTimeout extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	protected $timeoutWorkerJobWait = 0.000001;

	/**
	 * {@inheritdoc}
	 */
	public function process() {}
}

/**
 * Test thread
 */
class TestParentCheckTimeout extends Thread
{
	const EV_PID = 'pid';

	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterResultWait = 0.1;

	/**
	 * {@inheritdoc}
	 */
	public function process()
	{
		// Create new thread. Set debug flag from this thread
		$child = new TestParentCheckTimeoutChild($this->debug);

		// Send child PID to parent via event
		$childPid = $child->wait()->child_pid;
		$this->trigger(self::EV_PID, array($this->pid, $childPid));

		// Emulate terminating
		$this->killWorker();
		posix_kill($this->pid, SIGKILL);
		exit;
	}
}

/**
 * Test thread
 */
class TestParentCheckTimeoutChild extends Thread
{
	/**
	 * {@inheritdoc}
	 */
	protected $intervalWorkerMasterChecks = 0.001;

	/**
	 * {@inheritdoc}
	 */
	public function process() {}
}

#endregion
