<?php namespace Hampel\Testing;

use XF\Error as BaseError;

class Error extends BaseError
{
	protected $exceptions = [];

	public function hasPendingUpgrade()
	{
		$this->hasPendingUpgrade = false;

		return $this->hasPendingUpgrade;
	}

	public function logError($message, $forceLog = false)
	{
		$this->logException(new \ErrorException($message), false, '', $forceLog);
	}

	public function logException($e, $rollback = false, $messagePrefix = '', $forceLog = false)
	{
		/** @var \Throwable $e */

		try
		{
			$isValidArg = ($e instanceof \Exception || $e instanceof \Throwable);
			if (!$isValidArg)
			{
				$e = new \ErrorException('Non-exception passed to logException. See trace for details.');
			}

			$rootDir = \XF::getRootDirectory() . \XF::$DS;
			$file = str_replace($rootDir, '', $e->getFile());

			$requestInfo = $this->getRequestDataForExceptionLog();

			if ($messagePrefix)
			{
				$messagePrefix = trim($messagePrefix) . ' ';
			}

			$trace = $this->getTraceStringFromThrowable($e);

			$traceExtras = $this->addExtrasToTrace($e);
			if ($traceExtras)
			{
				$trace = $traceExtras . "\n------------\n\n" . $trace;
			}

			$exceptionMessage = $this->adjustExceptionMessage($e->getMessage(), $e);

			$this->exceptions[] = [
				'exception_date' => \XF::$time,
				'exception_type' => utf8_substr(get_class($e), 0, 75),
				'message' => utf8_substr($messagePrefix . $exceptionMessage, 0, 20000),
				'filename' => utf8_substr($file, 0, 255),
				'line' => $e->getLine(),
				'trace_string' => $trace,
				'request_state' => json_encode($requestInfo, JSON_PARTIAL_OUTPUT_ON_ERROR),
				'raw_exception' => $e
			];
		}
		catch (\Exception $e) {}

		return false;
	}

	public function displayFatalExceptionMessage($e)
	{
	}

	public function getExceptions()
	{
		return $this->exceptions;
	}
}
