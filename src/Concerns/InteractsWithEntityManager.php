<?php namespace Hampel\Testing\Concerns;

use Mockery;
use Closure;
use XF\Container;
use Hampel\Testing\Mvc\Entity\Manager;

trait InteractsWithEntityManager
{
	protected function setUpEntityManager()
	{
		return $this->swap('em', function (Container $c) {
			return new Manager($c['db'], $c['em.valueFormatter'], $c['extension']);
		});
	}

	/**
	 * @param $identifier string - shortname for repository class being mocked
	 * @param Closure|null $mock - (optional) mock closure to set expectations on
	 *
	 * @return Mockery\MockInterface
	 * @throws \Exception
	 */
	protected function mockRepository($identifier, Closure $mock = null)
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
	 * @param Closure|null $mock - (optional) mock closure to set expectations on
	 *
	 * @return Mockery\MockInterface
	 * @throws \Exception
	 */
	protected function mockFinder($shortName, Closure $mock = null)
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
	 * @param Closure|null $mock
	 *
	 * @return Mockery\MockInterface
	 * @throws \Exception
	 */
	protected function mockEntity($shortName, $inherit = true, Closure $mock = null)
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
}
