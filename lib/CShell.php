<?php

/**
 * Console functionality
 *
 * @project Anizoptera CMF
 * @package system.cli
 * @version $Id: CShell.php 2808 2011-11-25 15:47:41Z samally $
 */
abstract class CShell
{
	// Exit codes
	// @link http://www.freebsd.org/cgi/man.cgi?query=sysexits&sektion=3
	// @link http://www.hiteksoftware.com/knowledge/articles/049.htm

	/**
	 * The successful exit
	 */
	const EX_OK = 0;

	/**
	 * Error occured
	 */
	const EX_ERROR = 1;

	/**
	 * Action locked
	 */
	const EX_LOCKED = 2;

	/**
	 * The command was used incorrectly, e.g., with the
	 * wrong number of arguments, a bad flag, a bad syntax
	 * in a parameter, or whatever.
	 */
	const EX_USAGE = 64;

	/**
	 * The input data was incorrect in some way.
	 * This should only be used for user's data and not system
	 * files.
	 */
	const EX_DATAERR = 65;

	/**
	 * An input file (not a system file) did not exist or
	 * was not readable. This could also include errors
	 * like ``No message'' to a mailer (if it cared to
	 * catch it).
	 */
	const EX_NOINPUT = 66;

	/**
	 * The user specified did not exist. 
	 * This might be used for mail addresses or remote logins.
	 */
	const EX_NOUSER = 67;

	/**
	 * The host specified did not exist. This is used in
	 * mail addresses or network requests.
	 */
	const EX_NOHOST = 68;

	/**
	 * A service is unavailable.  This can occur if a sup-
	 * port program or file does not exist.  This can also
	 * be used as a catchall message when something you
	 * wanted to do does not work, but you do not know
	 * why.
	 */
	const EX_UNAVAILABLE = 69;

	/**
	 * An internal software error has been detected.  This
	 * should be limited to non-operating system related
	 * errors as possible.
	 */
	const EX_SOFTWARE = 70;

	/**
	 * An operating system error has been detected.  This
	 * is intended to be used for such things as ``cannot
	 * fork'', ``cannot create pipe'', or the like.  It
	 * includes things like getuid returning a user that
	 * does not exist in the passwd file.
	 */
	const EX_OSERR = 71;

	/**
	 * Some system file (e.g., /etc/passwd, /var/run/utmp,
	 * etc.) does not exist, cannot be opened, or has some
	 * sort of error (e.g., syntax error).
	 */
	const EX_OSFILE = 72;

	/**
	 * A (user specified) output file cannot be created.
	 */
	const EX_CANTCREAT = 73;

	/**
	 * An error occurred while doing I/O on some file.
	 */
	const EX_IOERR = 74;

	/**
	 * Temporary failure, indicating something that is not
	 * really an error.  In sendmail, this means that a
	 * mailer (e.g.) could not create a connection, and
	 * the request should be reattempted later.
	 */
	const EX_TEMPFAIL = 75;

	/**
	 * The remote system returned something that was 'not
	 * possible' during a protocol exchange.
	 */
	const EX_PROTOCOL = 76;

	/**
	 * You did not have sufficient permission to perform
	 * the operation.  This is not intended for file sys-
	 * tem problems, which should use EX_NOINPUT or
	 * EX_CANTCREAT, but rather for higher level permis-
	 * sions.
	 */
	const EX_NOPERM = 77;

	/**
	 * Something was found in an unconfigured or miscon-
	 * figured state.
	 */
	const EX_CONFIG = 78;

	/**
	 * Process terminated (interrupted)
	 */
	const EX_TERM = 143;



	/**
	 * Whether forks supported
	 *
	 * @var bool
	 */
	public static $hasForkSupport;

	/**
	 * Whether Libevent supported
	 *
	 * @var bool
	 */
	public static $hasLibevent;

	/**
	 * Global libevent base
	 *
	 * @var CLibEventBase|null
	 */
	public static $eventBase;

	/**
	 * Whether current process is master
	 *
	 * @var bool
	 */
	public static $isMaster = true;

