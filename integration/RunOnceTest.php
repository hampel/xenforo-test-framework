<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * XenForo defers work with \XF::runOnce(), and a real request drains the queue in
 * Dispatcher::dispatchLoop(). dispatch() resolves reroutes itself rather than going through that
 * loop, so it has to drain the queue too - and \XF::$runOnce is a static nothing else clears, so
 * anything left in it would reach the next test still bound to the previous test's app.
 *
 * Reported by the NativeAds add-on, whose advert cache rebuild is queued from an entity postSave.
 */
class RunOnceTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function queued(): array
	{
		$reflection = new \ReflectionClass(\XF::class);
		$property = $reflection->getProperty('runOnce');
		$property->setAccessible(true);

		return array_keys($property->getValue());
	}

	public function test_a_a_dispatch_drains_the_queue()
	{
		$ran = false;
		\XF::runOnce('testDeferredWork', function () use (&$ran)
		{
			$ran = true;
		});

		$this->assertSame(['testDeferredWork'], $this->queued());

		$this->dispatch('no-such-route-xyz');

		$this->assertTrue($ran, 'deferred work queued before the dispatch should have run');
		$this->assertSame([], $this->queued());
	}

	public function test_b_anything_left_queued_does_not_reach_the_next_test()
	{
		$this->assertSame([], $this->queued(), 'the queue should start empty');

		// left deliberately: teardown discards it rather than running it
		\XF::runOnce('leakedWork', function ()
		{
			throw new \LogicException('deferred work must not run after the test that queued it');
		});
	}

	public function test_c_the_queue_is_empty_again()
	{
		$this->assertSame([], $this->queued());
	}
}
