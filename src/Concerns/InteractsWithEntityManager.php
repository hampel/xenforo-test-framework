<?php namespace Hampel\Testing\Concerns;

use XF\Container;
use Hampel\Testing\XF\Mvc\Entity\Manager;

trait InteractsWithEntityManager
{
	protected static function setUpManager()
	{
		self::_swap('em', function (Container $c) {
			return new Manager($c['db'], $c['em.valueFormatter'], $c['extension']);
		});
	}

	protected function mockRepository($identifier)
	{
		return self::app()->em()->mockRepository($identifier);
	}
}
