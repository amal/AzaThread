CThread
====

https://github.com/amal/CThread


CThread is a simple and powerful threads component for PHP.

Libreary uses libevent and socket pairs for inter-process communication. Also it can use five variants of data transfering between processes and automatically selects the best of them


Main features and possibilites:

* Uses [forks](http://php.net/pcntl-fork);
* Synchronous compatibility mode if there no required extensions;
* Reuse of the child processes;
* Full exchange of data between processes. Sending arguments, receiving results;
* Transfer of events between the "thread" and the parent process;
* Working with a thread pool with preservation of multiple use, passing arguments and receiving results;
* Errors handling;
* Timeouts for work, child process waiting, initialization;
* Maximum performance;

CThread is written for Anizoptera CMF by Amal Samally (amal.samally at gmail.com)


Requirements
------------

* PHP 5.3.0 (or later);
* [libevent](http://php.net/libevent);
* [pcntl](http://php.net/pcntl);
* [posix](http://php.net/posix);


Examples
--------

Simply run processing asynchronously

```php
class ExampleThread extends CThread
{
	protected function process()
	{
		// Some work here
	}
}

$thread = new ExampleThread();
$thread->run();
```

Send argument and receive result of processing

```php
class ExampleThread extends CThread
{
	protected function process()
	{
		return $this->getParam(0);
	}
}

$thread = new ExampleThread();
$thread->run(123)->wait();
$result = $thread->getResult();
```

Triggering events from thread

```php
class ExampleThread extends CThread
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

$thread = new ExampleThread();
$thread->run($events)->wait();
```

Use pool with 8 threads

```php
$pool = new CThreadPool('ExampleThread', $threads);

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

Another examples you can see in the example file (*example.php*) and unit test (*tests/Test_Thread.php*).


Links
-----

CThread — многопоточность для PHP с блэкджеком
http://habrahabr.ru/blogs/php/134501/
