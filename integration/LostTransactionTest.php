<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * The wrapper notices when something has committed the test's transaction.
 *
 * MySQL commits implicitly on any DDL, and the adapter cannot see it happen - its own flag still
 * says a transaction is open, so teardown rolls back nothing and everything the test wrote is
 * permanent. Only the server can answer, which is what these check.
 */
class LostTransactionTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * @param string $test
	 *
	 * @return string
	 */
	private function runProbe($test)
	{
		$phpunit = dirname(__DIR__) . '/vendor/bin/phpunit';
		$config = __DIR__ . '/phpunit.xml';
		$probe = __DIR__ . '/Fixtures/LostTransactionProbe.php';

		return (string) shell_exec(
			'XF_ROOT=' . escapeshellarg(\XF::getRootDirectory())
			. ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($config)
			. ' ' . escapeshellarg($probe)
			. ' --filter ' . escapeshellarg($test)
			. ' --do-not-cache-result 2>&1'
		);
	}

	public function test_the_check_stays_quiet_when_the_test_has_already_failed()
	{
		$output = $this->runProbe('test_a_failing_test_also_loses_its_transaction');

		// the deliberate failure is what the run reports
		$this->assertStringContainsString('deliberate failure', $output);

		// and the lost transaction is not reported on top of it, which is what buried a deadlock's
		// own error under a wrong explanation
		$this->assertStringNotContainsString('did not survive', $output);
	}

	public function test_the_check_still_fires_when_the_test_passes()
	{
		$output = $this->runProbe('test_a_passing_test_loses_its_transaction');

		$this->assertStringContainsString('did not survive', $output);
	}

	public function test_an_open_transaction_is_reported_as_surviving()
	{
		$this->assertNull($this->transactionDidNotSurvive($this->app()->db()));
	}

	public function test_a_rollback_by_the_code_under_test_is_detected()
	{
		$db = $this->app()->db();

		try
		{
			// what XenForo's job manager does when a job throws, before deciding what to do with it:
			// the wrapper goes, and everything written afterwards is committed
			$db->rollbackAll();

			$this->assertStringContainsString('ended it', (string) $this->transactionDidNotSurvive($db));
		}
		finally
		{
			$db->beginTransaction();
		}
	}

	public function test_an_implicit_commit_is_detected()
	{
		$db = $this->app()->db();

		try
		{
			// DDL, so the server commits the wrapper out from under us
			$db->query('CREATE TABLE xf_lost_transaction_probe (probe_id INT)');

			$this->assertStringContainsString('committed it', (string) $this->transactionDidNotSurvive($db));
		}
		finally
		{
			$db->query('DROP TABLE IF EXISTS xf_lost_transaction_probe');

			// leave teardown a real transaction to roll back, or this test fails itself. The
			// adapter still believes one is open, so it has to be cleared first - otherwise
			// beginTransaction() issues a SAVEPOINT and the server is still outside a transaction
			$db->rollbackAll();
			$db->beginTransaction();
		}
	}
}
