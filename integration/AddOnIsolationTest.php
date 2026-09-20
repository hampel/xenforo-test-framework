<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Extension;
use Hampel\Testing\Mvc\Entity\Manager;

/**
 * Add-on isolation, and the half of it that did not work until 5.0.0.
 *
 * Filtering `addon.composer` was never enough: `XF\App::setup()` fires `app_setup` as its last
 * step, and the filtered extension used to be installed afterwards, from setUpTraits(). So every
 * installed add-on's `app_setup` listener ran before any test did, out of a suite that had named
 * an add-on id matching nothing.
 *
 * Control, run 2026-09-20: comment out the installExtension() call in App::setup() and
 * test_no_excluded_add_on_listener_ran_during_boot fails, listing all 13 listener classes this
 * forum's add-ons register - XenForo's own XFMG and XFRM among them. Nothing else in the suite
 * changes, so no other test depended on the leak.
 */
class AddOnIsolationTest extends TestCase
{
	public function test_no_excluded_add_on_listener_ran_during_boot()
	{
		$listeners = $this->appSetupListeners();

		if (!$listeners)
		{
			$this->markTestSkipped(
				'No add-on on this forum has an app_setup listener, so nothing here could leak. '
				. 'Skipped rather than passed, because a pass would mean nothing.'
			);
		}

		$loaded = [];

		foreach ($listeners AS $listener)
		{
			// no autoload: the question is whether booting already pulled the class in
			if (class_exists($listener['callback_class'], false))
			{
				$loaded[] = $listener['callback_class'];
			}
		}

		$this->assertSame(
			[],
			$loaded,
			'$addonsToLoad names an id that matches nothing, so no add-on listener class should '
				. 'have been loaded by the boot'
		);
	}

	public function test_the_container_holds_our_extension_so_events_can_be_faked()
	{
		// fakesEvents() refuses unless the container holds ours rather than XenForo's. Nothing
		// asserted that anywhere until now, and note this one passes with or without the boot
		// install - it guards the precondition, it does not prove where the install happened
		$this->assertInstanceOf(Extension::class, $this->app()->extension());
	}

	public function test_the_filter_keeps_the_add_ons_it_is_given()
	{
		$listeners = $this->appSetupListeners();

		if (!$listeners)
		{
			$this->markTestSkipped('No add-on on this forum has an app_setup listener.');
		}

		$listener = reset($listeners);

		$extension = Extension::forAddOns([$listener['addon_id']], $this->app()->db());

		$this->assertContains(
			[$listener['callback_class'], $listener['callback_method']],
			$this->listenersOf($extension)['app_setup']['_'],
			'the add-on that was asked for should keep its listener'
		);
	}

	public function test_the_filter_excludes_everything_for_an_id_matching_nothing()
	{
		$extension = Extension::forAddOns(['None/None'], $this->app()->db());

		$this->assertSame([], $this->listenersOf($extension));
	}

	public function test_the_filter_keeps_the_class_extensions_of_the_add_ons_it_is_given()
	{
		$extension = $this->anAddOnWithAClassExtension();

		if (!$extension)
		{
			$this->markTestSkipped('No add-on on this forum extends a class.');
		}

		$filtered = Extension::forAddOns([$extension['addon_id']], $this->app()->db());

		$this->assertContains(
			$extension['to_class'],
			$this->classExtensionsOf($filtered)[$extension['from_class']]
		);
	}

	public function test_the_filter_excludes_class_extensions_for_an_id_matching_nothing()
	{
		// the listener half is what app_setup needed, but a leaked class extension is the older
		// half of isolation and wants its own assertion rather than being assumed
		$extension = Extension::forAddOns(['None/None'], $this->app()->db());

		$this->assertSame([], $this->classExtensionsOf($extension));
	}

	public function test_the_application_records_what_it_was_given()
	{
		$this->assertSame(['None/None'], $this->app()->isolatedAddOnIds());
	}

