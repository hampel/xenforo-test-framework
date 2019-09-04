<?php namespace Hampel\Testing\Concerns;

use Closure;
use XF\Container;
use Hampel\Testing\XF\Mvc\Entity\Manager;

trait InteractsWithEntityManager
{
	protected function setUpEntityManager()
	{
		$this->swap('em', function (Container $c) {
			return new Manager($c['db'], $c['em.valueFormatter'], $c['extension']);
		});
	}

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
