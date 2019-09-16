<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\Job\Manager;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;

trait InteractsWithJobs
{
	protected function fakesJobs()
	{
		$this->swap('job.manager', function (Container $c) {
			return new Manager($this->app);
		});
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
	public function getQueuedJobs()
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
    public function assertJobQueued($shortName, $callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertJobsQueuedTimes($callback);
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
    public function assertJobNotQueued($shortName, $callback = null)
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
    public function assertNoJobsQueued()
    {
    	$queuedJobs = $this->getQueuedJobs();

        PHPUnit::assertEmpty($queuedJobs, 'Jobs was queued unexpectedly.');
    }

    /**
     * Get all of the queued jobs matching a truth-test callback.
     *
     * @param string $shortName
     * @param  callable|null  $callback
     * @return \Swift_Mime_Message[]
     *
     * @throws \Exception
     */
    public function queuedJobs($shortName, $callback = null)
    {
        if (! $this->hasQueuedJob($shortName)) {
            return [];
        }

        $callback = $callback ?: function () {
            return true;
        };

        $queuedJobs = $this->jobsOf($shortName);

        return array_filter($queuedJobs, function ($job) use ($callback) {
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
    public function hasQueuedJob($shortName)
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
    protected function jobsOf($shortName)
    {
    	$queuedJobs = $this->getQueuedJobs();

        return array_filter($queuedJobs, function ($job) use ($shortName) {
			$class = \XF::stringToClass($shortName, '\%s\Job\%s');
			$class = $this->app()->extendClass($class);

            return $job instanceof $class;
        });
    }
}
