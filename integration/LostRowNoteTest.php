<?php

namespace Hampel\Testing\Integration;

/**
 * A database error that means another transaction held a row is followed in the run's output by
 * a line naming the cause; any other failure is not. PHPUnit records an error before teardown
 * runs and prints what it recorded, so the only place a test case can put the cause is beside it,
 * and the only way to see that it arrived is to read a whole run's output.
 */
class LostRowNoteTest extends TestCase
{
	private const NOTE = "another add-on's suite";

	private function runProbe($test): string
	{
		$phpunit = dirname(__DIR__) . '/vendor/bin/phpunit';
		$config = __DIR__ . '/phpunit.xml';
		$probe = __DIR__ . '/Fixtures/LostRowProbe.php';

		return (string) shell_exec(
			'XF_ROOT=' . escapeshellarg(\XF::getRootDirectory())
			. ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($config)
			. ' ' . escapeshellarg($probe)
			. ' --filter ' . escapeshellarg($test)
			. ' --do-not-cache-result 2>&1'
		);
	}

	public function test_a_deadlock_is_followed_by_the_cause_and_still_reported_as_itself()
	{
		$output = $this->runProbe('test_a_deadlock');

		$this->assertStringContainsString('LostRowProbe::test_a_deadlock: another transaction', $output);
		$this->assertStringContainsString(self::NOTE, $output);
		$this->assertStringContainsString('UsesDatabaseTransactions in DOCS.md', $output);
		$this->assertStringContainsString('XF\Db\DeadlockException: MySQL query error [1213]', $output, 'the original error is still what PHPUnit reports');
		$this->assertStringContainsString('Errors: 1', $output);
	}

	public function test_a_row_that_changed_is_followed_by_the_cause()
	{
		$output = $this->runProbe('test_a_row_that_changed');

		$this->assertStringContainsString(self::NOTE, $output);
		$this->assertStringContainsString("'xf_data_registry'", $output, 'the table MySQL named is still reported');
	}

	public function test_another_database_error_is_not()
	{
		$output = $this->runProbe('test_another_database_error');

		$this->assertStringContainsString('[1064]', $output, 'the run happened and reported the error');
		$this->assertStringNotContainsString(self::NOTE, $output);
	}

	public function test_a_plain_failure_is_not()
	{
		$output = $this->runProbe('test_a_plain_failure');

		$this->assertStringContainsString('deliberate', $output, 'the run happened and reported the failure');
		$this->assertStringNotContainsString(self::NOTE, $output);
	}
}