	/**
	 * Process signals
	 *
	 * SIG_DFL
	 * specifies the default action for the particular signal.
	 *
	 * SIG_IGN
	 * specifies that the signal should be ignored.
	 * Your program generally should not ignore signals that represent serious events
	 * or that are normally used to request termination. You cannot ignore the SIGKILL
	 * or SIGSTOP signals at all. You can ignore program error signals like SIGSEGV,
	 * but ignoring the error won't enable the program to continue executing meaningfully.
	 * Ignoring user requests such as SIGINT, SIGQUIT, and SIGTSTP is unfriendly.
	 *
	 * When you do not wish signals to be delivered during a certain part of the program,
	 * the thing to do is to block them, not ignore them.
	 */
	public static $signals = array(
		/*
		 * The SIGHUP ("hang-up") signal is used to report that the user's
		 * terminal is disconnected, perhaps because a network or telephone
		 * connection was broken. For more information about this.
		 * This signal is also used to report the termination of the controlling process
		 * on a terminal to jobs associated with that session; this termination effectively
		 * disconnects all processes in the session from the controlling terminal.
		 */
		SIGHUP    => 'SIGHUP',
		/*
		 * The SIGINT ("program interrupt") signal is sent when the user
		 * types the INTR character (normally ^C).
		 */
		SIGINT    => 'SIGINT',
		/*
		 * The SIGQUIT signal is similar to SIGINT, except that it's
		 * controlled by a different key--the QUIT character, usually
		 * C-\---and produces a core dump when it terminates the process,
		 * just like a program error signal. You can think of this as a
		 * program error condition "detected" by the user.
		 */
		SIGQUIT   => 'SIGQUIT',
		/*
		 * The name of this signal is derived from "illegal instruction";
		 * it usually means your program is trying to execute garbage or
		 * a privileged instruction. Since the C compiler generates only
		 * valid instructions, SIGILL typically indicates that the executable
		 * file is corrupted, or that you are trying to execute data. Some
		 * common ways of getting into the latter situation are by passing an
		 * invalid object where a pointer to a function was expected, or by
		 * writing past the end of an automatic array (or similar problems
		 * with pointers to automatic variables) and corrupting other data on
		 * the stack such as the return address of a stack frame.
		 * SIGILL can also be generated when the stack overflows, or when the
		 * system has trouble running the handler for a signal.
		 */
		SIGILL    => 'SIGILL',
		/*
		 * Generated by the machine's breakpoint instruction, and possibly other trap
		 * instructions. This signal is used by debuggers. Your program will probably
		 * only see SIGTRAP if it is somehow executing bad instructions.
		 */
		SIGTRAP   => 'SIGTRAP',
		/*
		 * This signal indicates an error detected by the program itself
		 */
		SIGABRT   => 'SIGABRT',
		/*
		 * Emulator trap; this results from certain unimplemented instructions
		 * which might be emulated in software, or the operating system's failure
		 * to properly emulate them.
		 */
		7         => 'SIGEMT',
		/*
		 * The SIGFPE signal reports a fatal arithmetic error.
		 * Although the name is derived from "floating-point exception",
		 * this signal actually covers all arithmetic errors, including division
		 * by zero and overflow. If a program stores integer data in a location
		 * which is then used in a floating-point operation, this often causes an
		 * "invalid operation" exception, because the processor cannot recognize the
		 * data as a floating-point number.
		 */
		SIGFPE    => 'SIGFPE',
		/*
		 * The SIGKILL signal is used to cause immediate program termination.
		 * It cannot be handled or ignored, and is therefore always fatal.
		 * It is also not possible to block this signal.
		 * This signal is usually generated only by explicit request.
		 * Since it cannot be handled, you should generate it only as a last resort,
		 * after first trying a less drastic method such as SIGINT or SIGTERM.
		 * If a process does not respond to any other termination signals,
		 * sending it a SIGKILL signal will almost always cause it to go away.
		 */
		SIGKILL   => 'SIGKILL',
		/*
		 * This signal is generated when an invalid pointer is dereferenced.
		 * Like SIGSEGV, this signal is typically the result of dereferencing
		 * an uninitialized pointer. The difference between the two is that SIGSEGV
		 * indicates an invalid access to valid memory, while SIGBUS indicates an
		 * access to an invalid address. In particular, SIGBUS signals often result
		 * from dereferencing a misaligned pointer, such as referring to a four-word integer
		 * at an address not divisible by four. (Each kind of computer has its own requirements
		 * for address alignment.)
		 * The name of this signal is an abbreviation for "bus error".
		 */
		SIGBUS    => 'SIGBUS',
		/*
		 * This signal is generated when a program tries to read or write outside
		 * the memory that is allocated for it, or to write memory that can only
		 * be read. (Actually, the signals only occur when the program goes far
		 * enough outside to be detected by the system's memory protection mechanism.)
		 * The name is an abbreviation for "segmentation violation".
		 * Common ways of getting a SIGSEGV condition include dereferencing a null
		 * or uninitialized pointer, or when you use a pointer to step through an array,
		 * but fail to check for the end of the array. It varies among systems whether
		 * dereferencing a null pointer generates SIGSEGV or SIGBUS.
		 */
		SIGSEGV   => 'SIGSEGV',
		/*
		 * Bad system call; that is to say, the instruction to trap to the operating system
		 * was executed, but the code number for the system call to perform was invalid.
		 */
		SIGSYS    => 'SIGSYS',
		/*
		 * Broken pipe. If you use pipes or FIFOs, you have to design your application
		 * so that one process opens the pipe for reading before another starts writing.
		 * If the reading process never starts, or terminates unexpectedly, writing to
		 * the pipe or FIFO raises a SIGPIPE signal. If SIGPIPE is blocked, handled or
		 * ignored, the offending call fails with EPIPE instead.
		 * Another cause of SIGPIPE is when you try to output to a socket that isn't
		 * connected.
		 */
		SIGPIPE   => 'SIGPIPE',
		/*
		 * This signal typically indicates expiration of a timer
		 * that measures real or clock time.
		 */
		SIGALRM   => 'SIGALRM',
		/*
		 * The SIGTERM signal is a generic signal used to cause program termination.
		 * Unlike SIGKILL, this signal can be blocked, handled, and ignored. It is the
		 * normal way to politely ask a program to terminate.
		 * The shell command kill generates SIGTERM by default.
		 */
		SIGTERM   => 'SIGTERM',
		/*
		 * This signal is sent when "urgent" or out-of-band data arrives on a socket
		 */
		SIGURG    => 'SIGURG',
		/*
		 * The SIGSTOP signal stops the process. It cannot be handled, ignored, or blocked.
		 */
		SIGSTOP   => 'SIGSTOP',
		/*
		 * The SIGTSTP signal is an interactive stop signal. Unlike SIGSTOP, this signal
		 * can be handled and ignored.
		 * Your program should handle this signal if you have a special need to leave files
		 * or system tables in a secure state when a process is stopped. For example, programs
		 * that turn off echoing should handle SIGTSTP so they can turn echoing back on before stopping.
		 * This signal is generated when the user types the SUSP character (normally ^Z).
		 */
		SIGTSTP   => 'SIGTSTP',
		/*
		 * You can send a SIGCONT signal to a process to make it continue.
		 * This signal is special--it always makes the process continue if it is stopped,
		 * before the signal is delivered. The default behavior is to do nothing else.
		 * You cannot block this signal. You can set a handler, but SIGCONT always makes
		 * the process continue regardless.
		 * Most programs have no reason to handle SIGCONT; they simply resume execution
		 * without realizing they were ever stopped. You can use a handler for SIGCONT
		 * to make a program do something special when it is stopped and continued--for example,
		 * to reprint a prompt when it is suspended while waiting for input.
		 */
		SIGCONT   => 'SIGCONT',
		/*
		 * This signal is sent to a parent process whenever one of its child processes
		 * terminates or stops.
		 * The default action for this signal is to ignore it. If you establish a handler
		 * for this signal while there are child processes that have terminated but not
		 * reported their status via wait or waitpid, whether your new handler applies
		 * to those processes or not depends on the particular operating system.
		 */
		SIGCHLD   => 'SIGCHLD',
		/*
		 * A process cannot read from the user's terminal while it is running as a
		 * background job. When any process in a background job tries to read from
		 * the terminal, all of the processes in the job are sent a SIGTTIN signal.
		 * The default action for this signal is to stop the process.
		 */
		SIGTTIN   => 'SIGTTIN',
		/*
		 * This is similar to SIGTTIN, but is generated when a process in a background
		 * job attempts to write to the terminal or set its modes. Again, the default
		 * action is to stop the process. SIGTTOU is only generated for an attempt
		 * to write to the terminal if the TOSTOP output mode is set;
		 */
		SIGTTOU   => 'SIGTTOU',
		/*
		 * This signal is sent when a file descriptor is ready to perform input or output.
		 * On most operating systems, terminals and sockets are the only kinds of files
		 * that can generate SIGIO; other kinds, including ordinary files, never generate
		 * SIGIO even if you ask them to.
		 * In the GNU system SIGIO will always be generated properly if you successfully
		 * set asynchronous mode with fcntl.
		 */
		SIGIO     => 'SIGIO',
		/*
		 * CPU time limit exceeded. This signal is generated when the process exceeds
		 * its soft resource limit on CPU time.
		 */
		SIGXCPU   => 'SIGXCPU',
		/*
		 * File size limit exceeded. This signal is generated when the process attempts
		 * to extend a file so it exceeds the process's soft resource limit on file size.
		 */
		SIGXFSZ   => 'SIGXFSZ',
		/*
		 * This signal typically indicates expiration of a timer that measures
		 * CPU time used by the current process. The name is an abbreviation
		 * for "virtual time alarm".
		 */
		SIGVTALRM => 'SIGVTALRM',
		/*
		 * This signal typically indicates expiration of a timer that measures
		 * both CPU time used by the current process, and CPU time expended on
		 * behalf of the process by the system. Such a timer is used to
		 * implement code profiling facilities, hence the name of this signal.
		 */
		SIGPROF   => 'SIGPROF',
		/*
		 * Information request. In 4.4 BSD and the GNU system, this signal is sent to all
		 * the processes in the foreground process group of the controlling terminal when
		 * the user types the STATUS character in canonical mode.;
		 * If the process is the leader of the process group, the default action is to
		 * print some status information about the system and what the process is doing.
		 * Otherwise the default is to do nothing.
		 */
		28        => 'SIGINFO',
		/*
		 * Window size change. This is generated on some systems (including GNU)
		 * when the terminal driver's record of the number of rows and columns on
		 * the screen is changed. The default action is to ignore it.
		 * If a program does full-screen display, it should handle SIGWINCH.
		 * When the signal arrives, it should fetch the new screen size and
		 * reformat its display accordingly.
		 */
		SIGWINCH  => 'SIGWINCH',
		/*
		 * The SIGUSR1 and SIGUSR2 signals are set aside for you to use any way you want.
		 * They're useful for simple interprocess communication, if you write a signal
		 * handler for them in the program that receives the signal.
		 * The default action is to terminate the process.
		 */
		SIGUSR1   => 'SIGUSR1',
		SIGUSR2   => 'SIGUSR2',
	);



