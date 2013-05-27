Usage examples
==============

* [↰ back to the documentation contents](0.Index.md)
* [↰ back to the AzaThread overview](../../../../)



---



1. [Simply run processing asynchronously](#example-1---simply-run-processing-asynchronously)
2. [Send argument and receive result of processing](#example-2---send-argument-and-receive-result-of-processing)
3. [Triggering events from thread](#example-3---triggering-events-from-thread)
4. [Use pool with 8 threads](#example-4---use-pool-with-8-threads)
5. [Thread closure](#example-5---thread-closure)


#### Example #1 - Simply run processing asynchronously

```php
class ExampleThread extends Thread
{
	function process()
	{
		// Some work here
	}
}

$thread = new ExampleThread();
$thread->wait()->run();
```


#### Example #2 - Send argument and receive result of processing

```php
class ExampleThread extends Thread
{
	function process()
	{
		return $this->getParam(0);
	}
}

$thread = new ExampleThread();
$result = $thread->wait()->run(123)->wait()->getResult();

// After work it's strongly recommended to clean
// resources obviously to avoid leaks
$thread->cleanup();
```


#### Example #3 - Triggering events from thread

```php
class ExampleThread extends Thread
{
	const EV_PROCESS = 'process';

	function process()
	{
		$events = $this->getParam(0);
		for ($i = 0; $i < $events; $i++) {
			$event_data = $i;
			$this->trigger(self::EV_PROCESS, $event_data);
		}
	}
}

$thread = new ExampleThread();

// Additional argument.
$additionalArgument = 123;

$thread->bind(ExampleThread::EV_PROCESS, function($event_name, $event_data, $additional_arg)  {
	// Event handling
}, $additionalArgument);

$events = 10; // number of events to trigger

// You can override preforkWait property
// to TRUE to not wait thread at first time manually.
// In this case, waiting for initialization will happen
// automatically, but more efficient not to do it.
$thread->wait();

$thread->run($events)->wait();

// After work it's strongly recommended to clean
// resources obviously to avoid leaks
$thread->cleanup();
```


#### Example #4 - Use pool with 8 threads

```php
$threads = 8  // Number of threads
$pool = new ThreadPool('ExampleThread', $threads);

$num = 25;    // Number of tasks
$left = $num; // Remaining number of tasks

do {
	// If we still have tasks to perform
	// And the pool has waiting threads
	while ($left > 0 && $pool->hasWaiting()) {
		// You get thread id after start
		$threadId = $pool->run();
		$left--;
	}
	if ($results = $pool->wait($failed)) {
		foreach ($results as $threadId => $result) {
			// Successfully completed task
			// Result can be identified
			// with thread id ($threadId)
			$num--;
		}
	}
	if ($failed) {
		// Error handling.
		// The work is completed unsuccessfully
		// if the child process has died at run time or
		// work timeout exceeded.
		foreach ($failed as $threadId => $err) {
			list($errorCode, $errorMessage) = $err;
			$left++;
		}
	}
} while ($num > 0);

// Terminating all child processes. Cleanup of resources used by the pool.
$pool->cleanup();

// After work it's strongly recommended to clean
// resources obviously to avoid leaks
$pool->cleanup();
```


#### Example #5 - Thread closure

You can use simple threads crating with closures. Such threads are not preforked by default and not multitask too. You can change it via the second argument of `SimpleThread::create`.

```php
$result = SimpleThread::create(function($arg) {
	return $arg;
})->run(123)->wait()->getResult();
```



---



Other examples can be seen in the file [examples/example.php](../examples/example.php) and in unit test [Tests/ThreadTest.php](../Tests/ThreadTest.php).

You can also run the performance tests, choose the number of threads and pick the best settings for your system configuration by using [examples/speed_test.php](../examples/speed_test.php).
