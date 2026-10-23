<?php

namespace Hampel\Testing\Integration;

use PHPUnit\Framework\AssertionFailedError;
use XF\Db\DeadlockException;
use XF\Db\Exception as DbException;

/**
 * A database error that means another transaction held a row is rethrown with the cause named
 * and the original beneath it; any other failure passes through untouched. The hook is called
 * directly with a constructed error, since producing a real deadlock needs a second process.
 */
class LostRowAnnotationTest extends TestCase
{
	private function reportedFor(\Throwable $t): \Throwable
	{
		try
		{
			$this->onNotSuccessfulTest($t);
		}
		catch (\Throwable $reported)
		{
			return $reported;
		}
	}

	public function test_a_deadlock_is_rethrown_naming_the_cause_with_the_original_beneath()
	{
		$original = new DeadlockException('MySQL query error [1213]: Deadlock found when trying to get lock');

		$reported = $this->reportedFor($original);

		$this->assertInstanceOf(\RuntimeException::class, $reported);
		$this->assertStringContainsString("another add-on's suite", $reported->getMessage());
		$this->assertStringContainsString('UsesDatabaseTransactions in DOCS.md', $reported->getMessage());
		$this->assertStringContainsString('[1213]', $reported->getMessage());
		$this->assertSame($original, $reported->getPrevious());
	}

	public function test_a_row_changed_since_last_read_is_rethrown_the_same_way()
	{
		$original = new DbException("MySQL query error [1020]: Record has changed since last read in table 'xf_data_registry'");

		$reported = $this->reportedFor($original);

		$this->assertInstanceOf(\RuntimeException::class, $reported);
		$this->assertStringContainsString("'xf_data_registry'", $reported->getMessage());
		$this->assertSame($original, $reported->getPrevious());
	}

	public function test_any_other_database_error_passes_through_unchanged()
	{
		$original = new DbException('MySQL query error [1064]: You have an error in your SQL syntax');

		$this->assertSame($original, $this->reportedFor($original));
	}

	public function test_an_assertion_failure_passes_through_unchanged()
	{
		$original = new AssertionFailedError('deliberate');

		$this->assertSame($original, $this->reportedFor($original));
	}
}
