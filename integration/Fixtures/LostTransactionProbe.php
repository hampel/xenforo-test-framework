<?php

namespace Hampel\Testing\Integration\Fixtures;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Hampel\Testing\Integration\TestCase;

/**
 * Run in its own process by LostTransactionTest, because the teardown check it exercises can only
 * be observed from outside the test that triggers it. Not named *Test.php, so the suite does not
 * collect it.
 */
class LostTransactionProbe extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_a_failing_test_also_loses_its_transaction()
	{
		$this->app()->db()->rollbackAll();

		$this->fail('deliberate failure, standing in for the query that hit a deadlock');
	}

	public function test_a_passing_test_loses_its_transaction()
	{
		$this->app()->db()->rollbackAll();

		$this->assertTrue(true);
	}
}
