<?php

namespace Hampel\Testing\Integration;

use XF\Admin\App;
use XF\Pub\App as PubApp;

/**
 * setAppClassType(), and what dispatch() now puts in place for an add-on that only behaves as
 * itself on a public page.
 */
class AppClassTypeTest extends TestCase
{
	public function test_the_application_in_xf_is_one_of_that_type_and_shares_our_container()
	{
		$this->setAppClassType('public');

		$this->assertInstanceOf(PubApp::class, \XF::app());
		$this->assertSame($this->app()->container(), \XF::app()->container());
	}

	public function test_the_frameworks_own_application_is_unchanged()
	{
		$before = $this->app();

		$this->setAppClassType('public');

		$this->assertSame($before, $this->app(), 'app() should still be the booted test application');
		$this->assertNotSame($before, \XF::app());
	}

	public function test_the_setup_event_fires_with_it()
	{
		$seen = [];

		$this->app()->extension()->addListener('app_pub_setup', function ($app) use (&$seen)
		{
			$seen[] = $app;
		});

		$this->setAppClassType('public');

		$this->assertCount(1, $seen, 'app_pub_setup should have fired exactly once');
		$this->assertInstanceOf(PubApp::class, $seen[0]);
	}

	public function test_a_container_key_the_event_registers_is_available_afterwards()
	{
		$this->app()->extension()->addListener('app_pub_setup', function ($app)
		{
			$app->container()['probeKeyFromPubSetup'] = function ()
			{
				return 'registered';
			};
		});

		$this->setAppClassType('public');

		$this->assertSame('registered', $this->app()->container('probeKeyFromPubSetup'));
	}

	public function test_the_event_fires_only_once_per_test()
	{
		$fired = 0;

		$this->app()->extension()->addListener('app_pub_setup', function () use (&$fired)
		{
			$fired++;
		});

		$this->setAppClassType('public');
		$this->setAppClassType('public');
		$this->dispatch('help/terms');

		$this->assertSame(1, $fired);
	}

	public function test_a_listener_gated_on_the_public_app_runs_its_body_during_a_render()
	{
		$ran = false;

		$this->app()->extension()->addListener(
			'templater_global_data',
			function ($app, array &$data) use (&$ran)
			{
				if ($app instanceof PubApp)
				{
					$ran = true;
					$data['probeFromListener'] = 'yes';
				}
			}
		);

		$this->setAppClassType('public');
		$this->renderTemplate('public:login');

		$this->assertTrue($ran, 'the listener should see a public application');
	}

	public function test_an_admin_dispatch_installs_the_admin_application()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		$this->dispatch('tools', 'admin');

		$this->assertInstanceOf(App::class, \XF::app());
	}
}
