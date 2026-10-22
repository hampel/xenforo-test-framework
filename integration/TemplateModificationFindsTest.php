<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * assertAddOnTemplateModificationFindsMatch() reads the working copy's artifacts and the master
 * template text, so it fails before an import and tells zero matches from more than one - which
 * the log-reading assertions cannot, since they see only what the forum last compiled.
 *
 * The artifacts are fixtures under Fixtures/ModificationArtifacts/, one directory per probe
 * add-on, reached through the seam the assertion reads its directory from. The template they
 * modify is created here under a transaction, with text whose match counts are known.
 */
class TemplateModificationFindsTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * @var string which fixture directory the seam points at
	 */
	private $fixtureSet = 'Once';

	protected function addOnTemplateModificationArtifacts($addOnId)
	{
		return __DIR__ . '/Fixtures/ModificationArtifacts/' . $this->fixtureSet;
	}

	public function test_every_enabled_artifact_is_counted_as_xenforo_counts_it()
	{
		$this->givenTheTemplate();

		// str_replace by substr_count, preg_replace and callback by preg_match_all; the disabled
		// artifact would match twice and is skipped, and _metadata.json is not an artifact
		$this->assertSame(
			['cb' => 1, 'once' => 1, 'regex' => 1],
			$this->assertAddOnTemplateModificationFindsMatch('Probe/Fixtures')
		);
	}

	public function test_a_find_matching_more_or_fewer_times_fails_naming_each()
	{
		$this->givenTheTemplate();
		$this->fixtureSet = 'Twice';

		$reported = $this->reportFrom('Probe/Fixtures');

		$this->assertStringContainsString('double: its find matches public:zzfinds_template 2 times, not 1', $reported);
		$this->assertStringContainsString('absent: its find matches public:zzfinds_template 0 times, not 1', $reported);
		$this->assertStringNotContainsString('ok', $reported);
	}

	public function test_a_count_other_than_one_can_be_asserted()
	{
		$this->givenTheTemplate();
		$this->fixtureSet = 'Twice';

		$reported = $this->reportFrom('Probe/Fixtures', 2);

		$this->assertStringNotContainsString('double', $reported);
		$this->assertStringContainsString('ok: its find matches public:zzfinds_template 1 times, not 2', $reported);
	}

	public function test_a_template_the_forum_does_not_have_is_named()
	{
		$this->givenTheTemplate();
		$this->fixtureSet = 'Gone';

		$this->assertStringContainsString(
			'gone modifies public:zzfinds_no_such_template, which does not exist in this forum',
			$this->reportFrom('Probe/Fixtures')
		);
	}

	public function test_a_find_xenforo_could_not_apply_is_named_rather_than_counted()
	{
		$this->givenTheTemplate();
		$this->fixtureSet = 'BadRegex';

		$reported = $this->reportFrom('Probe/Fixtures');

		$this->assertStringContainsString("bad has a find XenForo cannot apply - its action is 'preg_replace'", $reported);
		$this->assertStringContainsString("unknown has a find XenForo cannot apply - its action is 'explode'", $reported);
	}

	public function test_a_json_file_that_is_not_an_artifact_is_named()
	{
		$this->givenTheTemplate();
		$this->fixtureSet = 'Stray';

		$this->assertStringContainsString(
			'stray is not a template modification artifact',
			$this->reportFrom('Probe/Fixtures')
		);
	}

	public function test_a_directory_with_no_artifact_is_refused()
	{
		$this->fixtureSet = 'Empty';

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('has no template modification artifact under');

		$this->assertAddOnTemplateModificationFindsMatch('Probe/Fixtures');
	}

	public function test_a_working_copy_without_the_directory_is_refused()
	{
		$this->fixtureSet = 'NoSuchSet';

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('has no _output/template_modifications/ directory');

		$this->assertAddOnTemplateModificationFindsMatch('Probe/Fixtures');
	}

	public function test_zero_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('A find that matches nowhere is a failure');

		$this->assertAddOnTemplateModificationFindsMatch('Probe/Fixtures', 0);
	}

	public function test_naming_no_add_on_without_isolation_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('whose template modification artifacts to check');

		$this->assertAddOnTemplateModificationFindsMatch();
	}

	/**
	 * The template every fixture modifies: BBB once, AAA twice, ZZZ never.
	 *
	 * @return void
	 */
	private function givenTheTemplate()
	{
		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => 'zzfinds_template',
			'template' => 'AAA BBB AAA',
			'addon_id' => '',
		]);
	}

	/**
	 * The list a failing run reports, which is the asserted value rather than the message.
	 *
	 * @param string $addOnId
	 * @param int $times
	 *
	 * @return string
	 */
	private function reportFrom($addOnId, $times = 1)
	{
		try
		{
			$this->assertAddOnTemplateModificationFindsMatch($addOnId, $times);
		}
		catch (ExpectationFailedException $e)
		{
			return print_r($e->getComparisonFailure()->getActual(), true);
		}

		$this->fail('the assertion was expected to fail and did not');
	}
}
