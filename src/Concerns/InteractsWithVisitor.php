<?php

namespace Hampel\Testing\Concerns;

use XF\Entity\User;

trait InteractsWithVisitor
{
	/** @var User|null */
	private $originalVisitor;

	/** @var bool */
	private $visitorRemembered = false;

	protected function setUpVisitor()
	{
		$this->beforeApplicationDestroyed(function ()
		{
			$this->restoreVisitor();
		});
	}

	/**
	 * Run the rest of the test as the given user, optionally granting global permissions.
	 *
	 * The visitor is restored after each test.
	 *
	 * @param User $user
	 * @param array $permissions - group => [permission => value], as XenForo caches them
	 *
	 * @return User - the user now acting
	 */
	protected function actingAs(User $user, array $permissions = [])
	{
		$this->rememberVisitor();

		if ($permissions)
		{
			$this->setVisitorPermissions($user, $permissions);
		}

		\XF::setVisitor($user);

		return $user;
	}

	/**
	 * Act as a guest - no user_id, guest permission combination.
	 *
	 * The user is built in memory and never written to the database.
	 *
	 * @param array $permissions
	 * @param string|null $username
	 *
	 * @return User
	 */
	protected function actingAsGuest(array $permissions = [], $username = null)
	{
		return $this->actingAs($this->buildVisitor([], $username), $permissions);
	}

	/**
	 * Act as a logged-in member without touching the database.
	 *
	 * Defaults to user_id 1 and a valid user state, so code guarding on $visitor->user_id
	 * behaves as it would for a real member. Pass $values to override any column.
	 *
	 * @param array $values - column => value overrides, eg ['user_id' => 5, 'is_admin' => true]
	 * @param array $permissions
	 *
	 * @return User
	 */
	protected function actingAsMember(array $values = [], array $permissions = [])
	{
		$values += [
			'user_id' => 1,
			'username' => 'TestMember',
			'user_state' => 'valid',
			'user_group_id' => User::GROUP_REG,
		];

		return $this->actingAs($this->buildVisitor($values), $permissions);
	}

	/**
	 * Grant global permissions for a user, without reading the permission cache from the
	 * database. Anything not granted is denied, as it would be in production.
	 *
	 * @param User $user
	 * @param array $permissions - group => [permission => value]
	 *
	 * @return void
	 */
	protected function setVisitorPermissions(User $user, array $permissions)
	{
		$this->app()->permissionCache()->setGlobalPerms(
			$user->permission_combination_id,
			$permissions
		);
	}

	/**
	 * Grant content permissions - node permissions and the like - for a user.
	 *
	 * Note these are NOT grouped the way global permissions are: XenForo stores content
	 * permissions flat, as permission => value, with no permission group above them.
	 *
	 * @param User $user
	 * @param string $contentType - eg 'node'
	 * @param int $contentId
	 * @param array $permissions - permission => value, ungrouped
	 *
	 * @return void
	 */
	protected function setVisitorContentPermissions(User $user, $contentType, $contentId, array $permissions)
	{
		$this->app()->permissionCache()->setContentPerms(
			$user->permission_combination_id,
			$contentType,
			$contentId,
			$permissions
		);
	}

	/**
	 * Build a User entity in memory. XenForo's guest user is the only user it will construct
	 * without a database row, so members are built from it with the columns overridden.
	 *
	 * @param array $values
	 * @param string|null $username
	 *
	 * @return User
	 */
	protected function buildVisitor(array $values = [], $username = null)
	{
		$manipulator = $values
			? function (array $data) use ($values)
			{
				return array_replace($data, $values);
			}
		: null;

		return $this->app()->repository('XF:User')->getGuestUser($username, $manipulator);
	}

	private function rememberVisitor()
	{
		if (!$this->visitorRemembered)
		{
			$this->originalVisitor = \XF::visitor();
			$this->visitorRemembered = true;
		}
	}

	private function restoreVisitor()
	{
		if ($this->visitorRemembered)
		{
			\XF::setVisitor($this->originalVisitor);
			$this->originalVisitor = null;
			$this->visitorRemembered = false;
		}
	}
}
