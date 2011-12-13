<?php

/**
 * Threads inc file
 *
 * @project Anizoptera CMF
 * @package system.thread
 */


/** Console line interface flag */
define('IS_CLI', PHP_SAPI === 'cli');

/** Windows flag */
define('IS_WIN', !strncasecmp(PHP_OS, 'win', 3));


require_once __DIR__ . '/lib/CShell.php';
require_once __DIR__ . '/lib/CSocket.php';
require_once __DIR__ . '/lib/CThread.php';
require_once __DIR__ . '/lib/CThreadPool.php';
require_once __DIR__ . '/lib/CLibEventBase.php';
require_once __DIR__ . '/lib/CLibEventBasic.php';
require_once __DIR__ . '/lib/CLibEvent.php';
require_once __DIR__ . '/lib/CLibEventBuffer.php';

require_once __DIR__ . '/lib/AzaException.php';

// IPC classes are not needed in most cases
require_once __DIR__ . '/lib/CIpcQueue.php';
require_once __DIR__ . '/lib/CIpcSemaphore.php';
require_once __DIR__ . '/lib/CIpcSharedMemory.php';
require_once __DIR__ . '/lib/CIpcShmop.php';
