<?php

namespace Hampel\Testing\Integration;

use XF\Entity\User;

/**
 * actingAs() and the permission seeding, against a real XenForo application.
 *
 * test_a_ / test_b_ ordering is load-bearing: the second test is what proves the first one's
 * visitor was restored rather than leaking into the rest of the suite.
 */
class VisitorTest extends TestCase
{
	public function test_a_acting_as_a_member()
	{
		$user = $this->actingAsMember(['user_id' => 42, 'username' => 'Probe']);

		$this->assertSame(42, \XF::visitor()->user_id);
		$this->assertSame('Probe', \XF::visitor()->username);
		$this->assertSame('valid', \XF::visitor()->user_state);
		$this->assertSame($user, \XF::visitor());
	}

	public function test_b_the_visitor_was_restored()
	{
		$this->assertNotSame(42, \XF::visitor()->user_id);
	}

	public function test_acting_as_a_guest()
	{
		$this->actingAsGuest([], 'ProbeGuest');

		$this->assertSame(0, \XF::visitor()->user_id);
		$this->assertSame('ProbeGuest', \XF::visitor()->username);
	}

	/** granted permissions resolve, ungranted ones deny - with no database read */
	public function test_permissions_are_seeded_not_read()
	{
		$user = $this->actingAsMember([], [
			'forum' => ['view' => true, 'postThread' => true],
		]);

		$this->assertTrue($user->hasPermission('forum', 'view'));
		$this->assertTrue($user->hasPermission('forum', 'postThread'));
		$this->assertFalse($user->hasPermission('forum', 'deleteAnyPost'));
		$this->assertFalse($user->hasPermission('general', 'somethingElse'));

		// and through the visitor, which is what add-on code actually calls
		$this->assertTrue(\XF::visitor()->hasPermission('forum', 'view'));
	}

	public function test_content_permissions()
	{
		$user = $this->actingAsMember();

		// content permissions are flat - no permission group, unlike global ones
		$this->setVisitorContentPermissions($user, 'node', 7, ['view' => true]);

		$this->assertTrue($user->hasNodePermission(7, 'view'));
		$this->assertFalse($user->hasNodePermission(7, 'deleteAnyPost'));
		$this->assertFalse($user->hasNodePermission(8, 'view'));
	}

	public function test_acting_as_an_arbitrary_user_entity()
	{
		$user = $this->buildVisitor(['user_id' => 99, 'username' => 'Built']);
		$this->assertInstanceOf(User::class, $user);

		$this->actingAs($user);
		$this->assertSame(99, \XF::visitor()->user_id);
	}
}
