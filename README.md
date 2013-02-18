AzaThread (old name - CThread)
====

AzaThread is a simple and powerful threads emulation component for PHP.

https://github.com/amal/AzaThread

Features:

* Uses [forks](http://php.net/pcntl-fork) to operate asynchronously;
* Synchronous compatibility mode if there are no required extensions;
* Reuse of the child processes;
* Full exchange of data between processes. Sending arguments, receiving results;
* Uses [libevent](http://php.net/libevent) with socket pairs for inter-process communication;
* Supports two variants of data serialization for transfer (igbinary, native php serialization);
* Transfer of events between the "thread" and the parent process;
* Working with a thread pool with preservation of multiple use, passing arguments and receiving results;
* Errors handling;
* Timeouts for work, child process waiting, initialization;
* Maximum performance;

AzaThread is a part of Anizoptera CMF, written by [Amal Samally](http://azagroup.ru#amal) (amal.samally at gmail.com)

Licensed under the MIT License.


Requirements
------------

* PHP 5.3.3 (or later);
* [libevent](http://php.net/libevent);
* [pcntl](http://php.net/pcntl);
* [posix](http://php.net/posix);


Examples
--------

Simply run processing asynchronously

```php
class ExampleThread extends Thread
{
	protected function process()
	{
		// Some work here
	}
}

$thread = new ExampleThread();
$thread->wait()->run();
```

Send argument and receive result of processing

```php
class ExampleThread extends Thread
{
	protected function process()
	{
		return $this->getParam(0);
	}
}

$thread = new ExampleThread();
$result = $thread->wait()->run(123)->wait()->getResult();
```

Triggering events from thread

```php
class ExampleThread extends Thread
{
	const EV_PROCESS = 'process';

	protected function process()
	{
		$events = $this->getParam(0);
		for ($i = 0; $i < $events; $i++) {
			$event_data = $i;
			$this->trigger(self::EV_PROCESS, $event_data);
		}
	}
}

// Additional argument.
$additionalArgument = 123;

$thread->bind(ExampleThread::EV_PROCESS, function($event_name, $event_data, $additional_arg)  {
	// Event handling
}, $additionalArgument);

$events = 10; // number of events to trigger

// You can override preforkWait property
// to TRUE to not wait thread at first time manually
$thread->wait();

$thread = new ExampleThread();
$thread->run($events)->wait();
```

Use pool with 8 threads

```php
$threads = 8  // Number of threads
$pool = new ThreadPool('ExampleThread', $threads);

$num = 25;    // Number of tasks
$left = $num; // Remaining number of tasks

do {
	// If the pool  has waiting threads
	// And we still have tasks to perform
	while ($pool->hasWaiting() && $left > 0) {
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
		foreach ($failed as $threadId) {
			$left++;
		}
	}
} while ($num > 0);

// Terminating all child processes. Cleanup of resources used by the pool.
$pool->cleanup();
```

Other examples can be seen in the example file ([example.php](example.php)) and unit test ([tests/ThreadTest.php](tests/ThreadTest.php)).

You can also run the performance tests, choose the number of threads and pick the best settings for your configuration by using a [test.php](https://github.com/amal/AzaThread/blob/master/test.php).


Links
-----

[AzaThread — многопоточность для PHP с блэкджеком](http://habrahabr.ru/blogs/php/134501/)

AzaThread uses some components from Anizopera CMF: AzaAutoloader, AzaSocket, [AzaLibEvent](https://github.com/amal/AzaLibEvent)
