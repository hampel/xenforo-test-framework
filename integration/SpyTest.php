<?php

namespace Hampel\Testing\Integration;

use XF\Http\Request;

/**
 * The behaviour DOCS.md describes for spy(), including that a spy returns null for anything it
 * was not told about.
 */
class SpyTest extends TestCase
{
	public function test_a_spy_records_a_call_and_is_asserted_afterwards()
	{
		$request = $this->spy('request', Request::class);

		$this->app()->request()->getIp();

		$request->shouldHaveReceived('getIp');
		$request->shouldNotHaveReceived('getUserAgent');
	}

	public function test_a_spy_records_the_arguments_it_was_called_with()
	{
		$request = $this->spy('request', Request::class);

		$this->app()->request()->getIp(false);

		$request->shouldHaveReceived('getIp')->with(false);
		$request->shouldNotHaveReceived('getIp', [true]);
	}

	public function test_a_spy_returns_null_for_anything_it_was_not_told_about()
	{
		$this->spy('request', Request::class);

		// the real Request answers getIp() with '' in this app, so null here is the spy
		$this->assertNull($this->app()->request()->getIp());
	}

	public function test_a_spy_can_still_be_told_what_to_return()
	{
		$this->spy('request', Request::class, function ($mock)
		{
			$mock->allows()->getIp(false)->andReturns('10.0.0.1');
		});

		$this->assertSame('10.0.0.1', $this->app()->request()->getIp(false));
	}

	public function test_a_second_spy_replaces_the_first()
	{
		$first = $this->spy('request', Request::class);
		$second = $this->spy('request', Request::class);

		$this->app()->request()->getIp();

		$second->shouldHaveReceived('getIp');
		$first->shouldNotHaveReceived('getIp');
	}

	public function test_the_spy_is_the_instance_in_the_container()
	{
		$spy = $this->spy('request', Request::class);

		$this->assertSame($spy, $this->app()->request());
	}
}
