<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Integration\Job\CountingJob;
use Hampel\Testing\Integration\Job\FailingJob;
use Hampel\Testing\Integration\Job\ForgetfulJob;
use Hampel\Testing\Integration\Job\ThrowingJob;
use XF\Job\JobResult;

/**
 * runJobToCompletion(). The probe jobs live in integration/Job, which PHPUnit does not load
 * while building the suite - they extend a XenForo class, so they can only be loaded after boot.
 */
class RunJobTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		CountingJob::$passes = 0;
		CountingJob::$deferredRuns = 0;
	}

	public function test_a_job_is_run_until_it_completes()
	{
		$result = $this->runJobToCompletion(CountingJob::class, ['target' => 3]);

		$this->assertSame(JobResult::RESULT_COMPLETED, $result->result);
		$this->assertSame(3, CountingJob::$passes);
	}

	public function test_a_short_name_is_accepted()
	{
		$result = $this->runJobToCompletion('Hampel\\Testing\\Integration:CountingJob', ['target' => 2]);

		$this->assertSame(JobResult::RESULT_COMPLETED, $result->result);
		$this->assertSame(2, CountingJob::$passes);
	}

	public function test_each_pass_resumes_from_the_data_the_last_one_returned()
	{
		// a job holding its position only on the instance never finishes when each pass is a
		// new instance - which is what XenForo's job manager does
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('had not completed after 10 passes');

		$this->runJobToCompletion(ForgetfulJob::class, [], 30, 10);
	}

	public function test_deferred_work_runs_after_each_pass()
	{
		$this->runJobToCompletion(CountingJob::class, ['target' => 2]);

		$this->assertSame(2, CountingJob::$deferredRuns);
	}

	public function test_a_failed_result_fails_the_test()
	{
		// JobResult::$completed is true for a failed job too, so this must not come back as done
		try
		{
			$this->runJobToCompletion(FailingJob::class);
			$this->fail('a failed job should have been refused');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('probe failure', $e->getMessage());
			$this->assertInstanceOf(\Exception::class, $e->getPrevious());
		}
	}

	public function test_an_exception_the_job_throws_is_not_swallowed()
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('probe exception');

		$this->runJobToCompletion(ThrowingJob::class);
	}

	public function test_a_job_that_does_not_exist_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('does not exist');

		$this->runJobToCompletion('XF:NoSuchJobAnywhere');
	}
}
