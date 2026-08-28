<?php namespace Hampel\Testing\Concerns;

use Closure;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert as PHPUnit;
use \XF\Db\AbstractAdapter;

trait InteractsWithDatabase
{
	/**
	 * Mock the database class
	 *
	 * @param Closure|null $mock
	 *
	 * @return mixed
	 */
	protected function mockDatabase(?Closure $mock = null)
	{
		$db = $this->mock('db', AbstractAdapter::class, $mock);
		// need to set up the entity manager again, so we get the mocked database
		$this->setUpEntityManager();
		return $db;
	}

	/**
	 * Assert that a table contains at least one row matching the given column values.
	 *
	 * @param string $table - table name, without the xf_ prefix being assumed - pass it in full
	 * @param array $criteria - column => value pairs, combined with AND
	 *
	 * @return void
	 */
	protected function assertDatabaseHas($table, array $criteria)
	{
		$count = $this->countDatabaseRows($table, $criteria);

		PHPUnit::assertGreaterThan(
			0,
			$count,
			"Failed asserting that the table [{$table}] contains a row matching "
				. $this->describeCriteria($criteria) . "."
		);
	}

	/**
	 * Assert that a table contains no row matching the given column values.
	 *
	 * @param string $table
	 * @param array $criteria - column => value pairs, combined with AND
	 *
	 * @return void
	 */
	protected function assertDatabaseMissing($table, array $criteria)
	{
		$count = $this->countDatabaseRows($table, $criteria);

		PHPUnit::assertSame(
			0,
			$count,
			"Failed asserting that the table [{$table}] contains no row matching "
				. $this->describeCriteria($criteria) . " - found {$count}."
		);
	}

	/**
	 * Assert that a table contains exactly the given number of rows, optionally restricted to
	 * those matching the given column values.
	 *
	 * @param string $table
	 * @param int $expected
	 * @param array $criteria - column => value pairs, combined with AND
	 *
	 * @return void
	 */
	protected function assertDatabaseCount($table, $expected, array $criteria = [])
	{
		$count = $this->countDatabaseRows($table, $criteria);

		$matching = $criteria ? ' matching ' . $this->describeCriteria($criteria) : '';

		PHPUnit::assertSame(
			$expected,
			$count,
			"Failed asserting that the table [{$table}] contains {$expected} row(s){$matching} - found {$count}."
		);
	}

	/**
	 * @param string $table
	 * @param array $criteria
	 *
	 * @return int
	 */
	private function countDatabaseRows($table, array $criteria)
	{
		$db = $this->app()->db();

		if ($db instanceof MockInterface)
		{
			throw new \LogicException(
				'The database assertions read the real database and cannot be used with mockDatabase().'
			);
		}

		$where = [];
		$params = [];

		foreach ($criteria AS $column => $value)
		{
			// column names come from the test, but a typo should fail loudly rather than
			// reaching the server as something else
			if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column))
			{
				throw new \InvalidArgumentException("Invalid column name '{$column}'");
			}

			if ($value === null)
			{
				$where[] = "`{$column}` IS NULL";
			}
			else
			{
				$where[] = "`{$column}` = ?";
				$params[] = $value;
			}
		}

		$sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`';

		if ($where)
		{
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		return (int) $db->fetchOne($sql, $params);
	}

	/**
	 * @param array $criteria
	 *
	 * @return string
	 */
	private function describeCriteria(array $criteria)
	{
		return json_encode($criteria, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
