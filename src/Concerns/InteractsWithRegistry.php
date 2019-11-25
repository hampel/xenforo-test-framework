<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\DataRegistry;
use \XF\Db\AbstractAdapter;

trait InteractsWithRegistry
{
	protected function fakesRegistry($preLoadData = true)
	{
		$this->swap('registry', function ($c) {
			// turn off registry data caching when testing!
			$registry = new DataRegistry($c['db'], null);
			$registry->setFakeMode();
			return $registry;
		});

		if ($preLoadData)
		{
			$this->app()->preLoadData();
		}
	}
}
