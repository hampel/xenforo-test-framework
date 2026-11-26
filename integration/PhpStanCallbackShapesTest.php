<?php

namespace Hampel\Testing\Integration;

/**
 * What a truth-test callback is given is what a consumer's analysis sees. PHPStan is run as a
 * separate process, at the level an untyped closure parameter would otherwise be mixed, over a
 * fixture whose lines say what they expect: reading the entry a helper passes is clean, and a
 * key or method the entry does not have is reported.
 */
class PhpStanCallbackShapesTest extends TestCase
{
	private function analyse(): string
	{
		$phpstan = dirname(__DIR__) . '/vendor/bin/phpstan';
		$config = __DIR__ . '/Fixtures/PHPStan/callback-shapes.neon';

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
		$lines = file(__DIR__ . '/Fixtures/PHPStan/CallbackShapesFixture.php');
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

		$this->assertCount(38, $expectations, 'the fixture should carry thirty-eight marked lines');

		// the run happened and each entry reaches the report under its own type
		$this->assertStringContainsString("Offset 'no_such_key' does not exist on array{execute_class: string", $output);
		$this->assertStringContainsString('Symfony\Component\Mime\Email::noSuchMethod()', $output);
		$this->assertStringContainsString('Psr\Http\Message\RequestInterface::noSuchMethod()', $output);

		preg_match_all('#CallbackShapesFixture\.php:(\d+):#', $output, $m);
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
