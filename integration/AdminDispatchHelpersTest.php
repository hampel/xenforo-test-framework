<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Entity\User;

/**
 * The router an admin dispatch builds links with, and the two fixtures an admin test opens with.
 */
class AdminDispatchHelpersTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_a_link_a_controller_builds_during_an_admin_dispatch_is_an_admin_link()
	{
		$this->fakesRegistry();
		$this->actingAsAdministrator(['notice' => true]);

		$reply = $this->dispatch('notices/save', 'admin', [
			'title' => 'router probe ' . uniqid(),
			'message' => 'probe',
			'notice_type' => 'block',
			'display_style' => 'primary',
		], [], 'POST');

		// the controller redirects to a link it built itself, with no type given
		$this->assertReplyIsRedirect($reply);
		$this->assertStringStartsWith('/admin.php?notices', $reply->getUrl());
	}

	public function test_a_public_dispatch_still_builds_public_links()
	{
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

		$this->dispatch('help/terms');

		$this->assertStringStartsWith('/index.php?help', $this->app()->router()->buildLink('help/terms'));
	}

	public function test_an_admin_link_does_not_depend_on_the_friendly_url_option()
	{
		$before = $this->app()->router('admin')->buildLink('notices');

		$this->setOption('useFriendlyUrls', true);

		// the formatters are cached container entries and each router holds one by value
		foreach (['router.public.formatter', 'router.api.formatter', 'router.public', 'router.api', 'router.admin', 'router'] AS $key)
		{
			$this->app()->container()->decache($key);
		}

		$this->assertSame(
			$before,
			$this->app()->router('admin')->buildLink('notices'),
			'the admin formatter does not read useFriendlyUrls'
		);

		// while a public one does change shape, which is why a test should not assert a literal
		$this->assertSame('/help/terms', $this->app()->router('public')->buildLink('help/terms'));
	}

	public function test_acting_as_administrator_grants_both_kinds_of_permission()
	{
		$admin = $this->actingAsAdministrator(['notice' => true], [], ['general' => ['view' => true]]);

		$this->assertTrue($admin->is_admin);
		$this->assertTrue($admin->hasAdminPermission('notice'));
		$this->assertTrue($admin->hasPermission('general', 'view'));
		$this->assertFalse($admin->hasAdminPermission('option'), 'only what was granted');
	}

	public function test_a_created_user_account_has_the_relations_xenforo_pages_read()
	{
		$user = $this->createUserAccount('probebob');

		$this->assertInstanceOf(User::class, $user);
		$this->assertSame('probebob', $user->username);
		$this->assertDatabaseHas('xf_user_profile', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_user_option', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_user_privacy', ['user_id' => $user->user_id]);
		$this->assertDatabaseHas('xf_user_authenticate', ['user_id' => $user->user_id]);
	}

	public function test_a_created_user_account_renders_through_a_xenforo_page()
	{
		$this->fakesErrors();
		$user = $this->createUserAccount();
		$this->actingAsAdministrator(['user' => true]);

		// a bare XF:User row fails this render on a null relation
		$html = $this->renderReply($this->dispatch("users/{$user->user_id}/edit", 'admin'));

		$this->assertSee($html, $user->username);
	}
}
