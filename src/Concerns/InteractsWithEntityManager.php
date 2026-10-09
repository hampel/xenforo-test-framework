<?php

namespace Hampel\Testing\Concerns;

use Closure;
use Hampel\Testing\Mvc\Entity\Manager;
use Mockery;
use XF\Container;
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
	 * @param \Closure|null $mock - (optional) mock closure to set expectations on
	 *
	 * @return Mockery\MockInterface
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
	 * @param $shortName string - shortname for finder class being mocked
	 * @param \Closure|null $mock - (optional) mock closure to set expectations on
	 *
	 * @return Mockery\MockInterface
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
	 * @param string $shortName - the entity short name, eg 'XF:User'
	 * @param mixed $id - the id find() will be called with
	 * @param Entity|null $entity - what find() should return; null for "no such record"
	 *
	 * @return Mockery\MockInterface - the finder mock, for any further expectations
	 * @throws \Exception
	 */
	protected function mockFind($shortName, $id, ?Entity $entity = null)
	{
		$this->app()->em()->clearEntityCache($shortName);

		return $this->mockFinder($shortName, function ($finder) use ($id, $entity)
		{
			$finder->shouldReceive('whereId')->with($id)->andReturnSelf();
			$finder->shouldReceive('with')->andReturnSelf();
			$finder->shouldReceive('fetchOne')->andReturn($entity);
		});
	}

	/**
	 * @param $shortName string - shortname for finder class being mocked
	 * @param bool $inherit - set to true (default) to inherit from the mocked entity, or false to mock a standalone class
	 * @param \Closure|null $mock
	 *
	 * @return Mockery\MockInterface
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
			$entity->bulkSet($values);
			$this->requireValuesWereSet($entity, $values, $shortName);
		}

		return $entity;
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
}