	public function test_a_half_upgraded_scaffold_is_refused()
	{
		// $addonsToLoad set in tests/TestCase.php, nothing passed to setupApp() - the v2.1.0
		// upgrade taken half way, silent on every version before 5.0.0
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('no add-on isolation is in effect');

		$this->requireAddOnIsolationApplied(['MyVendor/MyAddon'], []);
	}

	public function test_the_refusal_names_the_add_ons_that_went_missing()
	{
		try
		{
			$this->requireAddOnIsolationApplied(['MyVendor/MyAddon', 'XFMG'], []);
			$this->fail('a half-upgraded scaffold should have been refused');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('MyVendor/MyAddon, XFMG', $e->getMessage());
			$this->assertStringContainsString('tests/CreatesApplication.php', $e->getMessage());
		}
	}

	public function test_isolation_that_reached_the_application_is_accepted()
	{
		$this->requireAddOnIsolationApplied(['MyVendor/MyAddon'], ['MyVendor/MyAddon']);

		$this->assertTrue(true, 'no exception');
	}

	public function test_a_deliberately_different_list_is_left_alone()
	{
		// a suite that computes its own list and boots with it is not the half-upgrade, so the
		// guard stays out of the way rather than insisting the two agree
		$this->requireAddOnIsolationApplied(['MyVendor/MyAddon'], ['Someone/Else']);

		$this->assertTrue(true, 'no exception');
	}

	public function test_wanting_no_isolation_is_not_a_half_upgrade()
	{
		$this->requireAddOnIsolationApplied([], []);

		$this->assertTrue(true, 'no exception');
	}

	/**
	 * @return array
	 */
	private function appSetupListeners()
	{
		return $this->app()->db()->fetchAll("
			SELECT listener.addon_id, listener.callback_class, listener.callback_method
			FROM xf_code_event_listener AS listener
			LEFT JOIN xf_addon AS addon ON (listener.addon_id = addon.addon_id)
			WHERE listener.event_id = 'app_setup'
				AND listener.active = 1
				AND addon.active = 1
				AND addon.is_processing = 0
			ORDER BY listener.addon_id
		");
	}

	public function test_a_mocked_database_no_longer_re_runs_the_listener_query()
	{
		// this lives here because the cause is boot-time resolution rather than anything about
		// mockDatabase(). Until 5.0.0 the filtered extension was installed during test setup as
		// an unresolved closure, so mockDatabase() - which rebuilds the entity manager, which
		// reads $c['extension'] - ran that closure's listener query through the mock. DOCS told
		// consumers their fetchAll had to return an array rather than null because of it. The
		// extension is resolved while the application boots now, so the rebuilt manager reads
		// the resolved instance and a mock with no expectations at all is fine
		$this->mockDatabase();

		$this->assertInstanceOf(Manager::class, $this->app()->em());
	}

	/**
	 * @return array|null
	 */
	private function anAddOnWithAClassExtension()
	{
		return $this->app()->db()->fetchRow("
			SELECT extension.addon_id, extension.from_class, extension.to_class
			FROM xf_class_extension AS extension
			LEFT JOIN xf_addon AS addon ON (extension.addon_id = addon.addon_id)
			WHERE extension.active = 1
				AND addon.active = 1
				AND addon.is_processing = 0
			LIMIT 1
		");
	}

	/**
	 * @param Extension $extension
	 *
	 * @return array
	 */
	private function classExtensionsOf(Extension $extension)
	{
		return $this->readProperty($extension, 'classExtensions');
	}

	/**
	 * @param Extension $extension
	 *
	 * @return array
	 */
	private function listenersOf(Extension $extension)
	{
		return $this->readProperty($extension, 'listeners');
	}

	/**
	 * @param Extension $extension
	 * @param string $name
	 *
	 * @return array
	 */
	private function readProperty(Extension $extension, $name)
	{
		$property = new \ReflectionProperty(\XF\Extension::class, $name);
		$property->setAccessible(true);

		return $property->getValue($extension);
	}
}
