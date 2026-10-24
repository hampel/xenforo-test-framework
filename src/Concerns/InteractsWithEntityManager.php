<?php

namespace Hampel\Testing\Concerns;

use Closure;
use Hampel\Testing\Mvc\Entity\Manager;
use Mockery\MockInterface;
use XF\Container;
use XF\Entity\Admin;
use XF\Entity\User;
use XF\Mvc\Entity\Entity;
use XF\Repository\UserRepository;

trait InteractsWithEntityManager
{
	protected function setUpEntityManager()
	{
		return $this->swap('em', function (Container $c)
		{
			return new Manager($c['db'], $c['em.valueFormatter'], $c['extension']);
		});
	}

	/**
	 * @param $identifier string - shortname for repository class being mocked
	 * @param (\Closure(MockInterface): mixed)|null $mock - (optional) mock closure to set expectations on
	 *
	 * @return MockInterface
	 * @throws \Exception
	 */
	protected function mockRepository($identifier, ?\Closure $mock = null)
	{
		$em = $this->app()->em();
		if ($em instanceof Manager)
		{
			return $em->mockRepository($identifier, $mock);
		}
		else
		{
			throw new \Exception('Unable to mock repository. Extended entity manager not set up.');
		}
	}

	/**
	 * @param string $shortName - the entity short name, eg 'XF:User', or the finder or entity class
	 * @param (\Closure(MockInterface): mixed)|null $mock - (optional) mock closure to set expectations on
	 *
	 * @return MockInterface
	 * @throws \Exception
	 */
	protected function mockFinder($shortName, ?\Closure $mock = null)
	{
		$em = $this->app()->em();
		if ($em instanceof Manager)
		{
			return $em->mockFinder($shortName, $mock);
		}
		else
		{
			throw new \Exception('Unable to mock finder. Extended entity manager not set up.');
		}
	}

	/**
	 * Mock what \XF::em()->find() will return for one id.
	 *
	 * find() resolves through a finder, so a mocked entity is never consulted - it stubs the calls
	 * find() makes on the finder instead. It also clears the entity cache for that type, since
	 * find() reads the cache before it builds a finder at all and would otherwise hand back an
	 * entity an earlier part of the test loaded.
	 *
	 * @param string $shortName - the entity short name, eg 'XF:User', or the entity or finder class
	 * @param mixed $id - the id find() will be called with
	 * @param Entity|null $entity - what find() should return; null for "no such record"
	 *
	 * @return MockInterface - the finder mock, for any further expectations
	 * @throws \Exception
	 */
	protected function mockFind($shortName, $id, ?Entity $entity = null)
	{
		$em = $this->app()->em();
		if (!($em instanceof Manager))
		{
			throw new \Exception('Unable to mock finder. Extended entity manager not set up.');
		}

		// clearEntityCache() takes a short name or an entity class, not a finder class
		$shortName = $em->finderShortName($shortName);
		$em->clearEntityCache($shortName);

		return $this->mockFinder($shortName, function ($finder) use ($id, $entity)
		{
			$finder->shouldReceive('whereId')->with($id)->andReturnSelf();
			$finder->shouldReceive('with')->andReturnSelf();
			$finder->shouldReceive('fetchOne')->andReturn($entity);
		});
	}

	/**
	 * @param string $shortName - the entity short name, eg 'XF:User'
	 * @param bool $inherit - set to true (default) to inherit from the mocked entity, or false to mock a standalone class
	 * @param (\Closure(MockInterface): mixed)|null $mock
	 *
	 * @return MockInterface
	 * @throws \Exception
	 */
	protected function mockEntity($shortName, $inherit = true, ?\Closure $mock = null)
	{
		$em = $this->app()->em();
		if ($em instanceof Manager)
		{
			return $em->mockEntity($shortName, $inherit, $mock);
		}
		else
		{
			throw new \Exception('Unable to mock entity. Extended entity manager not set up.');
		}
	}

	/**
	 * Build an entity without saving it.
	 *
	 * Useful for handing a populated entity to code under test without needing a database at
	 * all - the entity exists only in memory.
	 *
	 * @param string $shortName - eg 'XF:User'
	 * @param array $values - column => value
	 *
	 * @return Entity
	 */
	protected function makeEntity($shortName, array $values = [])
	{
		$entity = $this->app()->em()->create($shortName);

		if ($values)
		{
			$this->requireColumnsAreSettable($entity, $values, $shortName);
			$entity->bulkSet($values);
			$this->requireValuesWereSet($entity, $values, $shortName);
		}

		return $entity;
	}

