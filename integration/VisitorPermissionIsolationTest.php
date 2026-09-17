<?php

namespace Hampel\Testing\Integration;

/**
 * Every user built by buildVisitor() used to land on permission combination id 1 - the forum's
 * real guest combination. Two consequences, both of which make a permission test lie: granting a
 * permission to one built user granted it to every other one, and a user nothing had been granted
 * for silently inherited whatever the development forum happens to grant guests.
 */
class VisitorPermissionIsolationTest extends TestCase
{
	public function test_built_users_do_not_share_a_permission_combination()
	{
		$alice = $this->actingAsMember(['user_id' => 11, 'username' => 'Alice']);
		$bob = $this->actingAsMember(['user_id' => 12, 'username' => 'Bob']);

		$this->assertNotSame(
			$alice->permission_combination_id,
			$bob->permission_combination_id
		);

		$this->setVisitorPermissions($alice, ['general' => ['somethingGranted' => true]]);

		$this->assertTrue($alice->hasPermission('general', 'somethingGranted'));
		$this->assertFalse($bob->hasPermission('general', 'somethingGranted'));
	}

	public function test_content_permissions_do_not_leak_between_built_users()
	{
		$alice = $this->actingAsMember(['user_id' => 13]);
		$bob = $this->actingAsMember(['user_id' => 14]);

		$this->setVisitorContentPermissions($alice, 'node', 1, ['view' => true]);

		$this->assertTrue($alice->hasNodePermission(1, 'view'));
		$this->assertFalse($bob->hasNodePermission(1, 'view'));
	}

	/**
	 * Admin is the one relation XenForo's guest user does not pre-hydrate, so it lazy-loaded by
	 * user_id and found whatever administrator record the forum has at that id - and
	 * actingAsMember() defaults to user 1, which is usually the owner.
	 */
	public function test_a_built_admin_does_not_inherit_the_forums_admin_record()
	{
		$userId = 1;

		if (!$this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_admin WHERE user_id = ?', $userId))
		{
			$this->markTestSkipped("this forum has no xf_admin row for user $userId, so it cannot show the leak");
		}

		$admin = $this->actingAsMember(['user_id' => $userId, 'is_admin' => true]);

		$this->assertNull($admin->Admin);
		$this->assertFalse($admin->hasAdminPermission('option'));
	}

	/** and the fix must not reach a real user the test loaded for itself */
	public function test_a_real_user_keeps_its_own_admin_record()
	{
		$user = $this->app()->em()->find('XF:User', 1);

		if (!$user || !$user->Admin)
		{
			$this->markTestSkipped('this forum has no administrator at user_id 1');
		}

		$this->actingAs($user);

		$this->assertNotNull(\XF::visitor()->Admin);
	}

	public function test_a_permission_that_was_never_granted_is_denied()
	{
		// 'view' is granted to guests on most forums, so on combination id 1 this returned true
		// without the test ever granting anything
		$member = $this->actingAsMember(['user_id' => 15]);

		$this->assertFalse($member->hasPermission('general', 'view'));
	}

	public function test_the_forums_own_guest_permissions_are_still_reachable_on_request()
	{
		$guest = $this->actingAsGuest();
		$this->assertFalse($guest->hasPermission('general', 'view'));

		$realGuest = $this->buildVisitor(['permission_combination_id' => 1]);
		$this->assertSame(1, $realGuest->permission_combination_id);
	}

	public function test_a_user_who_is_not_valid_falls_back_to_the_guest_combination()
	{
		// XenForo behaviour, preserved: User::getPermissionCombinationId() ignores the stored id
		// for any user_state other than 'valid'
		$moderated = $this->buildVisitor(['user_id' => 16, 'user_state' => 'moderated']);

		$this->assertSame(1, $moderated->permission_combination_id);
	}
}
