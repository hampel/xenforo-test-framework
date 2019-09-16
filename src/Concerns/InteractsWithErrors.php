<?php namespace Hampel\Testing\Concerns;

use Closure;
use Hampel\Testing\Error;

trait InteractsWithErrors
{
	protected function fakesErrors()
	{
		$this->swap('error', function (Container $c) {
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
	 * @return array
	 * @throws \Exception
	 */
	public function getExceptions()
	{
		return $this->getErrorFake()->getExceptions();
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
    public function assertJobQueued($class, $callback = null)
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
	 * Assert that an exception was logged a number of times.
	 *
	 * @param string $class
	 * @param int $times
	 * @return void
	 *
	 * @throws \Exception
	 */
    protected function assertJobQueuedTimes($class, $times = 1)
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
    public function assertExceptionNotLogged($class, $callback = null)
    {
	    $loggedExceptions = $this->loggedExceptions($class, $callback);

        PHPUnit::assertTrue(
            count($loggedExceptions) === 0,
            "Unexpected [{$class}] exception was logged."
        );
    }

    /**
     * Assert that no exceptions were logged.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function assertNoExceptionsLogged()
    {
    	$loggedExceptions = $this->getExceptions();

        PHPUnit::assertEmpty($loggedExceptions, 'Exceptions were logged unexpectedly.');
    }

    /**
     * Get all of the logged exceptions matching a truth-test callback.
     *
     * @param string $class
     * @param  callable|null  $callback
     * @return \Swift_Mime_Message[]
     *
     * @throws \Exception
     */
    public function loggedExceptions($class, $callback = null)
    {
        if (! $this->hasLoggedException($class)) {
            return [];
        }

        $callback = $callback ?: function () {
            return true;
        };

        $loggedExceptions = $this->exceptionsOf($class);

        return array_filter($loggedExceptions, function ($job) use ($callback) {
            return $callback($job);
        });
    }

    /**
     * Determine if the given exception has been logged.
     *
     * @param  string  $class
     * @return bool
     * @throws \Exception
     */
    public function hasLoggedException($class)
    {
    	$exceptions = $this->exceptionsOf($class);

        return count($exceptions) > 0;
    }

    /**
     * Get all of the logged exceptions for a given type.
     *
     * @param  string  $class
     * @return array
     * @throws \Exception
     */
    protected function exceptionsOf($class)
    {
    	$exceptions = $this->getExceptions();

        return array_filter($exceptions, function ($exception) use ($class) {
			return $exception['raw_exception'] instanceof $class;
        });
    }
}
