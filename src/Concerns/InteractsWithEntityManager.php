<?php

namespace Hampel\Testing\Concerns;

use Closure;
use Hampel\Testing\Mvc\Entity\Manager;
use Mockery;
use XF\Container;
use XF\Mvc\Entity\Entity;

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
		}

		return $entity;
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
}
