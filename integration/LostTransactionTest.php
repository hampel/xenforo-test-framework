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

	public function test_an_open_transaction_is_reported_as_open()
	{
		$this->assertFalse($this->transactionWasCommitted($this->app()->db()));
	}

	public function test_an_implicit_commit_is_detected()
	{
		$db = $this->app()->db();

		try
		{
			// DDL, so the server commits the wrapper out from under us
			$db->query('CREATE TABLE xf_lost_transaction_probe (probe_id INT)');

			$this->assertTrue($this->transactionWasCommitted($db));
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