	/**
	 * Returns name of the signal
	 *
	 * @param int  $signo
	 * @param bool $found If signal name found. FALSE for unknown signals.
	 *
	 * @return string
	 */
	public static function signalName($signo, &$found = null)
	{
		return ($found = isset(self::$signals[$signo])) ? self::$signals[$signo] : 'UNKNOWN';
	}


	/**
	 * Initializes signal handler
	 *
	 * @throws AzaException
	 *
	 * @see pcntl_signal
	 *
	 * @param callback $handler
	 * @param int $signo
	 * @param bool $ignore
	 * @param bool $default
	 */
	public static function signalHandle($handler, $signo = null, $ignore = false, $default = false)
	{
		$handler = $ignore ? SIG_IGN : ($default ? SIG_DFL : $handler);

		if ($signo !== null) {
			if (isset(self::$signals[$signo]) && SIGKILL !== $signo && SIGSTOP !== $signo) {
				if (!pcntl_signal($signo, $handler)) {
					$name = self::$signals[$signo];
					throw new AzaException("Can't initialize signal handler for $name ($signo)");
				}
			}
		} else {
			foreach (self::$signals as $signo => $name) {
				if ($signo === SIGKILL || $signo === SIGSTOP) {
					continue;
				}
				if (!pcntl_signal($signo, $handler)) {
					throw new AzaException("Can't initialize signal handler for $name ($signo)");
				}
			}
		}
	}


