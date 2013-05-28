<?php

namespace Aza\Components\Thread\Tests;
use Aza\Components\CliBase\Base;
use Aza\Components\LibEvent\EventBase;
use Aza\Components\Log\Logger;
use Aza\Components\Socket\Socket;
use Aza\Components\Thread\Exceptions\Exception;
use Aza\Components\Thread\SimpleThread;
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
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
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

		// Cleanup
		EventBase::cleanAllLoops();
		Thread::cleanAll();
		gc_collect_cycles();
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
		EventBase::cleanAllLoops();
		Thread::cleanAll();
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

	/**
	 * Tests external configured thread
	 *
	 * @author amal
	 * @group unit
	 */
	public function testExternalConfiguration()
	{
		Thread::$useForks = false;

		$property = 'timeoutWorkerJobWait';
		$expected = -mt_rand(100, 999);

		$thread = new TestThreadReturnFirstArgument(
			null, null, false, array($property => $expected)
		);
		$ref = new ReflectionProperty($thread, $property);
		$ref->setAccessible(true);
		$value = $ref->getValue($thread);
		$this->assertSame($expected, $value);

		$thread = new TestThreadReturnFirstArgument();
		$value = $ref->getValue($thread);
		$this->assertNotSame($expected, $value);
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
	 * Additional thread test
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThread2()
	{
		Thread::$useForks = false;
		$this->processThread2(false);
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
		$thread = new TestThreadEvents(null, null, $debug);

		$thread->bind(
			TestThreadEvents::EV_PROCESS,
			array($this, 'processEventCallback')
		);

		$count = $this->getCount();
		$thread->run(1)->wait();

		$this->assertSame($count+3, $this->getCount());
		$this->assertSame(Thread::STATE_WAIT, $thread->getState());
		$this->assertTrue(
			$thread->getSuccess(),
			'Job failure - #' . $thread->getLastErrorCode()
			. ' ' . $thread->getLastErrorMsg()
		);

		$thread->cleanup();
	}

	/**
	 * Thread events test with big data (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadWithEventsBigData()
	{
		Thread::$useForks = false;
		$this->processThreadEvent(false, true);
	}

	/**
	 * Errorable thread test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadErrorable()
	{
		Thread::$useForks = false;
		$this->processThreadErrorable(false);
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
	 * Multitask disabled thread test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 * @ticket GitHub Anizoptera/AzaThread/#6
	 */
	public function testSyncThreadOnetask()
	{
		Thread::$useForks = false;
		$this->processThread(false, true, false, true);
	}

	/**
	 * Closure thread test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadClosure()
	{
		Thread::$useForks = false;
		$this->processThreadClosure(false);
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
	 * Additional thread pool test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPool2()
	{
		Thread::$useForks = false;
		$this->processPool2(false);
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
			1, null, null, $debug
		);

		$pool->bind(
			TestThreadEvents::EV_PROCESS,
			array($this, 'processEventCallback')
		);

		$threadId = $pool->run(1);
		$this->assertNotEmpty($threadId);

		$pool->wait();

		$pool->cleanup();
	}

	/**
	 * Errorable thread pool test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPoolErrorable()
	{
		Thread::$useForks = false;
		$this->processPoolErrorable(false);
	}

	/**
	 * Thread pool test with external stop (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncThreadPoolErrorableExternal()
	{
		Thread::$useForks = false;
		$this->processPoolErrorableExternal(false);
	}

	/**
	 * Thread pool test with multitask disabled (sync mode)
	 *
	 * @author amal
	 * @group unit
	 * @ticket GitHub Anizoptera/AzaThread/#6
	 */
	public function testSyncPoolOnetask()
	{
		Thread::$useForks = false;
		$this->processPool(false, false, true, true);
	}


	/**
	 * Test for exception in events triggering (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncExceptionInEventListener()
	{
		Thread::$useForks = false;
		$this->processExceptionInEventListener(false);
	}

	/**
	 * Two different threads test (sync mode)
	 *
	 * @author amal
	 * @group unit
	 */
	public function testSyncTwoDifferentThreads()
	{
		Thread::$useForks = false;
		$this->processTwoDifferentThreads(false);
	}

	#endregion


	#region Full feature tests

	/**
	 * Simple thread test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThread()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false);
		}, true);
	}

	/**
	 * Additional thread test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThread2()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread2(false);
		}, true);
	}

	/**
	 * Thread test with big data
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithBigData()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false, true);
		}, true);
	}

	/**
	 * Thread test with childs
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithChilds()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThread(false, false, true);
		}, true);
	}

	/**
	 * Thread test with childs and big data
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
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
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithEvents()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadEvent(false);
		}, true);
	}

	/**
	 * Thread events test with big data
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadWithEventsBigData()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadEvent(false, true);
		}, true);
	}

	/**
	 * Errorable thread test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadErrorable()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadErrorable(false);
		}, true);
	}

	/**
	 * Thread arguments mapping test (sync mode)
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadArgumentsMapping()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadArgumentsMapping(false);
		}, true);
	}

	/**
	 * Multitask disabled thread test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 * @ticket GitHub Anizoptera/AzaThread/#6
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadOnetask()
	{
		$debug    = false;
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase, $debug) {
			$testCase->processThread($debug, true, false, true);
			$testCase->processThread($debug, false, false, true);
		}, true);
	}

	/**
	 * Closure thread test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadClosure()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processThreadClosure(false);
		}, true);
	}


	/**
	 * Simple thread pool test
	 *
	 * @author amal
	 * @group integrational
	 * @group pool
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
	}

	/**
	 * Additional thread pool test
	 *
	 * @author amal
	 * @group integrational
	 * @group pool
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPool2()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPool2(false);
		}, true);
	}

	/**
	 * Thread pool test with big data
	 *
	 * @author amal
	 * @group integrational
	 * @group pool
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
	 * @group pool
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
	 * @group pool
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
	 * @group pool
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

	/**
	 * Errorable thread pool test
	 *
	 * @author amal
	 * @group integrational
	 * @group pool
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolErrorable()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPoolErrorable(false);
		});
	}

	/**
	 * Thread pool test with external stop (for issue)
	 *
	 * @author amal
	 * @group integrational
	 * @group pool
	 * @ticket GitHub Anizoptera/AzaThread/#2
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testThreadPoolErrorableExternal()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPoolErrorableExternal(false);
		});
	}

	/**
	 * Thread pool test with multitask disabled
	 *
	 * @author amal
	 * @group integrational
	 * @group pool
	 * @ticket GitHub Anizoptera/AzaThread/#6
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testPoolOnetask()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processPool(false, false, true, true);
		}, true);
	}


	/**
	 * Test for exception in events triggering
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testExceptionInEventListener()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processExceptionInEventListener(false);
		}, true);
	}

	/**
	 * Two different threads test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testTwoDifferentThreads()
	{
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase) {
			$testCase->processTwoDifferentThreads(false);
		});
	}

	/**
	 * POSIX signals handling test
	 *
	 * @author amal
	 * @group integrational
	 * @group thread
	 *
	 * @requires extension posix
	 * @requires extension pcntl
	 */
	public function testSignalsHandling()
	{
		$debug    = false;
		$testCase = $this;
		$this->processAsyncTest(function() use ($testCase, $debug) {
			if ($debug) {
				echo '----------------------------', PHP_EOL,
				"POSIX signals handling test ", PHP_EOL,
				'----------------------------', PHP_EOL;
			}


			// Random thread init, to test handling of signals for different threads
			$t = new TestThreadReturnFirstArgument(
				null, null, $debug
			);


			TestSignalsHandling::$catchedSignalsInParent = $counter = 0;
			$testCase->assertSame($counter, TestSignalsHandling::$catchedSignalsInParent);


			$thread = new TestSignalsHandling('SignalsHandling', null, $debug);
			$thread->wait();

			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);

			$catchedSignals = (int)$thread->run()->wait()->getResult();
			$testCase->assertTrue(0 === $catchedSignals);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK); // Needed sometimes if parent isn't catched signal yet
			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);

			$thread->sendSignalToChild(SIGUSR1);
			usleep(1000);
			$catchedSignals = (int)$thread->run()->wait()->getResult();
			$testCase->assertTrue(1 === $catchedSignals || 0 === $catchedSignals);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK); // Needed sometimes if parent isn't catched signal yet
			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);

			$thread->sendSignalToChild(SIGUSR1);
			usleep(1000);
			$catchedSignals = (int)$thread->run()->wait()->getResult();
			$testCase->assertTrue(2 === $catchedSignals || 1 === $catchedSignals);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK); // Needed sometimes if parent isn't catched signal yet
			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);

			$thread->sendSignalToChild(SIGUSR1);
			usleep(1000);
			$catchedSignals = (int)$thread->run()->wait()->getResult();
			$testCase->assertTrue(3 === $catchedSignals || 2 === $catchedSignals);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK); // Needed sometimes if parent isn't catched signal yet
			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);

			$thread->sendSignalToParent(SIGWINCH);
			$thread->sendSignalToParent(SIGCHLD);

			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK);
			$thread->cleanup();


			$thread = new TestSignalsHandling(null, null, $debug);
			$thread->wait()->sendSignalToChild(SIGUSR1);
			usleep(1000);
			$catchedSignals = (int)$thread->run()->wait()->getResult();
			$testCase->assertTrue(1 === $catchedSignals || 0 === $catchedSignals);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK); // Needed sometimes if parent isn't catched signal yet
			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK);
			$thread->cleanup();


			$thread = new TestSignalsHandling(null, null, $debug);
			$thread->sendSignalToChild(SIGUSR1)->wait();
			usleep(1000);
			$catchedSignals = (int)$thread->run()->wait()->getResult();
			$testCase->assertTrue(0 === $catchedSignals || 1 === $catchedSignals);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK); // Needed sometimes if parent isn't catched signal yet
			$testCase->assertSame($counter++, TestSignalsHandling::$catchedSignalsInParent);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK);
			$thread->cleanup();


			$t->cleanup();
			unset($counter);
		});
	}


	/**
	 * Prefork wait timeout test
	 *
	 * @author amal
	 * @group integrational
	 * @group timeout
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

			$thread = new TestPreforkWaitTimeout(
				null, null, $debug
			);

			$testCase->assertFalse($thread->getSuccess());
			$testCase->assertSame(
				Thread::ERR_TIMEOUT_INIT,
				$thread->getLastErrorCode()
			);
			$testCase->assertContains(
				'Exceeded timeout: thread initialization',
				$thread->getLastErrorMsg()
			);
			$testCase->assertSame(
				Thread::STATE_WAIT,
				$thread->getState()
			);

			$thread->cleanup();
		}, true);
	}

	/**
	 * Result wait timeout test
	 *
	 * @author amal
	 * @group integrational
	 * @group timeout
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

			$thread = new TestResultWaitTimeout(
				null, null, $debug
			);
			$thread->wait()->run()->wait();

			$testCase->assertFalse($thread->getSuccess());
			$testCase->assertSame(
				Thread::ERR_TIMEOUT_RESULT,
				$thread->getLastErrorCode()
			);
			$testCase->assertContains(
				'Exceeded timeout: thread work',
				$thread->getLastErrorMsg()
			);
			$testCase->assertSame(
				Thread::STATE_WAIT,
				$thread->getState()
			);

			$thread->cleanup();
		}, true);
	}

	/**
	 * Job wait timeout test
	 *
	 * @author amal
	 * @group integrational
	 * @group timeout
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
				"Job wait (by worker) timeout test ", PHP_EOL,
				'-------------------------', PHP_EOL;
			}

			$thread = new TestJobWaitTimeout(
				null, null, $debug
			);
			$pid = $thread->getChildPid();
			$thread->wait();

			// To certainly meet the timeout
			usleep(20000);
			$thread->getEventLoop()->loop(EVLOOP_NONBLOCK);

			if ($thread->getIsForked()) {
				$thread->run()->wait();
			}

			$testCase->assertSame(
				Thread::ERR_DEATH,
				$thread->getLastErrorCode()
			);
			$testCase->assertContains(
				'Worker is dead',
				$thread->getLastErrorMsg()
			);
			$testCase->assertSame(
				Thread::STATE_WAIT,
				$thread->getState()
			);

			$testCase->assertFalse($thread->getSuccess());
			$testCase->assertFalse($thread->getIsForked());

			$testCase->assertNotSame($pid, $thread->getChildPid());
			$testCase->assertSame(
				$thread->getParentPid(),
				$thread->getChildPid()
			);

			$thread->cleanup();
		}, true);
	}

	/**
	 * Parent check timeout test
	 *
	 * @author amal
	 * @group integrational
	 * @group timeout
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

			$thread = new TestParentCheckTimeout(
				null, null, $debug
			);
			$childPid = $thread->wait()->getChildPid();
			$testCase->assertNotEmpty($childPid);

			$childChildPid = null;
			$triggered = false;
			$thread->bind(
				TestParentCheckTimeout::EV_PID,
				function($event_name, $e_data)
				use (&$childChildPid, &$triggered, $testCase)
				{
					$triggered = true;
					$testCase->assertSame(
						TestParentCheckTimeout::EV_PID,
						$event_name
					);
					$childChildPid = $e_data;
				}
			)->run()->wait();

			$testCase->assertTrue($triggered);
			$testCase->assertNotEmpty($childChildPid);

			// Child is dead
			$isAliveChild = Base::getProcessIsAlive($childPid);
			$testCase->assertFalse($isAliveChild);

			// Child's child can be alive in that moment, so wait a litle
			usleep(20000);
			$i = 50;
			do {
				if (!$isAliveChildChild = Base::getProcessIsAlive($childChildPid)) {
					break;
				}
				usleep(20000);
				$i--;
			} while ($i > 0);
			$testCase->assertFalse($isAliveChildChild);

			$thread->cleanup();
		}, true);
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
	function processAsyncTest($callback, $simple = false)
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
					if ($base = EventBase::getMainLoop(false)) {
						$base->resource && $base->loopBreak();
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
	 * @param bool $oneTask
	 */
	function processThread($debug = false, $bigResult = false, $withChild = false, $oneTask = false)
	{
		$num = 10;

		$async = Thread::$useForks;
		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		if ($oneTask) {
			$thread = new TestThreadOneTask(null, null, $debug);
		} else {
			$thread = $withChild
					? new TestThreadWithChilds(null, null, $debug)
					: new TestThreadReturnFirstArgument(null, null, $debug);
		}

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		if ($async) {
			if ($oneTask) {
				$this->assertFalse($thread->getIsForked());
			} else {
				$this->assertTrue($thread->getIsForked());
			}
		}
		$this->assertFalse($thread->getIsChild());
		$this->assertSame(Thread::STATE_WAIT, $thread->getState());
		$this->assertEmpty(
			$thread->getLastErrorCode(),
			$thread->getLastErrorMsg()
		);
		$this->assertEmpty($thread->getLastErrorMsg());
		$this->assertFalse($thread->getIsCleaning());

		for ($i = 0; $i < $num; $i++) {
			$value = $bigResult ? str_repeat($i, 100000) : $i;
			$thread->run($value)->wait();
			$this->assertSame(Thread::STATE_WAIT, $thread->getState());
			$this->assertTrue(
				$thread->getSuccess(),
				'Job failure - #' . $thread->getLastErrorCode()
				. ' ' . $thread->getLastErrorMsg()
			);
			$this->assertEquals($value, $thread->getResult());
			$this->assertEmpty(
				$thread->getLastErrorCode(),
				$thread->getLastErrorMsg()
			);
			$this->assertEmpty($thread->getLastErrorMsg());
		}

		$this->assertSame($num, $thread->getStartedJobs());
		$this->assertSame($num, $thread->getSuccessfulJobs());
		$this->assertSame(0, $thread->getFailedJobs());

		$thread->cleanup();
		$this->assertTrue($thread->getIsCleaning());
		$this->assertSame('TERM', $thread->getStateName());
		$this->assertSame('INIT', $thread->getStateName(Thread::STATE_INIT));
	}

	/**
	 * Thread 2
	 *
	 * @param bool $debug
	 */
	function processThread2($debug = false)
	{
		$async = Thread::$useForks;
		if ($debug) {
			echo '-------------------------', PHP_EOL,
			"Additional thread test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-------------------------', PHP_EOL;
		}


		// Empty argument
		$thread = new TestThreadReturnAllArguments(
			null, null, $debug
		);
		$thread->wait()->run(null)->wait();
		$this->assertTrue(
			$thread->getSuccess(),
			'Job failure - #' . $thread->getLastErrorCode()
			. ' ' . $thread->getLastErrorMsg()
		);
		$this->assertSame(array(null), $thread->getResult());
		$this->assertEmpty(
			$thread->getLastErrorCode(),
			$thread->getLastErrorMsg()
		);
		$this->assertEmpty($thread->getLastErrorMsg());
		$thread->cleanup();


		// Empty result
		$thread = new TestThreadDoNothing(
			null, null, $debug
		);
		$thread->wait()->run()->wait();
		$this->assertTrue(
			$thread->getSuccess(),
			'Job failure - #' . $thread->getLastErrorCode()
			. ' ' . $thread->getLastErrorMsg()
		);
		$this->assertSame(null, $thread->getResult());
		$this->assertEmpty(
			$thread->getLastErrorCode(),
			$thread->getLastErrorMsg()
		);
		$this->assertEmpty($thread->getLastErrorMsg());
		$thread->cleanup();


		// Hooks, etc.
		$thread = new TestThreadHooks(
			null, null, $debug
		);

		$isAlive = new ReflectionMethod($thread, 'isAlive');
		$isAlive->setAccessible(true);
		if ($async) {
			$this->assertNotEmpty($thread->getEventLoop());
			$this->assertNotEmpty($thread->getPid());
			$this->assertNotEmpty($thread->getParentPid());
			$this->assertNotEmpty($thread->getChildPid());
			$this->assertTrue($thread->getIsForked());
			$this->assertTrue($isAlive->invoke($thread));
		} else {
			$this->assertEmpty($thread->getEventLoop());
			$this->assertEmpty($thread->getPid());
			$this->assertEmpty($thread->getParentPid());
			$this->assertEmpty($thread->getChildPid());
			$this->assertFalse($thread->getIsForked());
			$this->assertFalse($isAlive->invoke($thread));
		}

		$this->assertSame(1, $thread->onLoadHookCalls);
		$this->assertSame(
			$async ? 0 : 1,
			$thread->onForkHookCalls
		);
		$this->assertSame(0, $thread->onShutdownHookCalls);
		$this->assertSame(0, $thread->onCleanupHookCalls);
		$thread->wait()->run()->wait();
		$this->assertTrue(
			$thread->getSuccess(),
			'Job failure - #' . $thread->getLastErrorCode()
			. ' ' . $thread->getLastErrorMsg()
		);
		$this->assertSame(array(1, 1, 0, 0), $thread->getResult());
		$thread->cleanup();
		$this->assertSame(1, $thread->onLoadHookCalls);
		$this->assertSame(
			$async ? 0 : 1,
			$thread->onForkHookCalls
		);
		$this->assertSame(0, $thread->onShutdownHookCalls);
		$this->assertSame(1, $thread->onCleanupHookCalls);
	}

	/**
	 * Thread, random errors
	 *
	 * @param bool $debug
	 */
	function processThreadErrorable($debug = false)
	{
		$num = 10;

		$async = Thread::$useForks;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread errorable test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = new TestThreadErrorable(
			null, null, $debug
		);

		$value = $i = $j = 0;
		$max   = (int)($num*2);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		while ($num > $i && $j <= $max) {
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

		$this->assertSame($num, $i, 'All jobs must be done');
		if ($async) {
			$this->assertTrue($j > $num);
		} else {
			$this->assertSame($num, $j);
		}

		if ($async) {
			$this->assertTrue($thread->getFailedJobs() > 0);
		}
		$this->assertSame(
			$thread->getStartedJobs(),
			$thread->getSuccessfulJobs() + $thread->getFailedJobs()
		);

		$thread->cleanup();
	}

	/**
	 * Thread, events
	 *
	 * @param bool $debug
	 * @param bool $bigData
	 *
	 * @throws \Exception
	 */
	function processThreadEvent($debug = false, $bigData = false)
	{
		$events = 11;
		$num    = 3;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread events test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = new TestThreadEvents(null, null, $debug);

		$arg  = mt_rand(19, 976);
		if ($bigData) {
			$arg = str_repeat($arg, 100000);
		}
		$last = 0;
		$testCase = $this;
		$cb = function($event, $e_data, $e_arg) use ($arg, $testCase, &$last) {
			$testCase->assertSame($arg, $e_arg);
			$testCase->assertSame(TestThreadEvents::EV_PROCESS, $event);
			$testCase->assertEquals($last++, $e_data);
		};
		$thread->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		$signalNotChecked = true;
		for ($i = 0; $i < $num; $i++) {
			$last = 0;
			if ($signalNotChecked) {
				$signalNotChecked = false;
				$thread->run($events)
					   ->sendSignalToChild(SIGWINCH)
					   ->wait();
			} else {
				$thread->run($events)->wait();
			}
			$this->assertTrue(
				$thread->getSuccess(),
				'Job failure - #' . $thread->getLastErrorCode()
				. ' ' . $thread->getLastErrorMsg()
			);
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

		$async = Thread::$useForks;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread arguments mapping test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = new TestThreadArgumentsMapping(
			null, null, $debug
		);

		// You can override preforkWait property
		// to TRUE to not wait thread at first time manually
		$thread->wait();

		for ($i = 0; $i < $num; $i++) {
			$a = mt_rand(99, 999);
			$b = mt_rand(59, 999);
			$c = mt_rand(13, 999);
			$thread->run($a, $b, $c)->wait();
			$this->assertSame(
				array($a, $b, $c),
				$thread->getResult()
			);
		}

		$thread->cleanup();
	}

	/**
	 * Closure thread
	 *
	 * @param bool $debug
	 */
	function processThreadClosure($debug = false)
	{
		$async = Thread::$useForks;
		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Closure thread test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}


		// ----
		$expected = mt_rand(999, 99999);
		$thread = SimpleThread::create(function() use ($expected) {
			return $expected;
		}, null, $debug);
		$result = $thread->run()->wait()->getResult();
		$this->assertSame($expected, $result);


		// ----
		$result = $thread->run()->wait()->getResult();
		$this->assertSame($expected, $result);
		$thread->cleanup();


		// ----
		$expected = mt_rand(999, 99999);
		$thread = SimpleThread::create(function($arg) {
			return $arg;
		}, null, $debug);
		$result = $thread->run($expected)->wait()->getResult();
		$this->assertSame($expected, $result);


		// ----
		$expected = mt_rand(999, 99999);
		$result = $thread->run($expected)->wait()->getResult();
		$this->assertSame($expected, $result);
		$thread->cleanup();
	}


	/**
	 * Pool
	 *
	 * @param bool $debug
	 * @param bool $bigResult
	 * @param bool $withChild
	 * @param bool $oneTask
	 *
	 * @throws Exception
	 */
	function processPool($debug = false, $bigResult = false, $withChild = false, $oneTask = false)
	{
		$threads       = 2;
		$targetThreads = $threads+2;
		$num           = $targetThreads*5;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread pool test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}


		if ($oneTask) {
			$thread = 'TestThreadOneTask';
		} else {
			$thread = $withChild
					? 'TestThreadWithChilds'
					: 'TestThreadReturnFirstArgument';
		}
		$thread = __NAMESPACE__ . '\\' . $thread;

		$name = 'example';
		$pool = new ThreadPool($thread, $threads, null, $name, $debug);

		$this->assertNotEmpty($pool->getId());
		$this->assertSame($name, $pool->getPoolName());
		$this->assertEmpty($pool->getThreadProcessName());
		$this->assertSame($thread, $pool->getThreadClassName());

		if (Thread::$useForks) {
			$this->assertSame($threads, $pool->getThreadsCount());
			$this->assertSame($threads, $pool->getMaxThreads());

			// We can not set number of threads lower than
			// number of already created threads
			$pool->setMaxThreads($threads-1);
			$this->assertSame($threads, $pool->getThreadsCount());
			$this->assertSame($threads, $pool->getMaxThreads());

			// But we can set more
			$pool->setMaxThreads($targetThreads);
			$this->assertSame($targetThreads, $pool->getThreadsCount());
			$this->assertSame($targetThreads, $pool->getMaxThreads());
		} else {
			$this->assertSame(1, $pool->getThreadsCount());
			$this->assertSame(1, $pool->getMaxThreads());

			$pool->setMaxThreads(8);
			$this->assertSame(1, $pool->getThreadsCount());
			$this->assertSame(1, $pool->getMaxThreads());

			$pool->setMaxThreads(0);
			$this->assertSame(1, $pool->getThreadsCount());
			$this->assertSame(1, $pool->getMaxThreads());
		}

		$jobs = array();

		$i = 0;
		$left = $num;
		$maxI = (int)ceil($num * 1.5);
		$worked = array();
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$arg = mt_rand(1, 999);
				if ($bigResult) {
					$arg = str_repeat($arg, 100000);
				}
				$threadId = $pool->run($arg);
				$this->assertNotEmpty($threadId);
				$this->assertTrue(
					!isset($jobs[$threadId]),
					"Thread #$threadId is not failed correctly"
				);
				$jobs[$threadId]   = $arg;
				$worked[$threadId] = true;
				$left--;
			}
			$results = $pool->wait($failed);
			$this->assertEmpty($failed, 'Failed results: ' . print_r($failed, true));
			if ($results) {
				foreach ($results as $threadId => $res) {
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertEquals($jobs[$threadId], $res, "Thread #$threadId");
					unset($jobs[$threadId]);
					$num--;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertSame(0, $num, 'All jobs must be done');

		$this->assertSame(
			$pool->getThreadsCount(), count($worked),
			'Worked threads count is not equals to real threads count'
		);

		$state     = $pool->getThreadsState();
		$statistic = $pool->getThreadsStatistic();
		if (Thread::$useForks) {
			$this->assertSame($targetThreads, $pool->getThreadsCount());
			$this->assertSame($targetThreads, count($state));
			$this->assertSame($targetThreads, count($statistic));
		} else {
			$this->assertSame(1, $pool->getThreadsCount());
			$this->assertSame(1, count($state));
			$this->assertSame(1, count($statistic));
		}
		foreach ($state as $s) {
			$this->assertSame('WAIT', $s);
		}

		$pool->cleanup();
		$this->assertSame(0, $pool->getThreadsCount());
		$this->assertEmpty($pool->getThreads());
		$this->assertFalse($pool->hasWaiting());
		$this->assertEmpty($pool->getThreadsState());
	}

	/**
	 * Pool 2
	 *
	 * @param bool $debug
	 *
	 * @throws Exception
	 */
	function processPool2($debug = false)
	{
		$async = Thread::$useForks;

		if ($debug) {
			echo '-------------------------', PHP_EOL,
			"Additional thread pool test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-------------------------', PHP_EOL;
		}


		// ReturnAllArguments
		$threads = 2;
		$thread  = __NAMESPACE__ . '\TestThreadReturnAllArguments';
		$pName = 'ReturnAllArguments';
		$pool = new ThreadPool(
			$thread, $threads, $pName, null, $debug
		);

		$this->assertSame($pName, $pool->getThreadProcessName());

		$state = $pool->getThreadsState();
		$this->assertSame($async ? $threads : 1, count($state));
		foreach ($state as $s) {
			$this->assertTrue('INIT' === $s || 'WAIT' === $s);
		}

		if ($async) {
			$catched = 0;
			try {
				$this->assertFalse($pool->run());
			} catch (Exception $e) {
				$catched++;
			}
			try {
				$this->assertFalse($pool->run('example'));
			} catch (Exception $e) {
				$catched++;
			}
			$this->assertSame(2, $catched);
		}

		$data = array(
			array(),
			array(1),
			array(1, 2),
			array(1, 2, 3),
			array(1, 2, 3, 4),
			array(1, 2, 3, 4, 5),
			array(null),
			array(null, null),
		);

		$worked = $jobs = array();
		$i      = 0;
		$left   = $num = count($data);
		$maxI   = ceil($num * 1.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$args     = array_shift($data);
				$threadId = call_user_func_array(array($pool, 'run'), $args);

				$this->assertNotEmpty($threadId);
				$this->assertTrue(
					!isset($jobs[$threadId]),
					"Thread #$threadId is not failed correctly"
				);

				$jobs[$threadId]   = $args;
				$worked[$threadId] = true;
				$left--;
			}
			$results = $pool->wait($failed);
			$this->assertEmpty($failed, 'Failed results: ' . print_r($failed, true));
			if ($results) {
				foreach ($results as $threadId => $res) {
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertSame($jobs[$threadId], $res, "Thread #$threadId");
					unset($jobs[$threadId]);
					$num--;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);
		$this->assertSame(0, $num, 'All jobs must be done');
		$pool->cleanup();


		// TestThreadDoNothing
		$threads = 2;
		$thread  = __NAMESPACE__ . '\TestThreadDoNothing';
		$pool = new ThreadPool(
			$thread, $threads, null, null, $debug
		);

		$i    = 0;
		$left = $num = 1;
		$maxI = 6;
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$threadId = $pool->run();
				$jobs[$threadId]   = null;
				$worked[$threadId] = true;
				$left--;
			}
			$results = $pool->wait($failed);
			$this->assertEmpty($failed, 'Failed results: ' . print_r($failed, true));
			if ($results) {
				foreach ($results as $threadId => $res) {
					$this->assertTrue(
						array_key_exists($threadId, $jobs), "Thread #$threadId"
					);
					$this->assertSame($jobs[$threadId], $res, "Thread #$threadId");
					unset($jobs[$threadId]);
					$num--;
					break 2;
				}
			}
			$i++;
		} while ($i < $maxI);
		$this->assertSame(0, $num, 'All jobs must be done');
		$pool->cleanup();
	}

	/**
	 * Pool, events
	 *
	 * @param bool $debug
	 * @param bool $bigData
	 *
	 * @throws Exception
	 */
	function processPoolEvent($debug = false, $bigData = false)
	{
		$events  = 3;
		$num     = 12;
		$threads = 3;

		$async = Thread::$useForks;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Thread pool events test: ",  ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$pool = new ThreadPool(
			__NAMESPACE__ . '\TestThreadEvents',
			$threads, null, null, $debug
		);

		$arg = mt_rand(12, 987);
		if ($bigData) {
			$arg = str_repeat($arg, 100000);
		}
		$jobs = $worked = array();
		$test = $this;
		$cb = function($event, $threadId, $e_data, $e_arg) use ($arg, $test, &$jobs) {
			/** @var $test TestCase */
			$test->assertSame($arg, $e_arg);
			$test->assertSame(TestThreadEvents::EV_PROCESS, $event);
			if (!isset($jobs[$threadId])) {
				$jobs[$threadId] = 0;
			}
			$test->assertEquals($jobs[$threadId]++, $e_data);
		};
		$pool->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);


		$i = 0;
		$left = $num;
		$maxI = ceil($num * 1.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$threadId = $pool->run($events);
				$this->assertNotEmpty($threadId);
				$worked[$threadId] = true;
				$left--;
			}
			$results = $pool->wait($failed);
			$this->assertEmpty($failed, 'Failed results: ' . print_r($failed, true));
			if ($results) {
				foreach ($results as $threadId => $res) {
					$num--;
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertSame($events, $jobs[$threadId]);
					unset($jobs[$threadId]);
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertSame(0, $num, 'All jobs must be done');

		$pool->cleanup();
	}

	/**
	 * Pool, errors
	 *
	 * @param bool $debug
	 *
	 * @throws Exception
	 */
	function processPoolErrorable($debug = false)
	{
		$num     = 12;
		$threads = 4;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Errorable thread pool test: ",  (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$pool = new ThreadPool(
			__NAMESPACE__ . '\TestThreadErrorable',
			$threads, null, null, $debug
		);

		$jobs = array();

		$i = $j = 0;
		$left = $num;
		$maxI = ceil($num * 2.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$arg = mt_rand(1000000, 200000000);
				$threadId = $pool->run($arg, $j);

				$this->assertTrue(
					!isset($jobs[$threadId]),
					"Thread #$threadId is not failed correctly"
				);

				$jobs[$threadId] = $arg;
				$left--;
				$j++;
			}
			if ($results = $pool->wait($failed)) {
				foreach ($results as $threadId => $res) {
					$num--;
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertEquals($jobs[$threadId], $res, "Thread #$threadId");
					unset($jobs[$threadId]);
				}
			}
			if ($failed) {
				foreach ($failed as $threadId => $errArray) {
					list($errCode, $errMsg) = $errArray;
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertNotEmpty($errCode, 'Error code needed');
					$this->assertTrue(is_int($errCode), 'Error code needed');
					$this->assertNotEmpty($errMsg, 'Error message needed');
					unset($jobs[$threadId]);
					$left++;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertSame(0, $num, 'All jobs must be done');

		$pool->cleanup();
		$this->assertSame(0, $pool->getThreadsCount());
		$this->assertEmpty($pool->getThreads());
		$this->assertFalse($pool->hasWaiting());
	}

	/**
	 * Pool, external errors
	 *
	 * @param bool $debug
	 *
	 * @throws Exception
	 */
	function processPoolErrorableExternal($debug = false)
	{
		$num     = 9;
		$threads = 3;

		if ($debug) {
			echo '------------------------------------------', PHP_EOL,
			"Thread pool test with external stop: ", (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'------------------------------------------', PHP_EOL;
		}

		$pool = new ThreadPool(
			__NAMESPACE__ . '\TestThreadErrorableExternal',
			$threads, null, null, $debug
		);

		$argDebug = $debug && true;
		$debugRef = new ReflectionMethod($pool, 'debug');
		$debugRef->setAccessible(true);
		$debugCb = function ($msg) use ($argDebug, $debugRef, $pool) {
			$argDebug && $debugRef->invoke($pool, $msg);
		};

		$jobs = array();

		$i = $j = 0;
		$left = $num;
		$maxI = ceil($num * 2.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$arg = str_repeat(
					mt_rand(100, 999),
					$argDebug ? 3 : 10000
				);

				$threadId = $pool->run($arg, $j);

				$this->assertNotEmpty($threadId);
				$debugCb(
					"TEST: Thread #$threadId; Job argument on start [$arg]"
				);

				// Stop child
				if (1 & $j) {
					$threads = $pool->getThreads();
					$threads[$threadId]->sendSignalToChild(
						($j % 4) > 1 ? SIGTERM : SIGKILL
					);
					$debugCb(
						"TEST: Thread #$threadId stopped"
					);
					unset($threads);
				}

				$this->assertTrue(
					!isset($jobs[$threadId]),
					"Thread #$threadId is not failed correctly"
				);

				$jobs[$threadId] = $arg;
				$left--;
				$j++;
			}
			if ($results = $pool->wait($failed)) {
				foreach ($results as $threadId => $res) {
					$debugCb(
						"TEST: Thread #$threadId; Job result [$res]"
					);
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertEquals($jobs[$threadId], $res, "Thread #$threadId");
					unset($jobs[$threadId]);
					$num--;
				}
			}
			if ($failed) {
				foreach ($failed as $threadId => $errArray) {
					list($errCode, $errMsg) = $errArray;
					$debugCb(
						"TEST: Thread #$threadId; Job fail [#$errCode - $errMsg]"
					);
					$this->assertTrue(isset($jobs[$threadId]), "Thread #$threadId");
					$this->assertNotEmpty($errCode, 'Error code needed');
					$this->assertTrue(is_int($errCode), 'Error code needed');
					$this->assertNotEmpty($errMsg, 'Error message needed');
					unset($jobs[$threadId]);
					$left++;
				}
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$this->assertSame(0, $num, 'All jobs must be done');

		$pool->cleanup();
	}

	/**
	 * Exception in events triggering
	 *
	 * @param bool $debug
	 */
	function processExceptionInEventListener($debug = false)
	{
		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Exception in event listener test: ", (Thread::$useForks ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = new TestThreadEvents(null, null, $debug);

		$testCase = $this;
		$thread->bind(
			TestThreadEvents::EV_PROCESS,
			function($event, $e_data, $e_arg) use ($testCase) {
				$testCase->assertSame(null, $e_arg);
				$testCase->assertSame(
					TestThreadEvents::EV_PROCESS,
					$event
				);
				$testCase->assertEquals(0, $e_data);

				throw new InvalidArgumentException(
					'Example Message'
				);
			}
		);

		try {
			$thread->wait()->run(1)->wait();
		} catch (InvalidArgumentException $e) {
			$catched = true;
			$this->assertTrue($e instanceof InvalidArgumentException);
			$this->assertContains(
				'Example Message', $e->getMessage()
			);
		}
		$this->assertFalse(empty($catched));

		$thread->cleanup();
	}


	/**
	 * Two different threads
	 */
	function processTwoDifferentThreads($debug = false)
	{
		$async = Thread::$useForks;

		if ($debug) {
			echo '-----------------------', PHP_EOL,
			"Different threads test ", ($async ? 'Async' : 'Sync'), PHP_EOL,
			'-----------------------', PHP_EOL;
		}


		$arg1 = 123456789;
		$arg2 = 987654321;

		$thread1 = new TestThreadReturnFirstArgument(
			null, null, $debug
		);
		$thread2 = new TestThreadReturnFirstArgument(
			null, null, $debug
		);


		$thread1->wait();
		$this->assertEmpty(
			$thread1->getLastErrorCode(),
			$thread1->getLastErrorMsg()
		);
		$async && $this->assertTrue($thread1->getIsForked());
		$thread1->run($arg1);

		$thread2->wait();
		$this->assertEmpty(
			$thread2->getLastErrorCode(),
			$thread2->getLastErrorMsg()
		);
		$async && $this->assertTrue($thread2->getIsForked());
		$thread2->run($arg2);

		$res2 = $thread2->wait()->getResult();
		$res1 = $thread1->wait()->getResult();
		$this->assertTrue($thread1->getSuccess());
		$this->assertSame($arg1, $res1);
		$this->assertTrue($thread2->getSuccess());
		$this->assertSame($arg2, $res2);


		$thread2->run($arg1);
		$thread1->run($arg2);

		$res1 = $thread1->wait()->getResult();
		$res2 = $thread2->wait()->getResult();
		$this->assertSame($arg2, $res1);
		$this->assertSame($arg1, $res2);


		$thread1->run($arg1);
		$thread2->run($arg2);

		$res1 = $thread1->wait()->getResult();
		$res2 = $thread2->wait()->getResult();
		$this->assertSame($arg1, $res1);
		$this->assertSame($arg2, $res2);


		$thread2->cleanup();

		$res1 = $thread1->run($arg2)->wait()->getResult();
		$this->assertSame($arg2, $res1);


		$thread1->cleanup();
	}


	/**
	 * Event callback for tests
	 */
	function processEventCallback($event, $e_data, $e_arg)
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
	protected $timeoutMasterResultWait = 2;

	/**
	 * {@inheritdoc}
	 */
	protected $argumentsMapping = true;

	/**
	 * {@inheritdoc}
	 */
	function process($a, $b, $c)
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
	protected $timeoutMasterInitWait = 1;

	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterResultWait = 10;

	/**
	 * {@inheritdoc}
	 */
	protected $timeoutWorkerJobWait = 15;


	/**
	 * {@inheritdoc}
	 */
	function process()
	{
		$param = $this->getParam(0);
		if ($this->debug && is_scalar($param) && strlen($param) < 50) {
			$this->debug("TEST: Job argument in worker [$param]");
		}
		return $param;
	}
}

/**
 * Test thread
 */
class TestThreadOneTask extends TestThreadReturnFirstArgument
{
	/**
	 * {@inheritdoc}
	 */
	protected $multitask = false;

	/**
	 * {@inheritdoc}
	 */
	protected $prefork = false;
}

/**
 * Test thread
 */
class TestThreadDoNothing extends TestThreadReturnFirstArgument
{
	/**
	 * {@inheritdoc}
	 */
	function process() {}
}

/**
 * Test thread
 */
class TestThreadHooks extends TestThreadReturnFirstArgument
{
	/** @var int */
	public $onLoadHookCalls = 0;

	/** @var int */
	public $onForkHookCalls = 0;

	/** @var int */
	public $onShutdownHookCalls = 0;

	/** @var int */
	public $onCleanupHookCalls = 0;


	/**
	 * {@inheritdoc}
	 */
	protected function onLoad()
	{
		$this->onLoadHookCalls++;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function onFork()
	{
		$this->onForkHookCalls++;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function onShutdown()
	{
		$this->onShutdownHookCalls++;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function onCleanup()
	{
		$this->onCleanupHookCalls++;
	}


	/**
	 * {@inheritdoc}
	 */
	function process()
	{
		return array(
			$this->onLoadHookCalls,
			$this->onForkHookCalls,
			$this->onShutdownHookCalls,
			$this->onCleanupHookCalls
		);
	}
}

/**
 * Test thread
 */
class TestThreadReturnAllArguments extends TestThreadReturnFirstArgument
{
	/**
	 * {@inheritdoc}
	 */
	function process()
	{
		return $this->getParams();
	}
}

/**
 * Test thread
 */
class TestThreadErrorable extends TestThreadReturnFirstArgument
{
	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterResultWait = 0.1;

	/**
	 * {@inheritdoc}
	 */
	function process()
	{
		if (1 & ($arg = (int)$this->getParam(1))) {
			// Emulate terminating
			$signo = ($arg % 4) > 1 ? SIGTERM : SIGKILL;
			if ($this->debug) {
				$signame = Base::getSignalName($signo);
				$this->debug("TEST: Emulate terminating with $signame ($signo)");
			}
			$this->sendSignalToChild($signo);
		}
		return parent::process();
	}
}

/**
 * Test thread
 */
class TestThreadErrorableExternal extends TestThreadReturnFirstArgument
{
	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterResultWait = 0.1;
}

/**
 * Test thread
 */
class TestThreadWithChilds extends TestThreadReturnFirstArgument
{
	/**
	 * Enable prefork waiting to test it
	 */
	protected $preforkWait = true;

	/**
	 * {@inheritdoc}
	 */
	function process()
	{
		/** @noinspection PhpUnusedLocalVariableInspection */
		$res = `echo 1`;
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadEvents extends TestThreadReturnFirstArgument
{
	const EV_PROCESS = 'process';

	/**
	 * {@inheritdoc}
	 */
	protected $timeoutMasterResultWait = 2;

	/**
	 * {@inheritdoc}
	 */
	protected $prefork = false;

	/**
	 * {@inheritdoc}
	 */
	function process()
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
		usleep(20000);
	}

	/**
	 * {@inheritdoc}
	 */
	function process() {}
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
	function process()
	{
		// To certainly meet the timeout
		usleep(20000);
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
	protected $listenMasterSignals = false;

	/**
	 * {@inheritdoc}
	 */
	function process() {}
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
	function process()
	{
		// Create new thread. Set debug flag from this thread
		$child = new TestParentCheckTimeoutChild(
			null, null, $this->debug, array(
				'eventLoop' => new EventBase()
			)
		);

		// Send child PID to parent via event
		$childPid = $child->wait()->getChildPid();
		$this->trigger(self::EV_PID, $childPid);

		// Emulate terminating with small timeout
		$this->sendSignalToChild(SIGKILL);
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
	function process() {}

	/**
	 * {@inheritdoc}
	 */
	protected function registerEventSignals($allSignals = true) {}
}

/**
 * Test thread
 */
class TestSignalsHandling extends TestThreadReturnFirstArgument
{
	/**
	 * @var int
	 */
	protected $catchedSignals = 0;

	/**
	 * @var int
	 */
	public static $catchedSignalsInParent = 0;


	/**
	 * {@inheritdoc}
	 */
	function process()
	{
		return $this->sendSignalToParent(SIGUSR2)->catchedSignals;
	}


	/**
	 * Child SIGUSR1 handler
	 */
	protected function sigUsr1()
	{
		$this->catchedSignals++;
	}

	/**
	 * Parent SIGUSR2 handler
	 */
	protected static function mSigUsr2()
	{
		self::$catchedSignalsInParent++;
	}
}

#endregion
