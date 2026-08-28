<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\Extension;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithEvents
{
	/**
	 * Record code events fired via the extension, and stop them reaching any listener - so we
	 * can assert that our code fired an event, without the side effects of whatever is
	 * listening for it.
	 *
	 * Class extensions are unaffected: only listeners are suppressed.
	 *
	 * @return Extension
	 *
	 * @throws \Exception
	 */
	protected function fakesEvents()
	{
		$extension = $this->getEventExtension();
		$extension->setFakeEventMode();

		return $extension;
	}

	/**
	 * @return Extension
	 *
	 * @throws \Exception
	 */
	protected function getEventExtension()
	{
		$extension = $this->app()->extension();

		if (!($extension instanceof Extension))
		{
			throw new \Exception(
				'Cannot fake code events - the container holds a ' . get_class($extension)
					. ' rather than ' . Extension::class . '. This happens when enableListeners '
					. 'is disabled in config.php.'
			);
		}

		return $extension;
	}

	/**
	 * Return every event fired since fakesEvents() was called.
	 *
	 * @return array - each entry is ['event' => string, 'args' => array, 'hint' => string|null]
	 *
	 * @throws \Exception
	 */
	protected function getFiredEvents()
	{
		$extension = $this->getEventExtension();

		if (!$extension->isFakingEvents())
		{
			throw new \Exception('Code events are not being faked - call fakesEvents() first');
		}

		return $extension->getFiredEvents();
	}

	/**
	 * Assert that a code event was fired, optionally matching a truth-test callback.
	 *
	 * @param string $event - the event id, eg 'entity_post_save'
	 * @param callable|int|null $callback - a truth test receiving ($args, $hint), or a count
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertEventFired($event, $callback = null)
	{
		if (is_numeric($callback))
		{
			$this->assertEventFiredTimes($event, $callback);
			return;
		}

		$fired = $this->firedEvents($event, $callback);

		PHPUnit::assertTrue(
			count($fired) > 0,
			"The expected event [{$event}] was not fired."
		);
	}

	/**
	 * Assert that a code event was fired a given number of times.
	 *
	 * @param string $event
	 * @param int $times
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertEventFiredTimes($event, $times = 1)
	{
		$fired = $this->firedEvents($event);

		PHPUnit::assertSame(
			$times,
			$count = count($fired),
			"The event [{$event}] was fired {$count} times instead of {$times} times."
		);
	}

	/**
	 * Assert that a code event was not fired.
	 *
	 * @param string $event
	 * @param callable|null $callback
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertEventNotFired($event, $callback = null)
	{
		$fired = $this->firedEvents($event, $callback);

		PHPUnit::assertCount(
			0,
			$fired,
			"The unexpected event [{$event}] was fired."
		);
	}

	/**
	 * Assert that no code events at all were fired.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertNoEventsFired()
	{
		$fired = $this->getFiredEvents();

		PHPUnit::assertCount(
			0,
			$fired,
			'Events were fired unexpectedly: ' . implode(', ', array_unique(array_column($fired, 'event')))
		);
	}

	/**
	 * @param string $event
	 * @param callable|null $callback
	 *
	 * @return array
	 *
	 * @throws \Exception
	 */
	private function firedEvents($event, $callback = null)
	{
		$callback = $callback ?: function ()
		{
			return true;
		};

		return array_filter(
			$this->getFiredEvents(),
			function (array $fired) use ($event, $callback)
			{
				return $fired['event'] === $event
					&& $callback($fired['args'], $fired['hint']);
			}
		);
	}
}
