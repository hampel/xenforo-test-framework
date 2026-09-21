<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Job\Manager;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;
use XF\Job\JobResult;

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

			\XF::triggerRunOnce(true);

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
}
