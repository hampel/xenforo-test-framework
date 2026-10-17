<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * assertAddOnTemplateModificationsApply() reads the add-on's enabled modifications and the apply
 * counts XenForo logged for them, so a modification whose find no longer matches is a failure
 * rather than silence.
 *
 * A committed test cannot name a real add-on, so the rows are inserted here under a transaction.
 */
class AddOnTemplateModificationsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const PROBE_ADDON = 'ZzProbe/Modifications';

	public function test_a_modification_that_applies_is_reported_with_its_count()
	{
		$this->givenTheProbeAddOnIsInstalled();
		$this->givenAModification('zzmod_applies', 3);

		$applied = $this->assertAddOnTemplateModificationsApply(self::PROBE_ADDON);

		$this->assertSame(['zzmod_applies' => 3], $applied);
	}

	public function test_a_modification_applying_zero_times_fails_and_names_it()
	{
		$this->givenTheProbeAddOnIsInstalled();
		$this->givenAModification('zzmod_stale', 0);

		try
		{
			$this->assertAddOnTemplateModificationsApply(self::PROBE_ADDON);
			$this->fail('a modification applying 0 times should have failed the assertion');
		}
		catch (ExpectationFailedException $e)
		{
			$reported = print_r($e->getComparisonFailure()->getActual(), true);

			$this->assertStringContainsString('zzmod_stale', $reported);
			$this->assertStringContainsString('no longer matches', $reported);
		}
	}

	public function test_an_add_on_part_way_through_an_install_is_not_refused()
	{
		// the measured difference from the class-extension assertion: XenForo gathers
		// modifications with whereAddOnActive() and no disableProcessing option, so a mid-install
		// add-on still has its modifications applied and there is nothing to refuse
		$this->givenTheProbeAddOnIsInstalled(['is_processing' => 1]);
		$this->givenAModification('zzmod_mid_install', 2);

		$applied = $this->assertAddOnTemplateModificationsApply(self::PROBE_ADDON);

		$this->assertSame(['zzmod_mid_install' => 2], $applied);
	}

	public function test_a_disabled_add_on_is_refused()
	{
		$this->givenTheProbeAddOnIsInstalled(['active' => 0]);
		$this->givenAModification('zzmod_disabled_addon', 5);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is installed but disabled');

		$this->assertAddOnTemplateModificationsApply(self::PROBE_ADDON);
	}

	public function test_an_id_that_matches_no_installed_add_on_says_so()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is not among the installed add-ons');

		$this->assertAddOnTemplateModificationsApply('ZzNobody/NotInstalled');
	}

	public function test_an_add_on_with_no_enabled_modification_is_refused()
	{
		$this->givenTheProbeAddOnIsInstalled();
		$this->givenAModification('zzmod_disabled', 4, false);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('registers no enabled template modification');

		$this->assertAddOnTemplateModificationsApply(self::PROBE_ADDON);
	}

	public function test_naming_no_add_on_without_isolation_is_refused()
	{
		// also the only test that reaches soleIsolatedAddOnId(), which lives in a sibling trait
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('whose template modifications to check');

		$this->assertAddOnTemplateModificationsApply();
	}

	/**
	 * @param array $state - overrides for the add-on's own state
	 *
	 * @return void
	 */
	private function givenTheProbeAddOnIsInstalled(array $state = [])
	{
		$this->app()->db()->insert('xf_addon', $state + [
			'addon_id' => self::PROBE_ADDON,
			'title' => 'Probe modifications',
			'version_string' => '1.0.0',
			'version_id' => 1000070,
			'active' => 1,
			'is_processing' => 0,
		], false, false);
	}

	/**
	 * Register a modification for the probe add-on and log the apply count XenForo would have.
	 *
	 * @param string $key
	 * @param int $applyCount
	 * @param bool $enabled
	 *
	 * @return void
	 */
	private function givenAModification($key, $applyCount, $enabled = true)
	{
		$db = $this->app()->db();

		$db->insert('xf_template_modification', [
			'addon_id' => self::PROBE_ADDON,
			'type' => 'public',
			'template' => 'zzprobe_template',
			'modification_key' => $key,
			'description' => 'probe',
			'execution_order' => 10,
			'enabled' => $enabled ? 1 : 0,
			'action' => 'str_replace',
			'find' => 'FIND',
			'replace' => 'REPLACE',
		]);

		$db->insert('xf_template_modification_log', [
			'template_id' => 1,
			'modification_id' => $db->lastInsertId(),
			'status' => 'ok',
			'apply_count' => $applyCount,
		], false, false);
	}
}
