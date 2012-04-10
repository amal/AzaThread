AzaLibEvent
====

https://github.com/amal/AzaLibEvent

AzaLibEvent is a simple, powerful and easy to use OOP wrapper for the PHP LibEvent.

Main features and possibilites:

* Convenient, fully documented and tested in production API
* Timers and intervals system (look at EventBase::timerAdd)
* Special base reinitializing for forks (look at EventBase::reinitialize)
* Error handling with exceptions
* Automatic resources cleanup

AzaLibEvent is a part of Anizoptera CMF, written by [Amal Samally](http://azagroup.ru/contacts#amal) (amal.samally at gmail.com)

Licensed under the MIT License.


Requirements
------------

* PHP 5.2.0 (or later);
* [libevent](http://php.net/libevent);


Examples
--------

WARNING!
To run the examples you need some sort of class autoloader (PSR-0 supported).
Or, of course, you can include all files manually.


Example #1 - polling STDIN using basic API (see [example1.php](https://github.com/amal/AzaLibEvent/blob/master/example1.php))

```php
/**
 * Callback function to be called when the matching event occurs
 *
 * @param resource $buf    File descriptor
 * @param int      $events What kind of events occurred. See EV_* constants
 * @param array    $args   Event arguments - array(Event $e, mixed $arg)
 */
function print_line($fd, $events, $args)
{
    static $max_requests = 0;
    $max_requests++;

	/**
	 * @var $e    Event
	 * @var $base EventBase
	 */
	list($e, $base) = $args;

    // exit loop after 10 writes
    if ($max_requests == 10) {
		$base->loopExit();
    }

    // print the line
    echo fgets($fd);
}

// Create base
$base = new EventBase;

// Setup and enable event
$ev = new Event();
$ev->set(STDIN, EV_READ|EV_PERSIST, 'print_line', $base)
	->setBase($base)
	->add();

// Start event loop
$base->loop();
```


Example #2 - polling STDIN using buffered event API (see [example2.php](https://github.com/amal/AzaLibEvent/blob/master/example2.php))

```php
/**
 * Callback to invoke where there is data to read
 *
 * @param resource $buf  File descriptor
 * @param array    $args Event arguments - array(EventBuffer $e, mixed $arg)
 */
function print_line($buf, $args)
{
    static $max_requests;
    $max_requests++;

	/**
	 * @var $e    EventBuffer
	 * @var $base EventBase
	 */
	list($e, $base) = $args;

    // exit loop after 10 writes
    if ($max_requests == 10) {
		$base->loopExit();
    }

    // print the line
    echo $e->read(4096);
}

/**
 * Callback to invoke where there is an error on the descriptor.
 * function(resource $buf, int $what, array $args(EventBuffer $e, mixed $arg))
 *
 * @param resource $buf  File descriptor
 * @param int      $what What kind of error occurred. See EventBuffer::E_* constants
 * @param array    $args Event arguments - array(EventBuffer $e, mixed $arg)
 */
function error_func($buf, $what, $args) {}


// I use Base::getEventBase() to operate always with the
// same instance, but you can simply use "new EventBase()"

// Get event base
$base = Base::getEventBase();

// Create buffered event
$ev = new EventBuffer(STDIN, 'print_line', null, 'error_func', $base);
$ev->setBase($base)->enable(EV_READ);

// Start loop
$base->loop();
```


Links
-----

AzaLibEvent is used in the [AzaThread](https://github.com/amal/AzaThread) libreary (Simple and powerful threads emulation for PHP)
