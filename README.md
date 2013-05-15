AzaThread
=========

Anizoptera CMF simple and powerful threads emulation component for PHP (based on forks).
Old name - CThread.

https://github.com/Anizoptera/AzaThread

[![Build Status][TravisImage]][Travis]


Table of Contents
-----------------

1. [Introduction](#introduction)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Examples](#examples)
   * [Simply run processing asynchronously](#example-1---simply-run-processing-asynchronously)
   * [Send argument and receive result of processing](#example-2---send-argument-and-receive-result-of-processing)
   * [Triggering events from thread](#example-3---triggering-events-from-thread)
   * [Use pool with 8 threads](#example-4---use-pool-with-8-threads)
   * [Thread closure](#example-5---thread-closure)
5. [Tests](#tests)
6. [Credits](#credits)
7. [License](#license)
8. [Links](#links)


Introduction
------------

**Features:**

* Uses [forks](http://php.net/pcntl-fork) to operate asynchronously;
* Supports synchronous compatibility mode if there are no required extensions;
* Reuse of the child processes;
* Full exchange of data between processes. Sending arguments, receiving results;
* Uses [libevent][] with socket pairs for efficient inter-process communication;
* Supports two variants of data serialization for transfer (igbinary, native php serialization);
* Transfer of events between the "thread" and the parent process;
* Working with a thread pool with preservation of multiple use, passing arguments and receiving results;
* Errors handling;
* Timeouts for work, child process waiting, initialization;
* Maximum performance;


Requirements
------------

* PHP 5.3.3 (or later);
* Unix system;
* [libevent][];
* [pcntl](http://php.net/pcntl);
* [posix](http://php.net/posix);
* [AzaLibevent](https://github.com/Anizoptera/AzaLibEvent) - will be installed automatically with composer;
* [AzaSocket](https://github.com/Anizoptera/AzaSocket) - will be installed automatically with composer;
* [AzaCliBase](https://github.com/Anizoptera/AzaCliBase) - will be installed automatically with composer;

NOTE: You can use synchronous compatibility mode even without requirements (or on windows, for example).


Installation
------------

The recommended way to install AzaThread is [through composer](http://getcomposer.org).
You can see [package information on Packagist][ComposerPackage].

```JSON
{
	"require": {
		"aza/thread": "~1.0"
	}
}
```


Examples
--------

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

#### Example #4 - Use pool with 8 threads

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

#### Example #5 - Thread closure

You can use simple threads crating with closures. Such threads are not preforked by default and not multitask too. You can change it via the second argument of `SimpleThread::create`.

```php
$result = SimpleThread::create(function($arg) {
	return $arg;
})->run(123)->wait()->getResult();
```


Other examples can be seen in the file [examples/example.php](examples/example.php) and in unit test [Tests/ThreadTest.php](Tests/ThreadTest.php).

You can also run the performance tests, choose the number of threads and pick the best settings for your system configuration by using [examples/speed_test.php](examples/speed_test.php).


Tests
-----

Tests are in the `Tests` folder.
To run them, you need PHPUnit.
Example:

    $ phpunit --configuration phpunit.xml.dist


Credits
-------

AzaThread is a part of [Anizoptera CMF][], written by [Amal Samally][] (amal.samally at gmail.com) and [AzaGroup][] team.


License
-------

Released under the [MIT](LICENSE.md) license.


Links
-----

* [Composer package][ComposerPackage]
* [Last build on the Travis CI][Travis]
* [Project profile on the Ohloh](https://www.ohloh.net/p/AzaThread)
* (RU) [AzaThread — многопоточность для PHP с блэкджеком](http://habrahabr.ru/blogs/php/134501/)
* Other Anizoptera CMF components on the [GitHub][Anizoptera CMF] / [Packagist](https://packagist.org/packages/aza)
* (RU) [AzaGroup team blog][AzaGroup]



[libevent]: http://php.net/libevent

[Anizoptera CMF]:  https://github.com/Anizoptera
[Amal Samally]:    http://azagroup.ru/about/#amal
[AzaGroup]:        http://azagroup.ru/
[ComposerPackage]: https://packagist.org/packages/aza/thread
[TravisImage]:     https://secure.travis-ci.org/Anizoptera/AzaThread.png?branch=master
[Travis]:          http://travis-ci.org/Anizoptera/AzaThread
