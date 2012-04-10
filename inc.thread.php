<?php

use Aza\Components\Autoloader\UniversalClassLoader;

/**
 * Example threads inc file
 *
 * @package system.AzaSocket
 * @author  Amal Samally <amal.samally at gmail.com>
 * @license MIT
 */


/** Console line interface flag */
define('IS_CLI', PHP_SAPI === 'cli');

/** Windows flag */
define('IS_WIN', !strncasecmp(PHP_OS, 'win', 3));


// Autoloader
require_once __DIR__ . '/Components/Autoloader/UniversalClassLoader.php';
$autoloader = new UniversalClassLoader();
$autoloader->registerNamespaces(array(
	'Aza' => __DIR__ . DIRECTORY_SEPARATOR,
));
$autoloader->register();
