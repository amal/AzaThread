Примеры использования
=====================

* [↰ назад к оглавлению документации](0.Index.md)
* [↰ назад к общей информации об AzaThread](../../../../#azathread)



---



1. [Простой запуск асинхронных вычислений](#example-1------)
2. [Запуск с аргументом и получением результата](#example-2--------)
3. [Генерирование событий из потока](#example-3------)
4. [Использование пула с 8 потоками](#example-4------8-)
5. [Поток с замыканием](#example-5-----)


#### Example #1 - Простой запуск асинхронных вычислений

```php
class ExampleThread extends Thread
{
	function process()
	{
		// Код, выполняемый асинхронно
	}
}

$thread = new ExampleThread();
$thread->wait()->run();
```


#### Example #2 - Запуск с аргументом и получением результата

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


#### Example #3 - Генерирование событий из потока

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

// Дополнительный аргумент - будет передаваться вместе с аргументами события.
$additionalArgument = 123;

$thread->bind(ExampleThread::EV_PROCESS, function($event_name, $event_data, $additional_arg)  {
	// Event handling
}, $additionalArgument);

$events = 10; // сколько событий сгенерировать

// Можно переопределить параметр "preforkWait" в TRUE,
// чтобы не вызывать ожидание вручную в первый раз.
// В этом случае ожидание инициализации будет происходить автоматически,
// но эффективнее этого не делать.
$thread->wait();

$thread->run($events)->wait();
```


#### Example #4 - Использование пула с 8 потоками

```php
$threads = 8  // Число потоков
$pool = new ThreadPool('ExampleThread', $threads);

$num = 25;    // Число задач
$left = $num; // Сколько задач осталось выполнить

do {
	// Если есть задачи для выполнения
	// и в пуле есть свободные потоки
	while ($left > 0 && $pool->hasWaiting()) {
		// После старта задачи вы получаете ID
		// потока, который начал ее выполнять
		$threadId = $pool->run();
		$left--;
	}
	if ($results = $pool->wait($failed)) {
		foreach ($results as $threadId => $result) {
			// Задача успешно выполнена
			// Результат может быть идентифицирован
			// с помощью ID потока ($threadId)
			$num--;
		}
	}
	if ($failed) {
		// Обработка ошибок.
		// Задачу не удалось выполнить по причине смерти
		// дочернего процесса или по истечении таймаута
		// на выполнение задачи.
		foreach ($failed as $threadId => $err) {
			list($errorCode, $errorMessage) = $err;
			$left++;
		}
	}
} while ($num > 0);

// Завершение дочерних процессов. Очистка ресурсов, использованных в пуле.
$pool->cleanup();
```


#### Example #5 - Поток с замыканием

Вы можете использовать упрощенное создание потоков с помощью замыканий. Такие потоки, по умолчанию, не создают сразу дочерний процесс и не мультизадачны (дочерний процесс умирает после каждой задачи). Это может быть изменено с помощью второго аргумента в `SimpleThread::create`.

```php
$result = SimpleThread::create(function($arg) {
	return $arg;
})->run(123)->wait()->getResult();
```



---



Остальные примеры можно найти в файле [examples/example.php](../examples/example.php) и в юнит тестах [Tests/ThreadTest.php](../Tests/ThreadTest.php).

Также вы можете выполнить тесты производительности, выбрать подходящее число потоков и лучшие настройки для вашей системы с использованием [examples/speed_test.php](../examples/speed_test.php).
