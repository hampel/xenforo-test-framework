<?php

namespace Hampel\Testing\Integration\Fixtures;

use Hampel\Testing\Integration\TestCase;
use XF\Db\DeadlockException;
use XF\Db\Exception as DbException;

/**
 * Run in its own process by LostRowNoteTest, because the note it exercises is written to the
 * run's output once PHPUnit has recorded the error, and only the output of a whole run shows
 * whether it arrived. Not named *Test.php, so the suite does not collect it.
 */
class LostRowProbe extends TestCase
{
	public function test_a_deadlock()
	{
		throw new DeadlockException('MySQL query error [1213]: Deadlock found when trying to get lock; try restarting transaction');
	}

	public function test_a_row_that_changed()
	{
		throw new DbException("MySQL query error [1020]: Record has changed since last read in table 'xf_data_registry'");
	}

	public function test_another_database_error()
	{
		throw new DbException('MySQL query error [1064]: You have an error in your SQL syntax');
	}

	public function test_a_plain_failure()
	{
		$this->fail('deliberate');
	}
}
