<?php namespace Hampel\Testing\Concerns;

use Mockery\MockInterface;
use XF\Db\AbstractAdapter;

/**
 * Wrap each test in a database transaction and roll it back afterwards, so tests can exercise
 * real entity saves, finders and repositories without leaving anything behind.
 *
 * Unlike the other concerns this one is NOT composed into Hampel\Testing\TestCase - it needs a
 * real database connection and it changes how your test behaves, so you opt in per test class:
 *
 *     class ThingTest extends TestCase
 *     {
 *         use \Hampel\Testing\Concerns\UsesDatabaseTransactions;
 *     }
 *
 * Nested transactions are safe. XenForo's adapter issues a SAVEPOINT rather than a second
 * BEGIN, so code under test may run its own beginTransaction()/commit() - as entity saves do -
 * without escaping the wrapper. rollbackAll() unwinds the lot.
 *
 * Two things it cannot roll back, both MySQL rather than XenForo:
 *
 *  - DDL implicitly commits, so anything that alters the schema (a Setup.php step, say) escapes
 *    and has to clean up after itself.
 *  - Only this connection sees the uncommitted rows, so nothing that reads the database through
 *    a second connection will see what the test wrote.
 */
trait UsesDatabaseTransactions
{
	protected function setUpDatabaseTransactions()
	{
		$db = $this->app()->db();

		if (!($db instanceof AbstractAdapter) || $db instanceof MockInterface)
		{
			throw new \LogicException(
				'UsesDatabaseTransactions needs a real database connection - it cannot be '
					. 'combined with mockDatabase().'
			);
		}

		$db->beginTransaction();

		$this->beforeApplicationDestroyed(function () use ($db)
		{
			if ($db->inTransaction())
			{
				$db->rollbackAll();
			}
		});
	}
}
