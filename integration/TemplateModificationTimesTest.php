<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * assertTemplateModificationAppliedTimes() and assertAddOnTemplateModificationsApplyTimes() check
 * the apply count per log row, not summed: XenForo logs a row per template a modification was
 * applied to, one per style holding its own copy, so a sum counts styles as well as matches.
 *
 * A committed test cannot name a real add-on, so the rows are inserted here under a transaction,
 * with real template ids so a failure names the template.
 */
class TemplateModificationTimesTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const PROBE_ADDON = 'ZzProbe/Times';

	public function test_the_count_is_asserted_per_row_not_summed()
	{
		// two styles each holding the template, the modification applied once in each: a sum
		// would read 2 and fail an assertion of 1
		$this->givenAModification('zzt_two_rows', [1, 1]);

		$this->assertTemplateModificationAppliedTimes('zzt_two_rows', 1);
	}

	public function test_a_row_matching_more_than_once_fails_naming_the_template()
	{
		$this->givenAModification('zzt_twice', [2]);

		// the plain assertion passes on it, which is the gap this assertion is for
		$this->assertTemplateModificationApplied('zzt_twice');

		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationAppliedTimes('zzt_twice', 1);
		});

		$this->assertStringContainsString('zzt_twice', $message);
		$this->assertStringContainsString('applied 2 times in public:_ads (style 0), not 1', $message);
	}

	public function test_a_row_matching_fewer_times_than_expected_fails()
	{
		$this->givenAModification('zzt_once', [1]);

		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationAppliedTimes('zzt_once', 2);
		});

		$this->assertStringContainsString('applied 1 times in public:_ads (style 0), not 2', $message);
	}

	public function test_a_modification_with_no_log_row_fails_as_applied_zero_times()
	{
		$this->givenAModification('zzt_unlogged', []);

		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationAppliedTimes('zzt_unlogged', 1);
		});

		$this->assertStringContainsString('applied 0 times', $message);
	}

	public function test_a_row_whose_template_is_gone_is_named_by_id()
	{
		$this->givenAModification('zzt_orphan', [3], [999999999]);

		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationAppliedTimes('zzt_orphan', 1);
		});

		$this->assertStringContainsString('applied 3 times in template id 999999999, not 1', $message);
	}

	public function test_a_supplied_message_prefixes_the_failure()
	{
		$this->givenAModification('zzt_noted', [2]);

		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationAppliedTimes('zzt_noted', 1, 'zzmy own note');
		});

		$this->assertStringStartsWith('zzmy own note', $message);
	}

	public function test_a_key_that_matches_no_modification_fails()
	{
		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationAppliedTimes('zzt_no_such_modification', 1);
		});

		$this->assertStringContainsString("No template modification with the key 'zzt_no_such_modification'", $message);
	}

	public function test_zero_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('positive integer - 0 given');

		$this->assertTemplateModificationAppliedTimes('zzt_anything', 0);
	}

	public function test_a_non_integer_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("positive integer - '1' given");

		$this->assertTemplateModificationAppliedTimes('zzt_anything', '1');
	}

	public function test_every_modification_of_the_add_on_is_checked_per_row()
	{
		$this->givenAModification('zzt_addon_a', [1, 1]);
		$this->givenAModification('zzt_addon_b', [1, 1]);

		$applied = $this->assertAddOnTemplateModificationsApplyTimes(1, self::PROBE_ADDON);

		$this->assertSame(['zzt_addon_a' => 2, 'zzt_addon_b' => 2], $applied);
	}

	public function test_the_add_on_assertion_names_only_the_modification_that_is_off()
	{
		$this->givenAModification('zzt_addon_ok', [1, 1]);
		$this->givenAModification('zzt_addon_double', [1, 2]);

		// the plain assertion passes on both, which is the gap this assertion is for
		$this->assertAddOnTemplateModificationsApply(self::PROBE_ADDON);

		try
		{
			$this->assertAddOnTemplateModificationsApplyTimes(1, self::PROBE_ADDON);
			$this->fail('a row applied twice should have failed the assertion');
		}
		catch (ExpectationFailedException $e)
		{
			$reported = print_r($e->getComparisonFailure()->getActual(), true);

			$this->assertStringContainsString('zzt_addon_double applied 2 times in', $reported);
			$this->assertStringNotContainsString('zzt_addon_ok', $reported);
		}
	}

	public function test_the_add_on_assertion_keeps_the_refusals()
	{
		// the three refusals live in a helper the plain assertion shares; one is enough to show
		// this assertion reaches it
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is not among the installed add-ons');

		$this->assertAddOnTemplateModificationsApplyTimes(1, 'ZzNobody/NotInstalled');
	}

	public function test_the_add_on_assertion_refuses_zero_before_reading_anything()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('positive integer - 0 given');

		$this->assertAddOnTemplateModificationsApplyTimes(0, 'ZzNobody/NotInstalled');
	}

	public function test_a_map_gives_each_modification_its_own_count()
	{
		$this->givenAModification('zzt_addon_once', [1, 1]);
		$this->givenAModification('zzt_addon_twice', [2, 2]);

		$applied = $this->assertAddOnTemplateModificationsApplyTimes(
			['zzt_addon_once' => 1, 'zzt_addon_twice' => 2],
			self::PROBE_ADDON
		);

		$this->assertSame(['zzt_addon_once' => 2, 'zzt_addon_twice' => 4], $applied);
	}

	public function test_a_map_entry_that_is_off_fails_naming_that_modification_and_its_own_count()
	{
		$this->givenAModification('zzt_addon_once', [1, 1]);
		$this->givenAModification('zzt_addon_twice', [2, 1]);

		try
		{
			$this->assertAddOnTemplateModificationsApplyTimes(
				['zzt_addon_once' => 1, 'zzt_addon_twice' => 2],
				self::PROBE_ADDON
			);
			$this->fail('a row applied once where two were expected should have failed');
		}
		catch (ExpectationFailedException $e)
		{
			$reported = print_r($e->getComparisonFailure()->getActual(), true);

			$this->assertStringContainsString('zzt_addon_twice applied 1 times in', $reported);
			$this->assertStringContainsString(', not 2', $reported);
			$this->assertStringNotContainsString('zzt_addon_once', $reported);
		}
	}

	public function test_a_map_missing_a_registered_modification_is_refused()
	{
		$this->givenAModification('zzt_addon_a', [1]);
		$this->givenAModification('zzt_addon_b', [1]);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('no count for zzt_addon_b');

		$this->assertAddOnTemplateModificationsApplyTimes(['zzt_addon_a' => 1], self::PROBE_ADDON);
	}

	public function test_a_map_naming_a_modification_the_add_on_does_not_have_is_refused()
	{
		$this->givenAModification('zzt_addon_a', [1]);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('a count for zzt_addon_gone, which it does not have');

		$this->assertAddOnTemplateModificationsApplyTimes(
			['zzt_addon_a' => 1, 'zzt_addon_gone' => 1],
			self::PROBE_ADDON
		);
	}

	public function test_a_map_may_say_a_modification_is_on_a_template_the_forum_does_not_have()
	{
		$this->givenAModification('zzt_addon_a', [1, 1]);
		// the probe template is in no forum, so this one has no log row and applies nowhere
		$this->givenAModification('zzt_addon_gallery', []);

		$applied = $this->assertAddOnTemplateModificationsApplyTimes(
			['zzt_addon_a' => 1, 'zzt_addon_gallery' => 'absent'],
			self::PROBE_ADDON
		);

		$this->assertSame(['zzt_addon_a' => 2, 'zzt_addon_gallery' => 0], $applied);
	}

	public function test_a_modification_given_as_absent_whose_template_exists_fails_naming_it()
	{
		$this->givenAModification('zzt_addon_a', [1]);
		$this->givenAModification('zzt_addon_real', [], null, '_ads');

		try
		{
			$this->assertAddOnTemplateModificationsApplyTimes(
				['zzt_addon_a' => 1, 'zzt_addon_real' => 'absent'],
				self::PROBE_ADDON
			);
			$this->fail('a template the forum has should have failed the absent entry');
		}
		catch (ExpectationFailedException $e)
		{
			$reported = print_r($e->getComparisonFailure()->getActual(), true);

			$this->assertStringContainsString(
				"zzt_addon_real is given as 'absent', but public:_ads exists in this forum, so it needs a count",
				$reported
			);
			$this->assertStringNotContainsString('zzt_addon_a', $reported);
			$this->assertStringContainsString('zzt_addon_a 1, zzt_addon_real absent', $e->getMessage());
		}
	}

	public function test_absent_is_a_map_value_not_a_count()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("positive integer - 'absent' given");

		$this->assertAddOnTemplateModificationsApplyTimes('absent', 'ZzNobody/NotInstalled');
	}

	public function test_a_map_with_a_count_below_one_is_refused_before_reading_anything()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("'zzt_x' => 0 given");

		$this->assertAddOnTemplateModificationsApplyTimes(['zzt_x' => 0], 'ZzNobody/NotInstalled');
	}

	/**
	 * Register a modification for the probe add-on with one log row per apply count given.
	 *
	 * @param string $key
	 * @param int[] $applyCounts - one per log row; an empty list logs nothing
	 * @param int[]|null $templateIds - defaults to the forum's lowest template ids
	 * @param string $template - the public template it modifies; the default is in no forum
	 *
	 * @return void
	 */
	private function givenAModification($key, array $applyCounts, ?array $templateIds = null, $template = 'zzprobe_template')
	{
		$db = $this->app()->db();

		if (!$db->fetchOne('SELECT 1 FROM xf_addon WHERE addon_id = ?', self::PROBE_ADDON))
		{
			$db->insert('xf_addon', [
				'addon_id' => self::PROBE_ADDON,
				'title' => 'Probe apply counts',
				'version_string' => '1.0.0',
				'version_id' => 1000070,
				'active' => 1,
				'is_processing' => 0,
			], false, false);
		}

		$db->insert('xf_template_modification', [
			'addon_id' => self::PROBE_ADDON,
			'type' => 'public',
			'template' => $template,
			'modification_key' => $key,
			'description' => 'probe',
			'execution_order' => 10,
			'enabled' => 1,
			'action' => 'str_replace',
			'find' => 'FIND',
			'replace' => 'REPLACE',
		]);
		$modificationId = $db->lastInsertId();

		$templateIds = $templateIds ?? $db->fetchAllColumn(
			'SELECT template_id FROM xf_template ORDER BY template_id LIMIT ?',
			max(count($applyCounts), 1)
		);

		foreach ($applyCounts AS $i => $applyCount)
		{
			$db->insert('xf_template_modification_log', [
				'template_id' => $templateIds[$i],
				'modification_id' => $modificationId,
				'status' => 'ok',
				'apply_count' => $applyCount,
			], false, false);
		}
	}

	/**
	 * @return string the failure message of an assertion expected to fail
	 */
	private function messageFrom(\Closure $assertion)
	{
		try
		{
			$assertion();
		}
		catch (ExpectationFailedException|AssertionFailedError $e)
		{
			return $e->getMessage();
		}

		$this->fail('the assertion was expected to fail and did not');
	}
}
