<?php namespace Hampel\Testing\Concerns;

use XF\Container;
use Hampel\Testing\XF\Mvc\Entity\Manager;

trait InteractsWithEntityManager
{
	protected $originalEm = null;

	protected function setUpEntityManager()
	{
		$app = $this->app();
		$this->originalEm = $app['em'];

		$this->swap('em', function (Container $c) {
			return new Manager($c['db'], $c['em.valueFormatter'], $c['extension']);
		});

        $this->beforeApplicationDestroyed(function () {
            $this->resetEntityManager();
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

	protected function resetEntityManager()
	{
		$this->swap('em', $this->originalEm);
		$this->originalEm = null;
	}
}
