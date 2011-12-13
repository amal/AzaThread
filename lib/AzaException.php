<?php

/**
 * Basic Anizoptera exception
 *
 * @project Anizoptera CMF
 * @package system.exceptions
 * @version $Id: AzaException.php 2381 2011-06-21 10:55:20Z samally $
 */
class AzaException extends RuntimeException
{
	/**
	 * Initializes exception.
	 *
	 * @param string $msg [optional]
	 * 			Exception message
	 *
	 * @param int $traceBack [optional]
	 * 			Use as a line number and file throwing an exception not the top item from the backtrace, and which one to use.
	 *
	 * @param int $code [optional]
	 * 			Exception code
	 *
	 * @param Exception $previous [optional]
	 * 			Previous exception in stack
	 */
	public function __construct($msg = null, $traceBack = 0, $code = E_WARNING, $previous = null)
	{
		if (null === $previous) {
			parent::__construct($msg, $code);
		} else {
			parent::__construct($msg, $code, $previous);
		}
		if ($traceBack > 0) {
			$traceBack = $traceBack-1;
			$trace = $this->getTrace();
			if (isset($trace[$traceBack]['line'])) {
				$this->file = $trace[$traceBack]['file'];
				$this->line = $trace[$traceBack]['line'];
			}
		}
	}
}
