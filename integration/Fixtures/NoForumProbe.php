<?php

namespace Hampel\Testing\Integration\Fixtures;

use Hampel\Testing\Integration\TestCase;
use XF\App;

/**
 * Run in its own process by SkipsWithoutForumTest with XF_ROOT pointing nowhere, so that every
 * test skips in setUp() before the application boots and teardown runs with no XenForo loaded.
 * Two tests, because the second test in a process is the one a class loaded by the first would
 * hide. Not named *Test.php, so the suite does not collect it.
 */
class NoForumProbe extends TestCase
{
	public function test_the_first_test_skips()
	{
		$this->assertInstanceOf(App::class, $this->app());
	}

	public function test_the_second_test_skips_too()
	{
		$this->assertInstanceOf(App::class, $this->app());
	}
}
