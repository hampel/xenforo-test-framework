<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * assertAddOnClassExtensionsApply() reads the add-on's registered extensions from the database and
 * resolves each one, so that an extension which stops applying is a failure rather than silence.
 *
 * A committed test cannot name a real add-on, so the rows are inserted here under a transaction
 * and the extension is registered with addClassExtension(). That gives both states in one process:
 * a class registered and extended, and a class registered and not.
 */
class ClassExtensionsApplyTest extends TestCase
{
	use UsesDatabaseTransactions;

	private const PROBE_ADDON = 'ZzProbe/Extensions';

	public function test_an_extension_that_applies_is_reported_with_what_it_resolved_to()
	{
		$base = Fixtures\ExtendableThingJ::class;
		$extension = Fixtures\Ext\ExtendableThingJ::class;

		$this->givenTheProbeAddOnIsInstalled();
		$this->givenRegisteredExtension($base, $extension);
		$this->app()->extension()->addClassExtension($base, $extension);

		$applied = $this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);

		$this->assertSame([$base => $extension], $applied);

		// and it really is the extension running, not merely a name that matched
		$this->assertSame('extended base', (new $extension())->describe());
	}

	public function test_a_more_derived_resolution_still_counts_as_applied()
	{
		$base = Fixtures\ExtendableThingL::class;
		$ours = Fixtures\Ext\ExtendableThingL::class;
		$theirs = Fixtures\Ext2\ExtendableThingL::class;

		// what a second add-on extending the same class produces: the resolution is the most
		// derived class, and ours is an ancestor of it rather than the answer
		$this->givenTheProbeAddOnIsInstalled();
		$this->givenRegisteredExtension($base, $ours);
		$this->app()->extension()->addClassExtension($base, $ours);
		$this->app()->extension()->addClassExtension($base, $theirs);

		$applied = $this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);

		$this->assertSame([$base => $theirs], $applied);

		// both extensions really are in the chain, so counting this as applied is right
		$this->assertSame('second first base', (new $theirs())->describe());
	}

	public function test_an_extension_that_does_not_apply_fails_and_names_the_class()
	{
		$base = Fixtures\ExtendableThingK::class;
		$extension = 'ZzProbe\Extensions\XF\ExtendableThingK';

		// registered in the database and never added to this process, which is exactly the state
		// a renamed core class leaves an add-on in
		$this->givenTheProbeAddOnIsInstalled();
		$this->givenRegisteredExtension($base, $extension);

		try
		{
			$this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);
			$this->fail('an extension that does not apply should have failed the assertion');
		}
		catch (ExpectationFailedException $e)
		{
			$reported = print_r($e->getComparisonFailure()->getActual(), true);

			$this->assertStringContainsString($base, $reported);
			$this->assertStringContainsString($extension, $reported);
			$this->assertStringContainsString('never runs', $reported);
		}
	}

	public function test_an_id_that_matches_no_installed_add_on_says_so()
	{
		// the likelier of the two refusals, and saying 'registers no active class extension' of a
		// typo reads as though the add-on exists and happens to have none
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is not among the installed add-ons');

		$this->assertAddOnClassExtensionsApply('ZzNobody/NotInstalled');
	}

	public function test_a_disabled_add_on_is_refused_even_though_its_rows_are_active()
	{
		// the confusing case: XenForo's extension cache filters on the add-on's state as well as
		// the row's, so these rows are active and nothing resolves them. Read without that, the
		// assertion would report every one as a keying failure on an add-on that is switched off
		$this->givenTheProbeAddOnIsInstalled(['active' => 0]);
		$this->givenRegisteredExtension(Fixtures\ExtendableThingK::class, 'ZzProbe\Nope');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is installed but disabled');

		$this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);
	}

	public function test_an_add_on_part_way_through_an_install_is_refused()
	{
		// is_processing is the other half of whereAddOnActive(['disableProcessing' => true]), so a
		// suite run while something is mid-upgrade would otherwise see the same false failure
		$this->givenTheProbeAddOnIsInstalled(['is_processing' => 1]);
		$this->givenRegisteredExtension(Fixtures\ExtendableThingK::class, 'ZzProbe\Nope');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('part way through an install or upgrade');

		$this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);
	}

	public function test_an_installed_add_on_with_no_active_extension_is_refused_separately()
	{
		$this->givenTheProbeAddOnIsInstalled();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is installed and registers no active class extension');

		$this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);
	}

	public function test_an_inactive_extension_is_not_counted_as_registered()
	{
		$this->givenTheProbeAddOnIsInstalled();

		// the other way to reach the refusal: rows exist, none of them active
		$this->givenRegisteredExtension(Fixtures\ExtendableThingK::class, 'ZzProbe\Nope', false);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is installed and registers no active class extension');

		$this->assertAddOnClassExtensionsApply(self::PROBE_ADDON);
	}

	public function test_naming_no_add_on_without_isolation_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('whose class extensions to check');

		// this suite isolates None/None, so there is no single add-on to assume
		$this->assertAddOnClassExtensionsApply();
	}

	/**
	 * Install the probe add-on, so the refusal can tell a missing add-on from one with no
	 * extensions. Rolled back with everything else.
	 *
	 * @param array $state - overrides for the add-on's own state
	 *
	 * @return void
	 */
	private function givenTheProbeAddOnIsInstalled(array $state = [])
	{
		$this->app()->db()->insert('xf_addon', $state + [
			'addon_id' => self::PROBE_ADDON,
			'title' => 'Probe extensions',
			'version_string' => '1.0.0',
			'version_id' => 1000070,
			'active' => 1,
			'is_processing' => 0,
		], false, false);
	}

	/**
	 * Register a class extension in the database for the probe add-on, as an installed add-on has.
	 *
	 * @param string $from
	 * @param string $to
	 * @param bool $active
	 *
	 * @return void
	 */
	private function givenRegisteredExtension($from, $to, $active = true)
	{
		$this->app()->db()->insert('xf_class_extension', [
			'from_class' => $from,
			'to_class' => $to,
			'execute_order' => 10,
			'active' => $active ? 1 : 0,
			'addon_id' => self::PROBE_ADDON,
		]);
	}
}
