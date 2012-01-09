<?php

require_once __DIR__ . '/../inc.thread.php';

/**
 * Testing thread system
 *
 * @project Anizoptera CMF
 * @package system.cli
 */
class Test_Thread extends PHPUnit_Framework_TestCase
{
	/**
	 * @var bool
	 */
	protected static $defForks;


	/**
	 * This method is called before the first test of this test class is run.
	 */
	public static function setUpBeforeClass()
	{
		self::$defForks = CThread::$useForks;
	}

	/**
	 * This method is called after the last test of this test class is run.
	 */
	public static function tearDownAfterClass()
	{
		CThread::$useForks = self::$defForks;
		gc_collect_cycles();
	}


	/**
	 * Tests threads in synchronous mode
	 */
	function testSync()
	{
		$debug = false;
		CThread::$useForks = false;


		// Sync thread
		$this->processThread($debug);

		// Sync events
		$this->processThreadEvent($debug);

		// Sync pool
		$this->processPool($debug);


		CThread::$useForks = self::$defForks;
	}

	/**
	 * Tests threads in asynchronous mode
	 */
	function testAsync()
	{
		if (!CThread::$useForks) {
			$this->markTestIncomplete(
				'You need Forks, LibEvent, PCNTL and POSIX support with CLI sapi to fully test Threads'
			);
			return;
		}

		$ipc_modes   = array(
			CThread::IPC_IGBINARY   => 'igbinary_serialize',
			CThread::IPC_SERIALIZE  => false,
			CThread::IPC_SYSV_QUEUE => 'msg_get_queue',
			CThread::IPC_SYSV_SHM   => 'shm_attach',
			CThread::IPC_SHMOP      => 'shmop_open',
		);
		$defDataMode = CThread::$ipcDataMode;

		$debug = false;
		CThread::$useForks = true;


		foreach ($ipc_modes as $mode => $check) {
			if ($check && !function_exists($check)) {
				continue;
			}

			CThread::$ipcDataMode = $mode;

			// Async thread
			$this->processThread($debug);

			// Async events
			$this->processThreadEvent($debug);

			// Async errorable thread
			$this->processThreadErrorable($debug);

			// Async pool
			$this->processPool($debug);

			// Async pool
			$this->processPoolEvent($debug);

			// Async errorable pool
			$this->processPoolErrorable($debug);
		}


		CThread::$useForks    = self::$defForks;
		CThread::$ipcDataMode = $defDataMode;
	}


