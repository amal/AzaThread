<?php

/**
 * Examples of using the library CThread
 *
 * @project Anizoptera CMF
 * @package system.thread
 */

require __DIR__ . '/inc.thread.php';



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


// Checks
if (!CThread::$useForks) {
	echo PHP_EOL . "You do not have the minimum system requirements to work in async mode!!!";
	if (!CShell::$hasForkSupport) {
		echo PHP_EOL . "You don't have pcntl or posix extensions installed or either not CLI SAPI environment!";
	}
	if (!CShell::$hasLibevent) {
		echo PHP_EOL . "You don't have libevent extension installed!";
	}
	echo PHP_EOL;
}


// ----------------------------------------------
echo PHP_EOL . 'Simple example with one thread' . PHP_EOL;

$num = 10; // Number of tasks
$thread = new TestThreadReturnFirstArgument();
for ($i = 0; $i < $num; $i++) {
	$value = $i;
	// Run task and wait for the result
	if ($thread->run($value)->wait()->getSuccess()) {
		// Success
		$result = $thread->getResult();
		echo 'result: ' . $result . PHP_EOL;
	} else {
		// Error handling here
		// processing is not successful if thread dies
		// when worked or working timeout exceeded
		echo 'error' . PHP_EOL;
	}
}
$thread->cleanup();



// ----------------------------------------------
echo PHP_EOL . 'Simple example with thread events' . PHP_EOL;

$events = 10;	// Number of events
$num    = 3;	// Number of tasks

$thread = new TestThreadEvents();

$cb = function($event_name, $event_data)  {
	echo "event: $event_name : $event_data" . PHP_EOL;
};
$thread->bind(TestThreadEvents::EV_PROCESS, $cb);

for ($i = 0; $i < $num; $i++) {
	$thread->run($events)->wait();
	echo 'task ended' . PHP_EOL;
}
$thread->cleanup();



// ----------------------------------------------
$threads = 4;

echo PHP_EOL . "Simple example with pool of threads ($threads)" . PHP_EOL;

$pool = new CThreadPool('TestThreadReturnFirstArgument', $threads);

$num  = 25;		// Number of tasks
$left = $num;	// Number of remaining tasks
do {
	while ($left > 0 && $pool->hasWaiting()) {
		if (!$threadId = $pool->run($left)) {
			throw new Exception('Pool slots error');
		}
		$left--;
	}
	if ($results = $pool->wait($failed)) {
		foreach ($results as $threadId => $result) {
			$num--;
			echo 'result: ' . $result . PHP_EOL;
		}
	}
	if ($failed) {
		// Error handling here
		// processing is not successful if thread dies
		// when worked or working timeout exceeded
		foreach ($failed as $threadId) {
			echo 'error' . PHP_EOL;
			$left++;
		}
	}
} while ($num > 0);
$pool->cleanup();



// ----------------------------------------------
$threads  = 8;
$jobs     = range(1, 30);
$jobs_num = count($jobs);

echo PHP_EOL . "Example with pool of threads ($threads) and pool of jobs ($jobs_num)" . PHP_EOL;

$pool = new CThreadPool('TestThreadReturnFirstArgument', $threads);

$num     = $jobs_num; // Number of tasks
$left    = $jobs_num; // Number of remaining tasks
$started = array();
do {
	while ($left > 0 && $pool->hasWaiting()) {
		$task = array_shift($jobs);
		if (!$threadId = $pool->run($task)) {
			throw new Exception('Pool slots error');
		}
		$started[$threadId] = $task;
		$left--;
	}
	if ($results = $pool->wait($failed)) {
		foreach ($results as $threadId => $result) {
			unset($started[$threadId]);
			$num--;
			echo 'result: ' . $result . PHP_EOL;
		}
	}
	if ($failed) {
		// Error handling here
		// processing is not successful if thread dies
		// when worked or working timeout exceeded
		foreach ($failed as $threadId) {
			$jobs[] = $started[$threadId];
			echo 'error: ' . $started[$threadId] . PHP_EOL;
			unset($started[$threadId]);
			$left++;
		}
	}
} while ($num > 0);
$pool->cleanup();
