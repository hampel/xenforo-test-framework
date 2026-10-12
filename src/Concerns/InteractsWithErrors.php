<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Error;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;

trait InteractsWithErrors
{
	/**
	 * Allow us to assert that certain errors were (or were not) thrown as a result of executing our test code, without
	 * side-effects (ie no logs written to database).
	 */
	protected function fakesErrors()
	{
		$this->swap('error', function (Container $c)
		{
			return new Error($this->app);
		});

		return $this->getErrorFake();
	}

	/**
	 * @return Error
	 * @throws \Exception
	 */
	protected function getErrorFake()
	{
		$manager = $this->app['error'];
		if (!($manager instanceof Error))
		{
			throw new \Exception("Test error fake not set up - call fakesErrors() first");
		}
		return $manager;
	}

	/**
	 * Return an array of all exceptions
	 *
	 * Note that errors are stored as exceptions of type \ErrorException
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function getExceptions()
	{
		return $this->getErrorFake()->getExceptions();
	}

	/**
	 * Return an array of all errors
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function getErrors()
	{
		return $this->exceptionsOf(\ErrorException::class);
	}

	/**
	 * Assert that an exception of the given class was logged.
	 *
	 * @param string $class - the exception class
	 * @param int|string|callable|null $filter - a count, a string the logged message must contain,
	 *                                           or a callback given the logged entry. The message
	 *                                           carries the prefix passed to \XF::logException(),
	 *                                           so the prefix itself is a string you can match on
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertExceptionLogged($class, $filter = null)
	{
		if (is_numeric($filter))
		{
			$this->assertExceptionLoggedTimes($class, $filter);
			return;
		}

		$loggedExceptions = $this->loggedExceptions($class, $filter);

		PHPUnit::assertTrue(
			count($loggedExceptions) > 0,
			"The expected [{$class}] exception was not logged."
		);
	}

	/**
	 * Assert an error was logged matching the supplied message.
	 *
	 * @param string|null $message - omit to assert that any error at all was logged
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertErrorLogged($message = null)
	{
		$loggedErrors = $this->loggedErrors($message);

		PHPUnit::assertTrue(
			count($loggedErrors) > 0,
			"The expected error was not logged."
		);
	}

	/**
	 * Assert that an exception was logged a number of times.
	 *
	 * @param string $class
	 * @param int $times
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertExceptionLoggedTimes($class, $times = 1)
	{
		$loggedExceptions = $this->loggedExceptions($class);

		PHPUnit::assertTrue(
			($count = count($loggedExceptions)) === $times,
			"The expected [{$class}] exception was logged {$count} times instead of {$times} times."
		);
	}

	/**
	 * Assert that no exception of the given class was logged.
	 *
	 * Unlike assertExceptionLogged(), a count is not accepted here - there is no number of
	 * exceptions that counts as none - so an int reaches the filter and PHP rejects it as not
	 * callable. Use assertExceptionLoggedTimes() to assert a count.
	 *
	 * @param string $class - the exception class
	 * @param string|callable|null $filter - a string the logged message must contain, or a callback
	 *                                       given the logged entry. Narrowing the assertion this
	 *                                       way asserts that no exception of that class MATCHING
	 *                                       the filter was logged, not that none was logged at all
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertExceptionNotLogged($class, $filter = null)
	{
		$loggedExceptions = $this->loggedExceptions($class, $filter);

		PHPUnit::assertTrue(
			count($loggedExceptions) === 0,
			"Unexpected [{$class}] exception was logged."
		);
	}

	/**
	* Determine if error matching message was not logged
	*
	* @param string|null $message - omit to assert that no error at all was logged
	* @return void
	*
	* @throws \Exception
	*/
	protected function assertErrorNotLogged($message = null)
	{
		$loggedErrors = $this->loggedErrors($message);

		PHPUnit::assertTrue(
			count($loggedErrors) === 0,
			"Unexpected error was logged."
		);
	}

	/**
	 * Assert that no exceptions were logged.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertNoExceptionsLogged()
	{
		$loggedExceptions = $this->getExceptions();

		PHPUnit::assertEmpty($loggedExceptions, 'Exceptions were logged unexpectedly.');
	}

	/**
	 * Assert that no errors were logged
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertNoErrorsLogged()
	{
		$loggedErrors = $this->getErrors();

		PHPUnit::assertTrue(
			count($loggedErrors) === 0,
			"Unexpected error was logged."
		);
	}

	/**
	 * Get all of the logged exceptions matching the given filter.
	 *
	 * @param string $class
	 * @param string|callable|null $filter
	 * @return array
	 *
	 * @throws \Exception
	 */
	private function loggedExceptions($class, $filter = null)
	{
		if (! $this->hasLoggedException($class))
		{
			return [];
		}

		if (is_string($filter))
		{
			// the common case is the house rule that every logged message starts with the add-on's
			// prefix, which reads badly as a closure
			$needle = $filter;
			$filter = function (array $logged) use ($needle)
			{
				return strpos($logged['message'], $needle) !== false;
			};
		}

		$filter = $filter ?: function ()
		{
			return true;
		};

		$loggedExceptions = $this->exceptionsOf($class);

		return array_filter($loggedExceptions, function ($exception) use ($filter)
		{
			return $filter($exception);
		});
	}

	/**
	 * Get all of the logged errors matching the supplied message.
	 *
	 * @param string|null $message - null matches every logged error
	 * @return array
	 *
	 * @throws \Exception
	 */
	private function loggedErrors($message = null)
	{
		if (! $this->hasLoggedErrors())
		{
			return [];
		}

		$loggedErrors = $this->getErrors();

		if ($message === null)
		{
			return $loggedErrors;
		}

		return array_filter($loggedErrors, function ($exception) use ($message)
		{
			return $exception['message'] === strval($message);
		});
	}

	/**
	 * Determine if the given exception has been logged.
	 *
	 * @param  string  $class
	 * @return bool
	 * @throws \Exception
	 */
	protected function hasLoggedException($class)
	{
		$exceptions = $this->exceptionsOf($class);

		return count($exceptions) > 0;
	}

	/**
	 * Determine if the given error has been logged.
	 *
	 * @return bool
	 * @throws \Exception
	 */
	protected function hasLoggedErrors()
	{
		$errors = $this->getErrors();

		return count($errors) > 0;
	}

	/**
	 * Get all of the logged exceptions for a given type.
	 *
	 * @param  string  $class
	 * @return array
	 * @throws \Exception
	 */
	private function exceptionsOf($class)
	{
		$exceptions = $this->getExceptions();

		return array_filter($exceptions, function ($exception) use ($class)
		{
			return $exception['raw_exception'] instanceof $class;
		});
	}
}
