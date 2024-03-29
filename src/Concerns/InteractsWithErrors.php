<?php namespace Hampel\Testing\Concerns;

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
		return $this->swap('error', function (Container $c) {
			return new Error($this->app);
		});
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
     * Assert if exception was logged based on a truth-test callback.
     *
     * @param string $class
     * @param  callable|int|null  $callback
     * @return void
     *
     * @throws \Exception
     */
    protected function assertExceptionLogged($class, $callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertExceptionLoggedTimes($callback);
        }

	    $loggedExceptions = $this->loggedExceptions($class, $callback);

        PHPUnit::assertTrue(
            count($loggedExceptions) > 0,
            "The expected [{$class}] exception was not logged."
        );
    }

    /**
     * Assert an error was logged matching the supplied message.
     *
     * @param string $message
     * @return void
     *
     * @throws \Exception
     */
    protected function assertErrorLogged($message)
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
     * Determine if exception was not logged based on a truth-test callback.
     *
     * @param string $class
     * @param  callable|null  $callback
     * @return void
     *
     * @throws \Exception
     */
    protected function assertExceptionNotLogged($class, $callback = null)
    {
	    $loggedExceptions = $this->loggedExceptions($class, $callback);

        PHPUnit::assertTrue(
            count($loggedExceptions) === 0,
            "Unexpected [{$class}] exception was logged."
        );
    }

     /**
     * Determine if error matching message was not logged
     *
     * @param string $message
     * @return void
     *
     * @throws \Exception
     */
    protected function assertErrorNotLogged($message)
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
     * Get all of the logged exceptions matching a truth-test callback.
     *
     * @param string $class
     * @param  callable|null  $callback
     * @return array
     *
     * @throws \Exception
     */
    private function loggedExceptions($class, $callback = null)
    {
        if (! $this->hasLoggedException($class)) {
            return [];
        }

        $callback = $callback ?: function () {
            return true;
        };

        $loggedExceptions = $this->exceptionsOf($class);

        return array_filter($loggedExceptions, function ($exception) use ($callback) {
            return $callback($exception);
        });
    }

    /**
     * Get all of the logged errors matching the supplied message.
     *
     * @param string $message
     * @return array
     *
     * @throws \Exception
     */
    private function loggedErrors($message)
    {
        if (! $this->hasLoggedErrors()) {
            return [];
        }

        $loggedErrors = $this->getErrors();

        return array_filter($loggedErrors, function ($exception) use ($message) {
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

        return array_filter($exceptions, function ($exception) use ($class) {
			return $exception['raw_exception'] instanceof $class;
        });
    }
}
