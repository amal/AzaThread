<?php

namespace Aza\Components\Thread;
use Aza\Components\Socket\Socket;

require __DIR__ . '/../vendor/autoload.php';


/**
 * Performance testing
 *
 * @project Anizoptera CMF
 * @package system.thread
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */

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

$data    = true;  // Transmit data
$work    = true;  // Do some work
$tests   = 6;     // Number of iterations in tests
$jobsT   = 10000; // Number of jobs to do in one thread
$jobsP   = 20000; // Number of jobs to do in pools
$poolMin = 2;     // Minimum threads number in pool to test

// Disable to use sync mode
// Thread::$useForks = false;

// Manually specify the type of data transfer between threads
// Thread::$ipcDataMode = Thread::IPC_IGBINARY;




#############
# Test
#############


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
 * Prints message
 *
 * @parma string $msg     Message
 * @parma int    $newline Newlines count
 */
$print = function($msg = '', $newline = 1) {
	echo $msg . str_repeat(PHP_EOL, (int)$newline);
	@ob_flush(); @flush();
};
$line = str_repeat('-', 80);


if (!Thread::$useForks) {
	$print(
		'ERROR: You need Forks, LibEvent, PCNTL and POSIX'
		.' support with CLI sapi to fully test Threads'
	);
	return;
}


/** @var $threadClass Thread */
$threadClass = $work ? 'TestThreadWork' : ($data ? 'TestThreadReturn' : 'TestThreadNothing');
$threadClass = __NAMESPACE__ . '\\' . $threadClass;

$arg1 = (object)array('foobarbaz' => 1234567890, 12.9876543);
$arg2 = 123/16;



// Test one thread
$print($line);
$print("One thread test; Jobs: $jobsT; Iterations: $tests");
$print($line, 2);

/** @var $thread Thread */
$thread = new $threadClass;
$thread->wait();
$res = array();

for ($j = 0; $j < $tests; ++$j) {
	$start = microtime(true);
	for ($i = 0; $i < $jobsT; $i++) {
		$data ? $thread->run($arg1, $arg2) : $thread->run();
		$thread->wait()->getResult();
	}
	$end = bcsub(microtime(true), $start, 99);
	$oneJob = bcdiv($end, $jobsT, 99);
	$res[]  = bcdiv(1, $oneJob, 99);
	$jps = bcdiv(1, $oneJob);
	$print("Iteration: ".($j+1)."; Jobs per second: $jps");
}
$sum = 0;
foreach ($res as $r) {
	$sum = bcadd($sum, $r, 99);
}
$averageOneThreadJps = bcdiv($sum, $tests);
$print("Average jobs per second: $averageOneThreadJps", 3);

$thread->cleanup();



// Test pools
$bestJps = 0;
$bestThreadsNum = 0;
$regression = 0;
$lastJps = 0;
$print($line);
$print("Pool test; Jobs: $jobsP; Iterations: $tests");
$print($line, 2);

$threads = $poolMin;
$pool = new ThreadPool($threadClass, $threads);
do {
	$print("Threads: $threads");
	$print($line);

	$res = array();
	for ($j = 0; $j < $tests; ++$j) {
		$start = microtime(true);

		$num = $jobsP;
		$i = 0;
		$maxI = ceil($jobsP * 1.5);
		do {
			while ($pool->hasWaiting()) {
				$data ? $pool->run($arg1, $arg2) : $pool->run();
			}
			if ($results = $pool->wait()) {
				$num -= count($results);
			}
			$i++;
		} while ($num > 0 && $i < $maxI);

		$end    = bcsub(microtime(true), $start, 99);
		$oneJob = bcdiv($end, $jobsP, 99);
		$res[]  = bcdiv(1, $oneJob, 99);
		$jps    = bcdiv(1, $oneJob);
		$print("Iteration: ".($j+1)."; Jobs per second: $jps");
	}
	$sum = 0;
	foreach ($res as $r) {
		$sum = bcadd($sum, $r, 99);
	}
	$avJps = bcdiv($sum, $tests);
	$print("Average jobs per second: $avJps", 2);

	if ($bestJps < $avJps) {
		$bestJps = $avJps;
		$bestThreadsNum = $threads;
	}
	if ($avJps < $bestJps || $avJps < $lastJps) {
		$regression++;
	}
	if ($regression >= 3) {
		break;
	}
	$lastJps = $avJps;

	// Increase number of threads
	$threads++;
	$pool->setMaxThreads($threads);
} while (true);

$pool->cleanup();


$print('', 3);
$print("Best number of threads for your system: {$bestThreadsNum} ({$bestJps} jobs per second)");

$boost = bcdiv($bestJps, bcdiv($averageOneThreadJps, 100, 99), 2);
$print("Performance boost in relation to a single thread: {$boost}%");
