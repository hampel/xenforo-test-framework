<?php

namespace Hampel\Testing\Integration;

use XF\Job\FileCleanUp;
use XF\Job\UserRename;

/**
 * What assertJobQueued() matches on, and what it hands its truth-test callback.
 */
class JobAssertionShapeTest extends TestCase
{
	public function test_the_callback_receives_the_queued_job_as_an_array()
	{
		$this->fakesJobs();

		$this->app()->jobManager()->enqueue('XF:UserRename', ['key1' => 'value1']);

		$this->assertJobQueued('XF:UserRename', function ($job)
		{
			$this->assertIsArray($job, 'the callback receives the recorded array, not a job object');

			$this->assertSame(
				['execute_class', 'execute_data', 'unique_key', 'manual_execute', 'trigger_date'],
				array_keys($job)
			);

			return $job['execute_data']['key1'] === 'value1';
		});
	}

	/** the negative half: a callback that rejects means the job was not matched */
	public function test_a_callback_that_rejects_fails_the_assertion()
	{
		$this->fakesJobs();

		$this->app()->jobManager()->enqueue('XF:UserRename', ['key1' => 'value1']);

		$this->assertJobNotQueued('XF:UserRename', function ($job)
		{
			return $job['execute_data']['key1'] === 'something else';
		});
	}

	/**
	 * XenForo records the job class exactly as the caller passed it - Manager::enqueue() sets it
	 * on the params and prepareJobParams() is a no-op - so neither form is canonical. Assert
	 * whichever one the code under test uses.
	 */
	public function test_a_job_matches_the_name_the_code_under_test_used()
	{
		$this->fakesJobs();

		$this->app()->jobManager()->enqueue('XF:UserRename', []);
		$this->app()->jobManager()->enqueue(FileCleanUp::class, []);

		$this->assertJobQueued('XF:UserRename');
		$this->assertJobQueued(FileCleanUp::class);

		// the short name of a job enqueued by class does not match, and vice versa
		$this->assertJobNotQueued('XF:FileCleanUp');
		$this->assertJobNotQueued(UserRename::class);
	}
}