	/**
	 * Refuse a value for a column an ordinary set cannot touch.
	 *
	 * XenForo throws out of bulkSet() for a read-only or auto-increment column, and its message
	 * names forceSet - an option this framework does not surface, and not the one to reach for.
	 * setTrusted() is how a test sets such a column, and wanting a fixture to carry a particular id
	 * is a legitimate reason to.
	 *
	 * Refused rather than set trusted automatically: read-only means something on columns that are
	 * not primary keys, so a test overriding one should say so rather than have it done quietly.
	 *
	 * @param Entity $entity
	 * @param array $values
	 * @param string $shortName
	 *
	 * @return void
	 */
	private function requireColumnsAreSettable(Entity $entity, array $values, $shortName)
	{
		$columns = $entity->structure()->columns;
		$refused = [];

		foreach (array_keys($values) AS $column)
		{
			if (!isset($columns[$column]))
			{
				// an unknown column is XenForo's to refuse, with a message that already says so
				continue;
			}

			if (!empty($columns[$column]['readOnly']) || !empty($columns[$column]['autoIncrement']))
			{
				$refused[] = $column;
			}
		}

		if (!$refused)
		{
			return;
		}

		$names = implode(' and ', array_map(function ($column)
		{
			return "'$column'";
		}, $refused));

		$calls = implode(' ', array_map(function ($column) use ($values)
		{
			return '$entity->setTrusted(' . var_export($column, true) . ', '
				. var_export($values[$column], true) . ');';
		}, $refused));

		$remaining = array_diff_key($values, array_fill_keys($refused, true));

		throw new \LogicException(
			"$shortName declares $names read-only or auto-increment, so an ordinary set cannot"
			. ' touch ' . (count($refused) === 1 ? 'it' : 'them') . '. Leave '
			. (count($refused) === 1 ? 'it' : 'them') . ' out of the values'
			. ($remaining ? ' - makeEntity() takes the rest as it is -' : '')
			. ' and set ' . (count($refused) === 1 ? 'it' : 'them') . ' on the entity afterwards: '
			. $calls
		);
	}

	/**
	 * Refuse an entity that did not take one of the values it was given.
	 *
	 * XenForo verifies some columns as they are set, and a value that fails is recorded as an error
	 * on the entity rather than thrown: a unique key already in use - XF:Option's option_id, say -
	 * leaves the column null. The test then works with an entity quietly missing the value it asked
	 * for, and fails somewhere else entirely.
	 *
	 * Only a value that did not land is refused. An entity carrying an error for some other reason
	 * is returned as it is, because building one deliberately invalid is a legitimate thing for a
	 * test to do.
	 *
	 * @param Entity $entity
	 * @param array $values - what was passed to bulkSet()
	 * @param string $shortName
	 *
	 * @return void
	 */
	private function requireValuesWereSet(Entity $entity, array $values, $shortName)
	{
		$errors = $entity->getErrors();

		foreach ($values AS $column => $value)
		{
			if ($value === null || $entity->get($column) !== null)
			{
				continue;
			}

			$reason = $errors[$column] ?? reset($errors);

			throw new \LogicException(
				"$shortName did not take the value given for '$column'"
				. ($reason ? ': ' . $reason : '')
				. '. XenForo verifies some columns as they are set and records a failure rather than'
				. ' throwing - a read-only column and a unique key already in use both do this.'
				. " Use setTrusted('$column', ...) to set it anyway."
			);
		}
	}

	/**
	 * Build an entity and save it.
	 *
	 * This writes to the database, so use it with UsesDatabaseTransactions unless you want the
	 * row to outlive the test.
	 *
	 * @param string $shortName
	 * @param array $values
	 *
	 * @return Entity
	 */
	protected function createEntity($shortName, array $values = [])
	{
		$entity = $this->makeEntity($shortName, $values);
		$entity->save();

		return $entity;
	}

