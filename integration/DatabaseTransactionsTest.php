<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * The transaction wrapper and the database assertions, against a real MySQL connection.
 *
 * test_a_ and test_b_ prefixes are load-bearing: PHPUnit runs them in declaration order, and the
 * second test is what proves the first one's writes were rolled back.
 */
class DatabaseTransactionsTest extends TestCase
{
	use UsesDatabaseTransactions;

	public const KEY = '__auditProbeTransaction';

	public function test_a_writes_are_visible_inside_the_test()
	{
		// the wrapper really is active - without it this is autocommit
		$this->assertTrue($this->app()->db()->inTransaction());

		$this->assertDatabaseMissing('xf_data_registry', ['data_key' => self::KEY]);

		$this->app()->db()->insert('xf_data_registry', [
			'data_key' => self::KEY,
			'data_value' => 's:1:"x";',
		]);

		$this->assertDatabaseHas('xf_data_registry', ['data_key' => self::KEY]);
		$this->assertDatabaseCount('xf_data_registry', 1, ['data_key' => self::KEY]);
	}

	/** if the rollback did not happen, the row from the previous test is still here */
	public function test_b_the_previous_test_was_rolled_back()
	{
		$this->assertDatabaseMissing('xf_data_registry', ['data_key' => self::KEY]);
	}

	/** XenForo's own transactions nest as savepoints, so an inner commit must not escape */
	public function test_c_a_nested_commit_does_not_escape()
	{
		$db = $this->app()->db();

		$db->beginTransaction();
		$db->insert('xf_data_registry', ['data_key' => self::KEY, 'data_value' => 's:1:"y";']);
		$db->commit();

		$this->assertDatabaseHas('xf_data_registry', ['data_key' => self::KEY]);
	}

	public function test_d_the_nested_commit_was_rolled_back_too()
	{
		$this->assertDatabaseMissing('xf_data_registry', ['data_key' => self::KEY]);
	}

	public function test_assertions_read_real_rows()
	{
		$this->assertDatabaseHas('xf_user', ['user_id' => 1]);
		$this->assertDatabaseMissing('xf_user', ['user_id' => 999999999]);
		$this->assertDatabaseCount('xf_user', 0, ['user_id' => 999999999]);
	}
}
