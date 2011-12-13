<?php

/**
 * Performance testing
 *
 * @project Anizoptera CMF
 * @package system.thread
 */

require __DIR__ . '/inc.thread.php';



/**
 * Threads speed test results in jobs per second
 *
 * =================================================
 *
 * Intel Core i3 540 3.07 Ghz (Ubuntu 11.04) results
 *
 * IPC (empty jobs):
 *
 * 20219	- 1 thread (Sync)
 * 19900	- 1 thread (Sync, Data transfer)
 *
 * 4460		- 1 thread (Async)
 * 3082		- 1 thread (Async, Data transfer)
 *
 * 5224		- 2 threads (Async)
 * 3894		- 2 threads (Async, Data transfer)
 *
 * 5718		- 4 threads (Async)
 * 4295		- 4 threads (Async, Data transfer)
 *
 * 5806		- 8 threads (Async)
 * 4371		- 8 threads (Async, Data transfer)
 *
 * 5842		- 10 threads (Async)
 * 4303		- 10 threads (Async, Data transfer)
 *
 * 5890		- 12 threads (Async)
 * 4333		- 12 threads (Async, Data transfer)
 *
 * 6018		- 16 threads (Async)
 * 4234		- 16 threads (Async, Data transfer)
 *
 * Working jobs:
 *
 * 553	- 1 thread (Sync)
 * 330	- 1 thread (Async)
 * 580	- 2 threads (Async)
 * 1015	- 4 threads (Async)
 * 1040	- 8 threads (Async)
 * 1027	- 10 threads (Async)
 * 970	- 12 threads (Async)
 * 958	- 16 threads (Async)
 *
 * =================================================
 *
 * Intel Core i7 2600K 3.40 Ghz (Ubuntu 11.04 on VMware virtual machine) results
 *
 * IPC (empty jobs):
 *
 * 26394	- 1 thread (Sync)
 * 25032	- 1 thread (Sync, Data transfer)
 *
 * 4928		- 1 thread (Async)
 * 4164		- 1 thread (Async, Data transfer)
 *
 * 7210		- 2 threads (Async)
 * 5910		- 2 threads (Async, Data transfer)
 *
 * 7129		- 4 threads (Async)
 * 6261		- 4 threads (Async, Data transfer)
 *
 * 7633		- 8 threads (Async)
 * 6630		- 8 threads (Async, Data transfer)
 *
 * 7810		- 10 threads (Async)
 * 6715		- 10 threads (Async, Data transfer)
 *
 * 7641		- 12 threads (Async)
 * 6540		- 12 threads (Async, Data transfer)
 *
 * 7587		- 16 threads (Async)
 * 6514		- 16 threads (Async, Data transfer)
 *
 * Working jobs:
 *
 * 763	- 1 thread (Sync)
 * 669	- 1 thread (Async)
 * 1254	- 2 threads (Async)
 * 2188	- 4 threads (Async)
 * 2618	- 8 threads (Async)
 * 2719	- 10 threads (Async)
 * 2739	- 12 threads (Async)
 * 2904	- 16 threads (Async)
 * 2830	- 18 threads (Async)
 * 2730	- 20 threads (Async)
 *
 * =================================================
 */



#############
# Settings
#############


// Disable to use sync mode
//CThread::$useForks = false;

// Manually specify the type of data transfer between threads
//CThread::$ipcDataMode = CThread::IPC_IGBINARY;

$data    = 0;		// Whether to transfer data (do not work with "work")
$work    = 0;		// Whether to do some work in threads
$tests   = 10;		// Number of tests to do
$jobs    = 10000;	// Number of jobs in test
$threads = 8;		// Number of threads




#############
# Test
#############

/**
 * Test thread
 */
class TestThreadNothing extends CThread
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
class TestThreadWork extends CThread
{
	/**
	 * Main processing.
	 *
	 * @return mixed
	 */
	protected function process()
	{
		$r = null;
		for ($i = 0; $i < 1000; $i++) {
			$r = mt_rand(0, PHP_INT_MAX) * mt_rand(0, PHP_INT_MAX);
		}
		return $r;
	}
}



var_dump("Threads: $threads; Tests: $tests; Starting tests...");

/** @var $thread CThread */
$thread = $work ? 'TestThreadWork' : ($data ? 'TestThreadReturn' : 'TestThreadNothing');
$pool = new CThreadPool($thread, $threads);

$arg1 = (object)array('foobarbaz' => 1234567890, 12.9876543);
$arg2 = 123/16;

$res = array();
for ($j = 0; $j < $tests; ++$j) {
	$start = microtime(true);

	$num = $jobs;
	$i = 0;
	$maxI = ceil($jobs * 1.5);
	do {
		while ($pool->hasWaiting()) {
			if ($data) {
				$pool->run($arg1, $arg2);
			} else {
				$pool->run();
			}
		}
		if ($results = $pool->wait()) {
			$num -= count($results);
		}
		$i++;
	} while ($num > 0 && $i < $maxI);

	$end    = bcsub(microtime(true), $start, 99);
	$oneJob = bcdiv($end, $jobs, 99);
	$res[]  = bcdiv(1, $oneJob, 99);
	$jps    = bcdiv(1, $oneJob);
	var_dump("Threads: $threads; Jobs: $jobs; Iteration: $j; Jobs per second: $jps");
}
$sum = 0;
foreach ($res as $r) {
	$sum = bcadd($sum, $r, 99);
}
$avJps = bcdiv($sum, $tests);

var_dump("Threads: $threads; Tests: $tests; Average jobs per second: $avJps");

$pool->cleanup();
