<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\SimpleCache;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;

trait InteractsWithSimpleCache
{
	/**
	 * Allow us to assert that keys/value exist (or do not exist) in the SimpleCache as a result of executing our test
	 * code, without side-effects (ie no changes are actually made to the cache).
	 */
	protected function fakesSimpleCache()
	{
		return $this->swap('simpleCache', function () {
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

	protected function assertSimpleCacheHasNot($addOnId, $key)
	{
		PHPUnit::assertFalse(
            $this->getSimpleCache()->keyExists($addOnId, $key),
            "The [{$key}] key exists."
        );
	}

	protected function assertSimpleCacheEquals($expected, $addOnId, $key)
	{
		PHPUnit::assertEquals(
			$expected,
            $this->getSimpleCache()->getValue($addOnId, $key)
        );
	}

	// backwards compatibility for spelling error!
	protected function assertSimpleCacheEqual($expected, $addOnId, $key)
	{
		$this->assertSimpleCacheEquals($expected, $addOnId, $key);
	}

	protected function assertSimpleCacheNotEquals($expected, $addOnId, $key)
	{
		PHPUnit::assertNotEquals(
			$expected,
            $this->getSimpleCache()->getValue($addOnId, $key)
        );
	}

	// backwards compatibility for spelling error!
	protected function assertSimpleCacheNotEqual($expected, $addOnId, $key)
	{
		$this->assertSimpleCacheNotEquals($expected, $addOnId, $key);
	}
}
