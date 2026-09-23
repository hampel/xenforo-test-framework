<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Hampel\Testing\Integration\Job\CountingJob;

/**
 * Deferred work run by dispatch(), callAction() and runJobToCompletion() leaves XenForo's own
 * `autoJobRun` registry row alone.
 *
 * The row is written by every ordinary request and by cron, so a test writing it inside a
 * transaction races the rest of the forum - and on MariaDB 11.8, which has snapshot isolation on
 * by default, the loser gets "Record has changed since last read". The race is not reproducible on
 * demand; the write is, which is what these assert.
 */
class JobBookkeepingTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function autoJobRunRow()
	{
		return $this->app()->db()->fetchOne(
			"SELECT data_value FROM xf_data_registry WHERE data_key = 'autoJobRun'"
		);
	}

	public function test_a_dispatch_does_not_write_the_job_bookkeeping_row()
	{
		$this->app()->jobManager()->enqueue(CountingJob::class, ['passes' => 1]);

		$before = $this->autoJobRunRow();

		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);
		$this->dispatch('tools', 'admin');

		$this->assertSame($before, $this->autoJobRunRow());
	}

	public function test_an_action_call_does_not_write_it_either()
	{
		$this->app()->jobManager()->enqueue(CountingJob::class, ['passes' => 1]);

		$before = $this->autoJobRunRow();

		$this->callAction('XF:Notice', 'save', 'admin', ['title' => '', 'message' => '']);

		$this->assertSame($before, $this->autoJobRunRow());
	}

	public function test_other_deferred_work_still_runs()
	{
		// the drain drops XenForo's job bookkeeping, not the test's own deferred work
		$ran = false;

		\XF::runOnce('probe', function () use (&$ran)
		{
			$ran = true;
		});

		$this->callAction('XF:Notice', 'save', 'admin', ['title' => '', 'message' => '']);

		$this->assertTrue($ran, 'work queued with runOnce() should still run');
	}
}
