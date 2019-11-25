<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\SimpleCache;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;

trait InteractsWithSimpleCache
{
	protected function fakesSimpleCache()
	{
		$this->swap('simpleCache', function () {
			return new SimpleCache([]);
		});
	}

	/**
	 * @return SimpleCache
	 * @throws \Exception
	 */
	protected function getSimpleCache()
	{
		$simpleCache = $this->app['simpleCache'];
		if (!($simpleCache instanceof SimpleCache))
		{
			throw new \Exception("Test simpleCache not set up - call fakesSimpleCache() first");
		}
		return $simpleCache;
	}

	protected function assertSimpleCacheHas($addOnId, $key)
	{
		PHPUnit::assertTrue(
            $this->getSimpleCache()->keyExists($addOnId, $key),
            "The expected [{$key}] key does not exist."
        );
	}

	protected function assertSimpleCacheEqual($expected, $addOnId, $key)
	{
		PHPUnit::assertEquals(
			$expected,
            $this->getSimpleCache()->getValue($addOnId, $key)
        );
	}

	protected function assertSimpleCacheNotEqual($expected, $addOnId, $key)
	{
		PHPUnit::assertNotEquals(
			$expected,
            $this->getSimpleCache()->getValue($addOnId, $key)
        );
	}
}
