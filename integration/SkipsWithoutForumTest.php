<?php

namespace Hampel\Testing\Integration;

/**
 * Without a forum, every integration test skips and the run exits 0, which is what CLAUDE.md
 * says. A skip in setUp() happens before the application boots, so teardown runs with no XenForo
 * loaded, and anything it does that reaches a XenForo class turns every skip into an error. Only
 * a whole run in its own process can show that, since every test here has a forum.
 */
class SkipsWithoutForumTest extends TestCase
{
	/**
	 * @return array{0: string, 1: int} - the output and the exit code
	 */
	private function runProbe(string $xfRoot): array
	{
		$phpunit = dirname(__DIR__) . '/vendor/bin/phpunit';
		$config = __DIR__ . '/phpunit.xml';
		$probe = __DIR__ . '/Fixtures/NoForumProbe.php';

		$lines = [];
		$exitCode = 1;

		exec(
			'XF_ROOT=' . escapeshellarg($xfRoot)
			. ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($config)
			. ' ' . escapeshellarg($probe)
			. ' --do-not-cache-result --colors=never 2>&1',
			$lines,
			$exitCode
		);

		return [implode("\n", $lines), $exitCode];
	}

	private function assertEveryTestSkipped(string $output, int $exitCode): void
	{
		$this->assertStringContainsString('Skipped: 2', $output, 'both probe tests skipped');
		$this->assertStringNotContainsString('Error', $output, 'a skipped test was also reported as an error');
		$this->assertStringNotContainsString('XF\Extension', $output);
		$this->assertSame(0, $exitCode, 'a run that only skips exits 0');
	}

	public function test_with_no_root_every_test_skips_and_the_run_exits_0()
	{
		[$output, $exitCode] = $this->runProbe('');

		$this->assertEveryTestSkipped($output, $exitCode);
	}

	public function test_with_a_root_that_is_not_a_forum_every_test_skips_and_the_run_exits_0()
	{
		[$output, $exitCode] = $this->runProbe(sys_get_temp_dir());

		$this->assertEveryTestSkipped($output, $exitCode);
	}

	/**
	 * With a forum the probe's two tests run and pass, which is what says the two tests above are
	 * reading a run of the probe rather than of nothing.
	 */
	public function test_the_probe_runs_with_a_forum()
	{
		[$output, $exitCode] = $this->runProbe(\XF::getRootDirectory());

		$this->assertStringContainsString('OK (2 tests, 2 assertions)', $output);
		$this->assertSame(0, $exitCode);
	}
}
