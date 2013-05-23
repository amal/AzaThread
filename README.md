AzaThread
=========

Simple and powerful threads emulation component for PHP (based on forks).
Old name - CThread.

https://github.com/Anizoptera/AzaThread

[![Build Status][TravisImage]][Travis]


Table of Contents
-----------------

1. [Introduction](#introduction)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Documentation and examples](#documentation-and-examples)
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
* Transfer of events between the "thread" and the parent process;
* Working with a thread pool with preservation of multiple use, passing arguments and receiving results;
* Uses [libevent][] with socket pairs for efficient inter-process communication;
* Supports two variants of data serialization for transfer (igbinary, native php serialization);
* Errors handling;
* Timeouts for work, child process waiting, initialization;
* Maximum performance and customization;


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


Documentation and examples
--------------------------

See [full documentation](docs/en/0.Index.md) and [main examples](docs/en/Examples.md). Documentation is available in several languages​​!

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

* [Mail list](mailto:azathread@googlegroups.com) (via [Google Group](https://groups.google.com/forum/#!forum/azathread))
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
