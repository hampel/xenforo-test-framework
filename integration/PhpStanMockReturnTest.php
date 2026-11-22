<?php

namespace Hampel\Testing\Integration;

/**
 * What the mock helpers say they return is what a consumer's analysis sees. PHPStan is run as a
 * separate process over a fixture whose lines say what they expect: a mock kept in a
 * MockInterface property or handed to a parameter typed as the mocked class is clean, and using
 * the result of mockFactory() or mockService(), which return nothing, is reported.
 */
class PhpStanMockReturnTest extends TestCase
{
	private function analyse(): string
	{
		$phpstan = dirname(__DIR__) . '/vendor/bin/phpstan';
		$config = __DIR__ . '/Fixtures/PHPStan/mock-return.neon';

		return (string) shell_exec(
			'XF_ROOT=' . escapeshellarg(\XF::getRootDirectory())
			. ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpstan)
			. ' analyse -c ' . escapeshellarg($config)
			. ' --no-progress --error-format=raw 2>&1'
		);
	}

	/**
	 * @return array<int, string> line number => expectation, from the fixture's markers
	 */
	private function expectations(): array
	{
		$lines = file(__DIR__ . '/Fixtures/PHPStan/MockReturnFixture.php');
		$expectations = [];

		foreach ($lines AS $i => $line)
		{
			if (preg_match('#// expect: (clean|reported)#', $line, $m))
			{
				$expectations[$i + 1] = $m[1];
			}
		}

		return $expectations;
	}

	public function test_the_fixture_is_reported_where_it_says_and_nowhere_else()
	{
		$output = $this->analyse();
		$expectations = $this->expectations();

		$this->assertCount(13, $expectations, 'the fixture should carry thirteen marked lines');

		// the run happened and the mocked class reaches the report: a method the class does not
		// have is reported against the mock of XF\SimpleCache, not against object
		$this->assertStringContainsString('Mockery\MockInterface&XF\SimpleCache::noSuchMethod()', $output);
		$this->assertStringContainsString('mockService() (void) is used', $output);
		$this->assertStringContainsString('mockFactory() (void) is used', $output);

		preg_match_all('#MockReturnFixture\.php:(\d+):#', $output, $m);
		$reported = array_map('intval', $m[1]);

		foreach ($expectations AS $line => $expectation)
		{
			if ($expectation === 'reported')
			{
				$this->assertContains($line, $reported, "line $line should be reported:\n$output");
			}
			else
			{
				$this->assertNotContains($line, $reported, "line $line should be clean:\n$output");
			}
		}

		$this->assertSame(
			[],
			array_diff($reported, array_keys($expectations)),
			"only marked lines should be reported:\n$output"
		);
	}
}