	/**
	 * Create a member in the database that XenForo's own pages can display.
	 *
	 * `createEntity('XF:User', ...)` writes the `xf_user` row and nothing else, so the profile,
	 * option, privacy and auth records a user has in practice are absent - and a page that reads
	 * one of them, as the admin user editor does, fails on a null relation rather than on anything
	 * the test is about. XenForo's own user repository hydrates those relations, which is what this
	 * uses.
	 *
	 * The rows are real, so call it from a test class using UsesDatabaseTransactions.
	 *
	 * **Saving a user is also the most contended write this package makes**, and a transaction does
	 * not make it reliable: XenForo rewrites the user's group memberships, which locks the index gap
	 * above the last row, so two tests creating users against one database can deadlock. Against a
	 * forum nothing else writes to this never happens; against a shared development install it is
	 * intermittent, and the failure names `xf_user_group_relation` rather than anything here.
	 * UsesDatabaseTransactions in DOCS.md has the mechanism and what to do instead - where a test
	 * needs a visitor rather than a row, `actingAsMember()` writes nothing at all.
	 *
	 * **The account inherits the forum's `registrationDefaults` option**, because XenForo's own
	 * option and privacy entities read it while setting their defaults up - eight columns on the
	 * option record and five on the privacy one. So what a test can assert about a "normal" member
	 * follows the forum rather than the test; set what it needs on the record afterwards, or change
	 * the option before creating the account. DOCS.md has both.
	 *
	 * Not called `createMember`: three add-on suites define a method of that name already, and a
	 * trait method cannot be shadowed by an incompatible one without a fatal at class load.
	 *
	 * @param string|null $username - a unique one is generated when not given
	 * @param array $values - columns for the user, eg ['is_admin' => true, 'user_state' => 'valid']
	 *
	 * @return User
	 */
	protected function createUserAccount($username = null, array $values = [])
	{
		$repository = $this->app()->repository('XF:User');

		if (!($repository instanceof UserRepository))
		{
			throw new \LogicException(
				'Expected XF:User to resolve to a ' . UserRepository::class
				. ', got ' . get_class($repository)
			);
		}

		$user = $repository->setupBaseUser();
		$unique = $username ?: 'member' . substr(md5(uniqid('', true)), 0, 8);

		$this->requireColumnsAreSettable($user, $values, 'XF:User');

		$user->bulkSet($values + [
			'username' => $unique,
			'email' => $unique . '@example.com',
			'user_state' => 'valid',
		]);

		// without this the user has no auth handler at all, and anything reading it fails
		$user->Auth->setNoPassword();
		$user->save();

		return $user;
	}

	/**
	 * Create an administrator in the database: the administrator record XenForo reads admin
	 * permissions from, on a member XenForo's own save of that record marks is_admin.
	 *
	 * setVisitorAdminPermissions() and actingAsAdministrator() grant the visitor alone, in memory.
	 * Code that reads administrators from the database - a command listing who may do something,
	 * a page of administrators - needs rows, and this is the createUserAccount() plus
	 * `createEntity('XF:Admin')` pair as one call. The record is saved through XenForo's own
	 * entity, so the permission entries the control panel reads are written beside the cache.
	 *
	 * The rows are real, so call it from a test class using UsesDatabaseTransactions; the user
	 * save is the contended write createUserAccount() describes.
	 *
	 * A permission id this forum does not define is refused. The cache would answer true for it
	 * while no control panel could grant it, so a test would be passing on a permission that
	 * cannot exist.
	 *
	 * @param array $permissions - permission id => bool, as hasAdminPermission() reads them; a
	 *                             plain list of ids is accepted too
	 * @param array $adminValues - extra columns for the administrator record, eg
	 *                             ['is_super_admin' => true]
	 * @param array $userValues - columns for the user, eg ['username' => 'alice']
	 *
	 * @return User - with its Admin relation loaded
	 */
	protected function createAdministratorFixture(array $permissions = [], array $adminValues = [], array $userValues = [])
	{
		if (array_key_exists('is_admin', $userValues) && !$userValues['is_admin'])
		{
			throw new \LogicException(
				'An administrator has is_admin set: saving the administrator record sets it,'
				. ' so a value of false would not survive. A user without the record is a member,'
				. ' which createUserAccount() creates.'
			);
		}

		$this->requireAdminPermissionsExist($permissions);

		$username = $userValues['username'] ?? null;
		unset($userValues['username']);

		$user = $this->createUserAccount($username, $userValues);

		$admin = $this->makeEntity('XF:Admin', $adminValues + [
			'user_id' => $user->user_id,
			'permission_cache' => $permissions,
		]);

		if (!($admin instanceof Admin))
		{
			throw new \LogicException(
				'Expected XF:Admin to resolve to a ' . Admin::class . ', got ' . get_class($admin)
			);
		}

		$admin->save();
		$user->hydrateRelation('Admin', $admin);

		return $user;
	}

	/**
	 * Refuse a permission id this forum does not define, naming it.
	 *
	 * @param array $permissions - as createAdministratorFixture() takes them
	 *
	 * @return void
	 */
	private function requireAdminPermissionsExist(array $permissions)
	{
		$ids = [];
		foreach ($permissions AS $key => $value)
		{
			$ids[] = (is_int($key) && is_string($value)) ? $value : (string) $key;
		}

		$defined = $this->app()->db()->fetchAllColumn('SELECT admin_permission_id FROM xf_admin_permission');
		$unknown = array_diff($ids, $defined);

		if ($unknown)
		{
			throw new \LogicException(
				'This forum defines no admin permission ' . implode(', ', $unknown)
				. ' - the ids are the admin_permission_id values of xf_admin_permission, and an'
				. ' administrator granted one that is not there would pass hasAdminPermission()'
				. ' for a permission nothing can grant.'
			);
		}
	}
}
