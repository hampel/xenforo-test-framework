<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Hampel\Testing\Integration\Job\CountingJob;

/**
 * Deferred work run by dispatch(), callAction(), runJobToCompletion() and runDeferred() leaves
 * XenForo's own `autoJobRun` registry row alone.
 *
 * The row is written by every ordinary request and by cron, so a test writing it inside a
 * transaction races the rest of the forum - and on MariaDB 11.8, which has snapshot isolation on
 * by default, the loser gets "Record has changed since last read". The race is not reproducible on
 * demand; the write is, which is what these assert - and they assert the write itself, read from
 * the adapter's query log, because the value written is usually the value already stored, so a
 * comparison of the row before and after sees nothing.
 */
class JobBookkeepingTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function watchQueries()
	{
		// false, not true: the simple log keeps no params, and the key is a param
		$this->app()->db()->logQueries(true, false);
	}

	private function registryWritesOf($key): int
	{
		$writes = 0;

		foreach ($this->app()->db()->getQueryLog() AS $entry)
		{
			if (stripos($entry['query'], 'xf_data_registry') !== false
				&& stripos($entry['query'], 'INSERT') !== false
				&& in_array($key, $entry['params'], true)
			)
			{
				$writes++;
			}
		}

		return $writes;
	}

	public function test_a_dispatch_does_not_write_the_job_bookkeeping_row()
	{
		$this->app()->jobManager()->enqueue(CountingJob::class, ['passes' => 1]);
		$this->watchQueries();

		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);
		$this->dispatch('tools', 'admin');

		$this->assertSame(0, $this->registryWritesOf('autoJobRun'));
	}

	public function test_an_action_call_does_not_write_it_either()
	{
		$this->app()->jobManager()->enqueue(CountingJob::class, ['passes' => 1]);
		$this->watchQueries();

		$this->callAction('XF:Notice', 'save', 'admin', ['title' => '', 'message' => '']);

		$this->assertSame(0, $this->registryWritesOf('autoJobRun'));
	}

	public function test_run_deferred_does_not_write_it_and_does_not_name_it()
	{
		$this->app()->jobManager()->enqueue(CountingJob::class, ['passes' => 1]);
		$this->watchQueries();

		$keys = $this->runDeferred();

		$this->assertSame(0, $this->registryWritesOf('autoJobRun'));
		$this->assertNotContains('autoJobRun', $keys);
	}

	public function test_the_query_log_sees_the_write_when_it_happens()
	{
		// the control for the three above: without it, a drain that wrote the row and a query log
		// that cannot see registry writes would both read as zero
		$this->watchQueries();

		$this->app()->registry()->set('zzProbeKey', 1);

		$this->assertSame(1, $this->registryWritesOf('zzProbeKey'));
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
