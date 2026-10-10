<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Job\Manager;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;
use XF\Job\JobResult;
use XF\MultiPartRunnerTrait;

trait InteractsWithJobs
{
	/**
	 * Run a job to completion and return its final result.
	 *
	 * Each pass is a new instance of the job, built from the data the previous pass returned, as
	 * XenForo's job manager does - so a job that keeps its position only on the instance, rather
	 * than in the data its result carries, does not complete here either. Work queued with
	 * \XF::runOnce() runs after each pass.
	 *
	 * Unlike the manager, an exception the job throws is not caught, logged or rolled back - it
	 * fails the test.
	 *
	 * @param string $jobClass - 'XF:Thing' or a full class name
	 * @param array $data - the job's parameters, as enqueue() would take them
	 * @param int $maxRunTime - seconds allowed per pass
	 * @param int $maxPasses - how many passes before the job is judged not to finish
	 *
	 * @return JobResult
	 */
	protected function runJobToCompletion($jobClass, array $data = [], $maxRunTime = 30, $maxPasses = 1000)
	{
		for ($pass = 1; $pass <= $maxPasses; $pass++)
		{
			$job = $this->app()->job($jobClass, null, $data);
			if (!$job)
			{
				throw new \LogicException("Job '$jobClass' does not exist");
			}

			$result = $job->run($maxRunTime);

			$this->drainRunOnce();

			// JobResult::$completed is also true for a failed job, so check failure first
			if ($result->result === JobResult::RESULT_FAILED)
			{
				throw new \LogicException(
					"Job '$jobClass' failed on pass $pass"
						. ($result->exception ? ': ' . $result->exception->getMessage() : ''),
					0,
					$result->exception
				);
			}

			if ($result->result === JobResult::RESULT_COMPLETED)
			{
				return $result;
			}

			$data = $result->data;
		}

		throw new \LogicException("Job '$jobClass' had not completed after $maxPasses passes");
	}

	/**
	 * Run one step of a multi-part runner to completion, and return how many times it ran.
	 *
	 * XenForo's clean-up and merge services are multi-part runners: a list of steps, run by
	 * XF\MultiPartRunnerTrait::runLoop(). Running all of them to test one is how a test ends up
	 * contending with the live forum over rows the steps it does not care about rebuild, so this
	 * runs the one step whose behaviour the test is about - which for an add-on answering
	 * user_delete_clean_init or user_delete_clean_steps is the step that consumes its entry.
	 *
	 * A step is not a method you call once. Its return value is a resume offset: null or false
	 * means finished, anything else means call me again from there - and a step may ask for that
	 * because it batches rather than because it ran out of time, so invoking it once can leave work
	 * undone with nothing to say so. This loops until the step reports it is finished.
	 *
	 * The step is named rather than passed, and the name is checked against the runner's own
	 * getSteps() - which fires its event, so a step a listener added is selectable too.
	 *
	 * @param object $runner - a service using XF\MultiPartRunnerTrait, as service() built it
	 * @param string $stepName - a method name as getSteps() lists it
	 * @param int $maxCalls - how many calls before the step is judged not to finish
	 *
	 * @return int - how many times the step ran, which is 1 for a step that needs no resuming
	 */
	protected function runRunnerStep($runner, $stepName, $maxCalls = 1000)
	{
		if (!in_array(MultiPartRunnerTrait::class, $this->classUsesRecursive($runner)))
		{
			throw new \LogicException(
				get_class($runner) . ' is not a multi-part runner, so it has no steps to run'
			);
		}

		$steps = $this->runnerStepNames($runner);

		if (!in_array($stepName, $steps, true))
		{
			throw new \LogicException(
				get_class($runner) . " does not run a step named '$stepName'."
					. ' The steps it runs are: ' . (implode(', ', $steps) ?: '(none)')
					. '. A step added as a closure has no name and cannot be selected.'
			);
		}

		$method = new \ReflectionMethod($runner, $stepName);
		$method->setAccessible(true);

		// stepMiscCleanUp() takes nothing, stepDeleteContent($lastOffset, $maxRunTime) takes two
		$arity = $method->getNumberOfParameters();
		$offset = null;

		for ($call = 1; $call <= $maxCalls; $call++)
		{
			$arguments = array_slice([$offset, 0], 0, $arity);

			$result = $method->invokeArgs($runner, $arguments);

			if ($result === null || $result === false)
			{
				return $call;
			}

			if ($result === $offset)
			{
				throw new \LogicException(
					get_class($runner) . "::$stepName asked to resume from the position it was"
						. ' already given, so it would never finish'
				);
			}

			$offset = $result;
		}

		throw new \LogicException(
			get_class($runner) . "::$stepName had not finished after $maxCalls calls"
		);
	}

	/**
	 * @param object $runner
	 *
	 * @return string[]
	 */
	private function runnerStepNames($runner)
	{
		$getSteps = new \ReflectionMethod($runner, 'getSteps');
		$getSteps->setAccessible(true);

		$names = [];

		foreach ($getSteps->invoke($runner) AS $step)
		{
			if (is_string($step))
			{
				$names[] = $step;
			}
			else if (is_array($step) && count($step) == 2 && is_string($step[1]))
			{
				$names[] = $step[1];
			}
		}

		return $names;
	}

