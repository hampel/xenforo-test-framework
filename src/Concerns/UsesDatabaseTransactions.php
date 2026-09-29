<?php

namespace Hampel\Testing\Concerns;

use Mockery\MockInterface;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Db\AbstractAdapter;
use XF\Db\Exception as DbException;

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
 * What it cannot roll back, all of it MySQL rather than XenForo:
 *
 *  - DDL implicitly commits, so anything that alters the schema (a Setup.php step, say) escapes
 *    and has to clean up after itself. Teardown notices and fails the test.
 *  - Only this connection sees the uncommitted rows, so nothing that reads the database through
 *    a second connection will see what the test wrote.
 *  - An AUTO_INCREMENT counter advances inside the transaction and stays advanced afterwards.
 */
trait UsesDatabaseTransactions
{
	protected function setUpDatabaseTransactions()
	{
		$db = $this->app()->db();

		// db() is declared to return a real adapter, so the mock is the only case left to test for
		if ($db instanceof MockInterface)
		{
			throw new \LogicException(
				'UsesDatabaseTransactions needs a real database connection - it cannot be '
					. 'combined with mockDatabase().'
			);
		}

		$db->beginTransaction();

		$this->beforeApplicationDestroyed(function () use ($db)
		{
			$reason = $this->transactionDidNotSurvive($db);

			if ($db->inTransaction())
			{
				$db->rollbackAll();
			}

			if ($reason !== null)
			{
				PHPUnit::fail(
					'This test\'s transaction did not survive, so everything written after that '
					. "point is now permanent in the database: $reason"
				);
			}
		});
	}

	/**
	 * Say why the transaction this test opened is gone, or null if it is still there.
	 *
	 * Two ways to lose it, and neither is visible from the test:
	 *
	 *  - something ended it. rollbackAll() and commit() both do, and XenForo calls rollbackAll() on
	 *    its own error paths - the job manager does it when a job throws, before deciding what to do
	 *    with the job, so a test that runs a failing job through the manager loses the wrapper and
	 *    everything written afterwards, including what XenForo itself then writes.
	 *  - something committed it implicitly, which the adapter cannot see at all: its flag still says
	 *    a transaction is open and rollbackAll() then rolls back nothing.
	 *
	 * @param AbstractAdapter $db
	 *
	 * @return string|null
	 */
	private function transactionDidNotSurvive(AbstractAdapter $db)
	{
		if (!$db->inTransaction())
		{
			return 'the code under test ended it, with rollbackAll() or commit(). XenForo calls'
				. ' rollbackAll() on its own error paths - the job manager does when a job throws -'
				. ' so test the method directly rather than through something that handles errors,'
				. ' or leave this trait off that class and undo the writes yourself.';
		}

		$committed = null;

		try
		{
			// MariaDB
			$committed = !$db->fetchOne('SELECT @@in_transaction');
		}
		catch (DbException $e)
		{
			try
			{
				// MySQL has no such variable
				$committed = !$db->fetchOne(
					'SELECT COUNT(*) FROM information_schema.innodb_trx'
					. ' WHERE trx_mysql_thread_id = CONNECTION_ID()'
				);
			}
			catch (DbException $e)
			{
				// no answer available, so do not invent one
				return null;
			}
		}

		if (!$committed)
		{
			return null;
		}

		return 'something committed it. Any DDL does, including the TRUNCATE XenForo runs when it'
			. ' compiles a template - which on a development install happens whenever a rendered'
			. " template's _output/ file does not match the hash in _metadata.json, the state an edit"
			. ' leaves it in. Load a page that renders the template, outside any test, and the'
			. ' watcher re-imports it and writes that hash. Neither xf-dev:import nor xf-dev:export'
			. ' is the answer - import reads _metadata.json without writing it, and export writes the'
			. ' database over your edited file.';
	}
}
