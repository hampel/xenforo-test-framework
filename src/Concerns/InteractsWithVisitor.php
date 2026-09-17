<?php

namespace Hampel\Testing\Concerns;

use XF\Entity\Admin;
use XF\Entity\User;
use XF\Repository\UserRepository;

trait InteractsWithVisitor
{
	/**
	 * Built visitors get their own permission combination id from here, counting up. The base is
	 * far above any id a real forum will have in xf_permission_combination, so a combination
	 * nothing has granted resolves to no permissions at all rather than to a real row.
	 */
	private const PERMISSION_COMBINATION_BASE = 1000000;

	/** @var User|null */
	private $originalVisitor;

	/** @var bool */
	private $visitorRemembered = false;

	/** @var int */
	private $builtVisitorCount = 0;

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
	 * Each built user gets its own permission combination id, so that permissions granted to one
	 * do not apply to another, and so that a user nothing has been granted for has no permissions
	 * rather than the forum's real guest permissions. Pass permission_combination_id in $values to
	 * opt out - `['permission_combination_id' => 1]` reads whatever the forum grants guests.
	 *
	 * Note XenForo ignores the id for any user whose user_state is not 'valid': User::
	 * getPermissionCombinationId() returns the guest combination for those, so a user built as
	 * 'moderated' shares permissions with guests exactly as it would in production.
	 *
	 * @param array $values
	 * @param string|null $username
	 *
	 * @return User
	 */
	/**
	 * Grant admin permissions to a built user, by giving it the administrator record that
	 * buildVisitor() deliberately withholds.
	 *
	 * XenForo reads admin permissions from the user's Admin relation, not from the permission
	 * combination that setVisitorPermissions() writes, so this is a separate helper. Pass
	 * ['is_super_admin' => true] in $values for a super administrator, who has every permission
	 * regardless of what is granted here.
	 *
	 * @param User $user - must already have is_admin set
	 * @param array $permissions - permission id => bool, as XenForo caches them
	 * @param array $values - optional extra columns for the administrator record
	 *
	 * @return Admin - the record now hydrated onto the user
	 */
	protected function setVisitorAdminPermissions(User $user, array $permissions, array $values = [])
	{
		if (!$user->is_admin)
		{
			throw new \LogicException(
				'Admin permissions only apply to a user with is_admin set, because'
				. " User::hasAdminPermission() checks that first - build it with"
				. " actingAsMember(['is_admin' => true])."
			);
		}

		$admin = $this->makeEntity('XF:Admin', $values + ['permission_cache' => $permissions]);

		if (!($admin instanceof Admin))
		{
			throw new \LogicException(
				'Expected XF:Admin to resolve to a ' . Admin::class . ', got ' . get_class($admin)
			);
		}

		// user_id is the primary key, and assigning one normally sends the entity to the finder
		// for a uniqueness check against a database the test may not have
		$admin->setTrusted('user_id', $user->user_id);

		$user->hydrateRelation('Admin', $admin);

		return $admin;
	}

	protected function buildVisitor(array $values = [], $username = null)
	{
		$values += [
			'permission_combination_id' => self::PERMISSION_COMBINATION_BASE + $this->builtVisitorCount++,
		];

		$manipulator = function (array $data) use ($values)
		{
			return array_replace($data, $values);
		};

		$repository = $this->app()->repository('XF:User');

		if (!($repository instanceof UserRepository))
		{
			throw new \LogicException(
				'Expected XF:User to resolve to a ' . UserRepository::class . ', got '
				. get_class($repository)
			);
		}

		$user = $repository->getGuestUser($username, $manipulator);

		// XenForo pre-hydrates Option, Profile and Privacy from its guest defaults but not Admin, so
		// that relation would lazy-load by user_id and find whatever administrator record the forum
		// has at that id - user 1 on most forums. With is_admin set, hasAdminPermission() then
		// answers from the forum rather than from the test. Hydrating it keeps a built user
		// self-contained, the same reason each one gets its own permission combination id.
		$user->hydrateRelation('Admin', null);

		return $user;
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