	/**
	 * Allow us to assert that certain jobs were (or were not) queued as a result of executing our test code, without
	 * side-effects (ie no jobs written to database or executed).
	 */
	protected function fakesJobs()
	{
		$this->swap('job.manager', function (Container $c)
		{
			return new Manager($this->app);
		});

		return $this->getJobManager();
	}

	/**
	 * @return Manager
	 * @throws \Exception
	 */
	protected function getJobManager()
	{
		$manager = $this->app['job.manager'];
		if (!($manager instanceof Manager))
		{
			throw new \Exception("Test job manager not set up - call fakesJobs() first");
		}
		return $manager;
	}

	/**
	 * Return an array of all queued jobs
	 *
	 * @return array
	 * @throws \Exception
	 */
	protected function getQueuedJobs()
	{
		return $this->getJobManager()->getQueuedJobs();
	}

	/**
	 * Assert if job was queued based on a truth-test callback.
	 *
	 * @param string $shortName
	 * @param  callable|int|null  $callback
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertJobQueued($shortName, $callback = null)
	{
		if (is_numeric($callback))
		{
			$this->assertJobQueuedTimes($shortName, $callback);
			return;
		}

		$queuedJobs = $this->queuedJobs($shortName, $callback);

		PHPUnit::assertTrue(
			count($queuedJobs) > 0,
			"The expected [{$shortName}] job was not queued."
		);
	}

	/**
	 * Assert that a job was queued a number of times.
	 *
	 * @param string $shortName
	 * @param int $times
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertJobQueuedTimes($shortName, $times = 1)
	{
		$queuedJobs = $this->queuedJobs($shortName);

		PHPUnit::assertTrue(
			($count = count($queuedJobs)) === $times,
			"The expected [{$shortName}] job was queued {$count} times instead of {$times} times."
		);
	}

	/**
	 * Determine if job was not queued based on a truth-test callback.
	 *
	 * @param string $shortName
	 * @param  callable|null  $callback
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertJobNotQueued($shortName, $callback = null)
	{
		$queuedJobs = $this->queuedJobs($shortName, $callback);

		PHPUnit::assertTrue(
			count($queuedJobs) === 0,
			"Unexpected [{$shortName}] job was queued."
		);
	}

	/**
	 * Assert that no jobs were queued.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertNoJobsQueued()
	{
		$queuedJobs = $this->getQueuedJobs();

		PHPUnit::assertEmpty($queuedJobs, 'Jobs were queued unexpectedly.');
	}

	/**
	 * Get all of the queued jobs matching a truth-test callback.
	 *
	 * @param string $shortName
	 * @param  callable|null  $callback
	 * @return array
	 *
	 * @throws \Exception
	 */
	private function queuedJobs($shortName, $callback = null)
	{
		if (! $this->hasQueuedJob($shortName))
		{
			return [];
		}

		$callback = $callback ?: function ()
		{
			return true;
		};

		$queuedJobs = $this->jobsOf($shortName);

		return array_filter($queuedJobs, function ($job) use ($callback)
		{
			return $callback($job);
		});
	}

	/**
	 * Determine if the given job has been queued.
	 *
	 * @param  string  $shortName
	 * @return bool
	 * @throws \Exception
	 */
	protected function hasQueuedJob($shortName)
	{
		$jobs = $this->jobsOf($shortName);

		return count($jobs) > 0;
	}

	/**
	 * Get all of the queued jobs for a given type.
	 *
	 * @param  string  $shortName
	 * @return array
	 * @throws \Exception
	 */
	private function jobsOf($shortName)
	{
		$queuedJobs = $this->getQueuedJobs();

		return array_filter($queuedJobs, function ($job) use ($shortName)
		{
			return $job['execute_class'] == $shortName;
		});
	}

	/**
	 * Run the work queued with \XF::runOnce(), less XenForo's own job bookkeeping.
	 *
	 * Enqueuing a job that is not manual queues a registry write of `autoJobRun` - when the next
	 * automatic run is due. It is bookkeeping about the request rather than anything a test
	 * asserts on, and it writes to a row the forum's own traffic and cron also write. Under
	 * UsesDatabaseTransactions that write sits inside the test's transaction, and MariaDB 11.8
	 * onwards, where snapshot isolation is on by default, refuses it with "Record has changed
	 * since last read" if anything else has touched the row since - an error that arrives on some
	 * runs and not others, in whichever test happened to dispatch.
	 *
	 * A job enqueued from inside other deferred work can still schedule it, because that happens
	 * within XenForo's own loop.
	 *
	 * @return void
	 */
	private function drainRunOnce()
	{
		$runOnce = $this->getStaticProperty(\XF::class, 'runOnce');

		if (is_array($runOnce) && isset($runOnce['autoJobRun']))
		{
			unset($runOnce['autoJobRun']);
			$this->writeStaticProperty(\XF::class, 'runOnce', $runOnce);
		}

		\XF::triggerRunOnce(true);
	}
}