	/**
	 * Waits for the signal, with a timeout
	 *
	 * @see pcntl_sigtimedwait
	 * @see pcntl_sigprocmask
	 *
	 * @param int $signo <p>
	 * Signal's number
	 * </p>
	 * @param int $seconds [optional] <p>
	 * Timeout in seconds.
	 * </p>
	 * @param int $nanoseconds [optional] <p>
	 * Timeout in nanoseconds.
	 * </p>
	 * @param array $siginfo [optional] <p>
	 * The siginfo is set to an array containing
	 * informations about the signal. See
	 * {@link pcntl_sigwaitinfo}.
	 * </p>
	 *
	 * @return bool
	 */
	public static function signalWait($signo, $seconds = 1, $nanoseconds = null, &$siginfo = null)
	{
		if (isset(self::$signals[$signo])) {
			pcntl_sigprocmask(SIG_BLOCK, array($signo));
			$res = pcntl_sigtimedwait(array($signo), $siginfo, $seconds, $nanoseconds);
			pcntl_sigprocmask(SIG_UNBLOCK, array($signo));
			if ($res > 0) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Forks the currently running process
	 *
	 * @throws AzaException if could not fork
	 *
	 * @return int the PID of the child process is returned
	 * in the parent's thread of execution, and a 0 is
	 * returned in the child's thread of execution.
	 */
	public static function fork()
	{
		/*
		 * pcntl_fork triggers E_WARNING errors.
		 * For Ubuntu error codes indicate the following:
		 *
		 * Error 11: Resource temporarily unavailable
		 * Error 12: Cannot allocate memory
		 */
		$pid = pcntl_fork();
		if ($pid === -1) {
			throw new AzaException('Could not fork');
		} else if ($pid === 0) {
			self::$isMaster = false;
		}
		return $pid;
	}

	/**
	 * Detach process from the controlling terminal
	 *
	 * @throws AzaException if could not detach
	 */
	public static function detach()
	{
		// Fork and exit in parent process
		if (self::fork()) {
			exit;
		}

		// Make the current process a session leader
		self::$isMaster = true;
		if (posix_setsid() === -1) {
			throw new AzaException('Could not detach from terminal');
		}
	}


	/**
	 * Returns current tty width in columns
	 *
	 * @param resource $stream
	 *
	 * @return int
	 */
	public static function getTtyColumns($stream = null)
	{
		if (IS_WIN || !self::getIsTty($stream ?: STDOUT)) {
			return 95;
		}
		$cols = (int)shell_exec('stty -a|grep -oPm 1 "(?<=columns )(\d+)(?=;)"');
		return max(40, $cols);
	}

	/**
	 * Determine if a file descriptor is an interactive terminal
	 *
	 * @param resource|int $stream File descriptor resource
	 *
	 * @return bool
	 */
	public static function getIsTty($stream)
	{
		return function_exists('posix_isatty') && @posix_isatty($stream);
	}


	/**
	 * Returns command by PID
	 *
	 * @param int $pid
	 *
	 * @return string
	 */
	public static function getCommandByPid($pid)
	{
		if ($pid < 1 || IS_WIN) {
			return '';
		}
		exec("ps -p {$pid} -o%c", $data);
		return $data && count($data) === 2 ? array_pop($data) : '';
	}


	/**
	 * Kills process with it's childs recursively
	 *
	 * @throws AzaException
	 *
	 * @param int $pid
	 * @param int $signal Kill signal
	 */
	public static function killProcessTree($pid, $signal = SIGKILL)
	{
		if ($pid < 1 || IS_WIN) {
			return;
		}

		// Kill childs
		exec("ps -ef| awk '\$3 == '$pid' { print  \$2 }'", $output, $ret);
		if ($ret) {
			throw new AzaException('You need ps, grep, and awk', 1);
		}
		foreach ($output as $t) {
			if ($t != $pid) {
				self::killProcessTree($t, $signal);
			}
		}

		// Kill self
		posix_kill($pid, $signal);
	}


	/**
	 * Checks if process is running.
	 * Supports processes of another user if you have no root rights.
	 *
	 * @param int $pid
	 *
	 * @return bool
	 */
	public static function getProcessIsAlive($pid)
	{
		// EPERM === 1
		return posix_kill($pid, 0) || posix_get_last_error() === 1;
	}


	/**
	 * Sets current process title
	 *
	 * @param string $title
	 */
	public static function setProcessTitle($title)
	{
		/** @noinspection PhpUndefinedFunctionInspection */
		function_exists('setproctitle') && setproctitle($title);
	}


	/**
	 * Returns prepared for logging time string
	 *
	 * @return string
	 */
	public static function getLogTime()
	{
		$mt = explode(' ', microtime());
		return '[' . date('Y.m.d H:i:s', $mt[1]) . '.' . sprintf('%06d', $mt[0] * 1000000) . ' ' . date('O') . ']';
	}
}

CShell::$hasForkSupport = IS_CLI && function_exists('pcntl_fork');
CShell::$hasLibevent    = function_exists('event_base_new');
