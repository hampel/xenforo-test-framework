<?php

namespace Hampel\Testing\Integration;

/**
 * The extension.neon this package ships types makeEntity() and createEntity() from the entity
 * they are asked for. PHPStan is run as a separate process over a fixture whose lines say what
 * they expect, so what is asserted is what a consumer's analysis reports.
 */
class PhpStanEntityHelpersTest extends TestCase
{
	private function analyse(): string
	{
		$phpstan = dirname(__DIR__) . '/vendor/bin/phpstan';
		$config = __DIR__ . '/Fixtures/PHPStan/entity-helpers.neon';

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
		$lines = file(__DIR__ . '/Fixtures/PHPStan/EntityHelpersFixture.php');
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

		$this->assertCount(6, $expectations, 'the fixture should carry six marked lines');

		// the run happened and the narrowed class reaches the report: a method the token does not
		// have is reported against XF\Entity\OAuthToken, not against Entity
		$this->assertStringContainsString('XF\Entity\OAuthToken::noSuchMethod()', $output);

		preg_match_all('#EntityHelpersFixture\.php:(\d+):#', $output, $m);
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
