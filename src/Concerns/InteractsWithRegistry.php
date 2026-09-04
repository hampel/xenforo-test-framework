<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\DataRegistry;

trait InteractsWithRegistry
{
	/**
	 * Disables database and cache updates for registry changes - all updates are written to memory only, so no
	 * side-effects when writing to the registry.
	 *
	 * @param bool $preLoadData - set to false to disable pre-loading of registry data
	 *
	 * @return DataRegistry
	 */
	protected function fakesRegistry($preLoadData = true)
	{
		$this->swap('registry', function ($c)
		{
			// turn off registry data caching when testing!
			$registry = new DataRegistry($c['db'], null);
			$registry->setFakeMode();
			return $registry;
		});

		if ($preLoadData)
		{
			$this->app()->preLoadData();
		}

		// resolve out of the container - swap() hands back the closure, not the instance
		$registry = $this->app()->registry();

		if (!($registry instanceof DataRegistry))
		{
			throw new \LogicException(
				'Expected the registry to be a ' . DataRegistry::class . ', got '
				. get_class($registry)
			);
		}

		return $registry;
	}
}
