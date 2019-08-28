<?php namespace Hampel\Testing\Concerns;

use XF\Container;
use Hampel\Testing\XF\Mvc\Entity\Manager;

trait InteractsWithEntityManager
{
	protected function setUpEntityManager()
	{
		$app = $this->app();

		$this->swap('em', function (Container $c) {
			return new Manager($c['db'], $c['em.valueFormatter'], $c['extension']);
		});
	}

	protected function mockRepository($identifier)
	{
		$em = $this->app()->em();
		if ($em instanceof Manager)
		{
			return $this->app()->em()->mockRepository($identifier);
		}
		else
		{
			throw new \Exception('Unable to mock repository. Extended entity manager not set up.');
		}
	}
}
