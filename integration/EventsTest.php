<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Extension;

class EventsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		EventProbeListener::reset();
	}

	/**
	 * Swap in a controlled listener, so these tests do not depend on which add-ons happen to be
	 * installed in the forum. Not done in setUp() - some tests below need the real extension.
	 */
	private function useProbeListener()
	{
		return $this->swap('extension', new Extension(
			['probe_event' => ['_' => [[EventProbeListener::class, 'handle']]]],
			[]
		));
	}

	public function test_without_faking_the_listener_runs()
	{
		$this->useProbeListener();

		$this->app()->extension()->fire('probe_event', ['a', 'b']);

		$this->assertSame(1, EventProbeListener::$calls);
		$this->assertSame(['a', 'b'], EventProbeListener::$lastArgs);
	}

	/** the point of the fake: the listener must not run */
	public function test_faking_stops_the_listener_running()
	{
		$this->useProbeListener();

		$this->fakesEvents();

		$this->app()->extension()->fire('probe_event', ['a', 'b']);

		$this->assertSame(0, EventProbeListener::$calls);
		$this->assertEventFired('probe_event');
	}

	public function test_assertions()
	{
		$this->useProbeListener();

		$this->fakesEvents();
		$this->assertNoEventsFired();

		$extension = $this->app()->extension();
		$extension->fire('probe_event', ['first'], 'someHint');
		$extension->fire('probe_event', ['second']);
		$extension->fire('other_event');

		$this->assertEventFired('probe_event');
		$this->assertEventFiredTimes('probe_event', 2);
		$this->assertEventFired('probe_event', 2);          // numeric shortcut
		$this->assertEventNotFired('absent_event');

		$this->assertEventFired('probe_event', function ($args, $hint)
		{
			return $args === ['first'] && $hint === 'someHint';
		});

		$this->assertEventNotFired('probe_event', function ($args)
		{
			return $args === ['nope'];
		});
	}

	public function test_fire_reports_no_veto_while_faking()
	{
		$this->useProbeListener();

		$this->fakesEvents();

		$this->assertTrue($this->app()->extension()->fire('probe_event'));
	}

	public function test_assertions_require_fakes_events_first()
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Code events are not being faked');

		$this->getFiredEvents();
	}

	/** faking suppresses listeners only - class extensions must still resolve */
	public function test_class_extensions_still_work_while_faking()
	{
		$this->fakesEvents();

		// XF 2.3 renamed this class; resolving it proves the extension map is still live
		$this->assertSame(
			'XF\\Repository\\UserRepository',
			get_class($this->app()->em()->getRepository('XF:User'))
		);
	}
}
