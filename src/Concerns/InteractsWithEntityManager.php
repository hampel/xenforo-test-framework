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
