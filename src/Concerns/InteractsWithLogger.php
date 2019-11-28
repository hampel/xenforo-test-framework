<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\Logger;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;

trait InteractsWithLogger
{
	/**
	 * Allow us to assert that certain moderator actions were (or were not) logged as a result of executing our test
	 * code, without side-effects (ie no logs written to database).
	 */
	protected function fakesLogger()
	{
		$this->swap('logger', function (Container $c) {
			return new Logger($this->app);
		});
	}

	/**
	 * @return Logger
	 * @throws \Exception
	 */
	protected function getLoggerFake()
	{
		$logger = $this->app['logger'];
		if (!($logger instanceof Logger))
		{
			throw new \Exception("Test logger fake not set up - call fakesLogger() first");
		}
		return $logger;
	}

	/**
	 * Return an array of all actions
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function getActions()
	{
		return $this->getLoggerFake()->getActions();
	}

	/**
	 * Return an array of all changes
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function getChanges()
	{
		return $this->getLoggerFake()->getChanges();
	}

    /**
     * Assert an action on a particular content type was logged based on a truth-test callback.
     *
     * @param string $type
     * @param  callable|int|null  $callback
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertActionLogged($type, $callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertActionLoggedTimes($callback);
        }

	    $loggedActions = $this->loggedActions($type, $callback);

        PHPUnit::assertTrue(
            count($loggedActions) > 0,
            "The expected [{$type}] action was not logged."
        );
    }

    /**
     * Assert a change on a particular content type was logged based on a truth-test callback.
     *
     * @param string $type
     * @param  callable|int|null  $callback
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertChangeLogged($type, $callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertChangeLoggedTimes($callback);
        }

	    $loggedChanges = $this->loggedChanges($type, $callback);

        PHPUnit::assertTrue(
            count($loggedChanges) > 0,
            "The expected [{$type}] change was not logged."
        );
    }

	/**
	 * Assert that an action was logged a number of times.
	 *
	 * @param string $type
	 * @param int $times
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
    protected function assertActionLoggedTimes($type, $times = 1)
    {
    	$loggedActions = $this->loggedActions($type);

        PHPUnit::assertTrue(
            ($count = count($loggedActions)) === $times,
            "The expected [{$type}] action was logged {$count} times instead of {$times} times."
        );
    }

	/**
	 * Assert that a change was logged a number of times.
	 *
	 * @param string $type
	 * @param int $times
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
    protected function assertChangeLoggedTimes($type, $times = 1)
    {
    	$loggedChanges = $this->loggedChanges($type);

        PHPUnit::assertTrue(
            ($count = count($loggedChanges)) === $times,
            "The expected [{$type}] change was logged {$count} times instead of {$times} times."
        );
    }

     /**
     * Determine if action was not logged based on a truth-test callback.
     *
     * @param string $type
      * @param  callable|null  $callback
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertActionNotLogged($type, $callback = null)
    {
	    $loggedActions = $this->loggedActions($type, $callback);

        PHPUnit::assertTrue(
            count($loggedActions) === 0,
            "Unexpected [{$type}] action was logged."
        );
    }

     /**
     * Determine if change was not logged based on a truth-test callback.
     *
     * @param string $type
      * @param  callable|null  $callback
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertChangeNotLogged($type, $callback = null)
    {
	    $loggedChanges = $this->loggedChanges($type, $callback);

        PHPUnit::assertTrue(
            count($loggedChanges) === 0,
            "Unexpected [{$type}] change was logged."
        );
    }

    /**
     * Assert that no actions were logged.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertNoActionsLogged()
    {
    	$loggedActions = $this->getActions();

        PHPUnit::assertEmpty($loggedActions, 'Actions were logged unexpectedly.');
    }

    /**
     * Assert that no changes were logged.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertNoChangesLogged()
    {
    	$loggedChanges = $this->getChanges();

        PHPUnit::assertEmpty($loggedChanges, 'Changes were logged unexpectedly.');
    }

    /**
     * Get all of the logged actions matching a truth-test callback.
     *
     * @param string $type
     * @param  callable|null  $callback
     *
     * @return array
     *
     * @throws \Exception
     */
    private function loggedActions($type, $callback = null)
    {
        if (! $this->hasLoggedAction($type)) {
            return [];
        }

        $callback = $callback ?: function () {
            return true;
        };

        $loggedActions = $this->actionsOf($type);

        return array_filter($loggedActions, function ($exception) use ($callback) {
            return $callback($exception);
        });
    }

    /**
     * Get all of the logged changes matching a truth-test callback.
     *
     * @param string $type
     * @param  callable|null  $callback
     *
     * @return array
     *
     * @throws \Exception
     */
    private function loggedChanges($type, $callback = null)
    {
        if (! $this->hasLoggedChange($type)) {
            return [];
        }

        $callback = $callback ?: function () {
            return true;
        };

        $loggedChanges = $this->changesOf($type);

        return array_filter($loggedChanges, function ($exception) use ($callback) {
            return $callback($exception);
        });
    }

    /**
     * Determine if the given action has been logged.
     *
     * @param  string  $type
     *
     * @return bool
     * @throws \Exception
     */
    protected function hasLoggedAction($type)
    {
    	$actions = $this->actionsOf($type);

        return count($actions) > 0;
    }

    /**
     * Determine if the given change has been logged.
     *
     * @param  string  $type
     *
     * @return bool
     * @throws \Exception
     */
    protected function hasLoggedChange($type)
    {
    	$changes = $this->changesOf($type);

        return count($changes) > 0;
    }

    /**
     * Get all of the logged actions for a given type.
     *
     * @param  string  $type
     *
     * @return array
     * @throws \Exception
     */
    private function actionsOf($type)
    {
    	$actions = $this->getActions();

        return array_filter($actions, function ($log) use ($type) {
			return $log['type'] === $type;
        });
    }

    /**
     * Get all of the logged changes for a given type.
     *
     * @param  string  $type
     *
     * @return array
     * @throws \Exception
     */
    private function changesOf($type)
    {
    	$changes = $this->getChanges();

        return array_filter($changes, function ($log) use ($type) {
			return $log['type'] === $type;
        });
    }
}
