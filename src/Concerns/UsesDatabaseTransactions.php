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
			$lost = $this->transactionWasCommitted($db);

			if ($db->inTransaction())
			{
				$db->rollbackAll();
			}

			if ($lost)
			{
				PHPUnit::fail(
					'Something committed this test\'s transaction, so everything it wrote before '
					. 'that point is now permanent in the database. The usual cause is a statement '
					. 'MySQL commits implicitly - any DDL, including the TRUNCATE that XenForo runs '
					. 'when a template is compiled. On a development install that compile happens '
					. 'whenever a rendered template\'s _output/ file does not match the hash in '
					. '_metadata.json, which is the state an edit leaves it in. Load a page that '
					. 'renders the template, outside any test: the watcher re-imports it from the '
					. 'file and writes that hash, after which the compile stops happening. Neither '
					. 'xf-dev:import nor xf-dev:export is the answer - import reads _metadata.json '
					. 'without writing it, and export writes the database over your edited file.'
				);
			}
		});
	}

	/**
	 * Ask the server whether the transaction is still open.
	 *
	 * The adapter cannot answer this: an implicit commit happens inside the server, so its own
	 * flag still says a transaction is open and rollbackAll() then rolls back nothing.
	 *
	 * @param AbstractAdapter $db
	 *
	 * @return bool - true when the transaction this test opened is gone
	 */
	private function transactionWasCommitted(AbstractAdapter $db)
	{
		if (!$db->inTransaction())
		{
			// the test rolled back or committed deliberately, and knows what it did
			return false;
		}

		try
		{
			// MariaDB
			return !$db->fetchOne('SELECT @@in_transaction');
		}
		catch (DbException $e)
		{
		}

		try
		{
			// MySQL has no such variable
			return !$db->fetchOne(
				'SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = CONNECTION_ID()'
			);
		}
		catch (DbException $e)
		{
			// no answer available, so do not invent one
			return false;
		}
	}
}