	/**
	 * Thread
	 *
	 * @param bool $debug
	 */
	function processThread($debug)
	{
		$num = 10;

		if ($debug) {
			echo '-----------------------' , PHP_EOL,
				 'Thread test: ' , (CThread::$useForks ? 'Async' : 'Sync') , PHP_EOL,
				 '-----------------------', PHP_EOL;
		}

		$thread = new TestThreadReturnFirstArgument($debug);

		for ($i = 0; $i < $num; $i++) {
			$value = $i;
			$thread->run($value)->wait();
			$state = $thread->getState();
			$this->assertEquals(CThread::STATE_WAIT, $state);
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
			echo '-----------------------' , PHP_EOL,
			'Thread errorable test: ' , (CThread::$useForks ? 'Async' : 'Sync') , PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$thread = new TestThreadReturnArgErrors($debug);

		$i = 0;
		$value = $i;
		$j = 0;
		while ($num > $i) {
			$j++;
			$thread->run($value, $j)->wait();
			$state = $thread->getState();
			$this->assertEquals(CThread::STATE_WAIT, $state);
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
			echo '-----------------------' , PHP_EOL,
				 'Thread events test: ' , (CThread::$useForks ? 'Async' : 'Sync') , PHP_EOL,
				 '-----------------------', PHP_EOL;
		}

		$thread = new TestThreadEvents($debug);

		$test = $this;
		$arg = mt_rand(12, 987);
		$last = 0;
		$cb = function($event, $e_data, $e_arg) use ($arg, $test, &$last) {
			/** @var $test Test_Thread */
			$test->assertEquals($arg, $e_arg);
			$test->assertEquals(TestThreadEvents::EV_PROCESS, $event);
			$test->assertEquals($last++, $e_data);
		};
		$thread->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);

		for ($i = 0; $i < $num; $i++) {
			$last = 0;
			$thread->run($events)->wait();
			$state = $thread->getState();
			$this->assertEquals(CThread::STATE_WAIT, $state);
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
	 */
	function processPool($debug)
	{
		$num     = 100;
		$threads = 4;

		if ($debug) {
			echo '-----------------------' , PHP_EOL,
				 'Thread pool test: ' , (CThread::$useForks ? 'Async' : 'Sync') , PHP_EOL,
				 '-----------------------', PHP_EOL;
		}

		$pool = new CThreadPool('TestThreadReturnFirstArgument', $threads, null, $debug);

		$jobs = array();

		$i    = 0;
		$left = $num;
		$maxI = ceil($num * 1.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$arg = mt_rand(1000000, 20000000);
				if (!$threadId = $pool->run($arg)) {
					throw new Exception('Pool slots error');
				}
				$this->assertTrue(!isset($jobs[$threadId]));
				$jobs[$threadId] = $arg;
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

		$pool->cleanup();
	}

	/**
	 * Pool, events
	 *
	 * @param bool $debug
	 */
	function processPoolEvent($debug)
	{
		$events  = 5;
		$num     = 15;
		$threads = 3;

		if ($debug) {
			echo '-----------------------' , PHP_EOL,
			'Thread pool events test: ' , (CThread::$useForks ? 'Async' : 'Sync') , PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$pool = new CThreadPool('TestThreadEvents', $threads, null, $debug);

		$test = $this;
		$arg  = mt_rand(12, 987);
		$jobs = array();
		$cb = function($event, $threadId, $e_data, $e_arg) use ($arg, $test, &$jobs) {
			/** @var $test Test_Thread */
			$test->assertEquals($arg, $e_arg);
			$test->assertEquals(TestThreadEvents::EV_PROCESS, $event);
			$test->assertTrue(isset($jobs[$threadId]));
			$test->assertEquals($jobs[$threadId]++, $e_data);
		};
		$pool->bind(TestThreadEvents::EV_PROCESS, $cb, $arg);


		$i    = 0;
		$left = $num;
		$maxI = ceil($num * 1.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				if (!$threadId = $pool->run($events)) {
					throw new Exception('Pool slots error');
				}
				$this->assertTrue(!isset($jobs[$threadId]));
				$jobs[$threadId] = 0;
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

		$pool->cleanup();
	}

	/**
	 * Pool, errors
	 *
	 * @param bool $debug
	 */
	function processPoolErrorable($debug)
	{
		$num     = 50;
		$threads = 4;

		if ($debug) {
			echo '-----------------------' , PHP_EOL,
			'Thread pool test: ' , (CThread::$useForks ? 'Async' : 'Sync') , PHP_EOL,
			'-----------------------', PHP_EOL;
		}

		$pool = new CThreadPool('TestThreadReturnArgErrors', $threads, null, $debug);

		$jobs = array();

		$i    = 0;
		$j    = 0;
		$left = $num;
		$maxI = ceil($num * 2.5);
		do {
			while ($left > 0 && $pool->hasWaiting()) {
				$arg = mt_rand(1000000, 20000000);
				if (!$threadId = $pool->run($arg, $j)) {
					throw new Exception('Pool slots error');
				}
				$this->assertTrue(!isset($jobs[$threadId]));
				$jobs[$threadId] = $arg;
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

		$pool->cleanup();
	}
}



/**
 * Test thread
 */
class TestThreadReturnFirstArgument extends CThread
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
class TestThreadReturnArgErrors extends CThread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		if (1 & (int)$this->getParam(1)) {
			posix_kill($this->child_pid, SIGKILL);
			exit;
		}
		return $this->getParam(0);
	}
}

/**
 * Test thread
 */
class TestThreadReturn extends CThread
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
class TestThreadEvents extends CThread
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
