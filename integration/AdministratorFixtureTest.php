<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Entity\User;

/**
 * An administrator the database holds, for code that reads administrators rather than the visitor.
 */
class AdministratorFixtureTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_the_administrator_read_back_from_the_database_has_the_permissions_given_and_no_other()
	{
		$created = $this->createAdministratorFixture(['user' => true]);

		$this->assertDatabaseHas('xf_admin', ['user_id' => $created->user_id, 'is_super_admin' => 0]);
		$this->assertDatabaseHas('xf_admin_permission_entry', ['user_id' => $created->user_id, 'admin_permission_id' => 'user']);
		$this->assertDatabaseCount('xf_admin_permission_entry', 1, ['user_id' => $created->user_id]);

		$fresh = $this->freshUser($created);

		$this->assertTrue($fresh->is_admin);
		$this->assertTrue($fresh->hasAdminPermission('user'));
		$this->assertFalse($fresh->hasAdminPermission('option'));
	}

	public function test_a_super_administrator_has_a_permission_it_was_not_given()
	{
		$created = $this->createAdministratorFixture([], ['is_super_admin' => true]);

		$this->assertDatabaseHas('xf_admin', ['user_id' => $created->user_id, 'is_super_admin' => 1]);
		$this->assertTrue($this->freshUser($created)->hasAdminPermission('option'));
	}

	public function test_a_plain_list_of_permission_ids_is_accepted()
	{
		$created = $this->createAdministratorFixture(['user', 'option']);

		$fresh = $this->freshUser($created);

		$this->assertTrue($fresh->hasAdminPermission('user'));
		$this->assertTrue($fresh->hasAdminPermission('option'));
		$this->assertFalse($fresh->hasAdminPermission('style'));
	}

	public function test_a_permission_this_forum_does_not_define_is_refused_before_anything_is_written()
	{
		$username = 'zzadmin' . substr(md5(uniqid('', true)), 0, 8);

		try
		{
			$this->createAdministratorFixture(['user' => true, 'zz_no_such_permission' => true], [], ['username' => $username]);
			$this->fail('an undefined permission was accepted');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('zz_no_such_permission', $e->getMessage());
			$this->assertStringContainsString('xf_admin_permission', $e->getMessage());
		}

		$this->assertDatabaseMissing('xf_user', ['username' => $username]);
	}

	public function test_a_fixture_with_is_admin_false_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('createUserAccount()');

		$this->createAdministratorFixture(['user' => true], [], ['is_admin' => false]);
	}

	public function test_the_username_and_record_columns_given_are_used()
	{
		$created = $this->createAdministratorFixture(['user' => true], ['extra_user_group_ids' => [3]], ['username' => 'zzadminalice']);

		$this->assertSame('zzadminalice', $created->username);
		$this->assertDatabaseHas('xf_user', ['user_id' => $created->user_id, 'username' => 'zzadminalice', 'is_admin' => 1]);
		$this->assertDatabaseHas('xf_admin', ['user_id' => $created->user_id, 'extra_user_group_ids' => '3']);
	}

	public function test_the_fixture_is_not_the_visitor()
	{
		$visitorBefore = \XF::visitor()->user_id;

		$created = $this->createAdministratorFixture(['user' => true]);

		$this->assertSame($visitorBefore, \XF::visitor()->user_id);
		$this->assertNotSame($created->user_id, \XF::visitor()->user_id);
	}

	/**
	 * The user as code reading the database sees it, rather than the instance the fixture returned.
	 */
	private function freshUser(User $created): User
	{
		$this->app()->em()->clearEntityCache();
		$fresh = $this->app()->em()->find('XF:User', $created->user_id);
		$this->assertInstanceOf(User::class, $fresh);

		return $fresh;
	}
}
