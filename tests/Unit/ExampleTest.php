<?php

namespace Tests\Unit;

use Tests\TestCase;
use XF\App;

class ExampleTest extends TestCase
{
	/**
	 * The application booted: the scaffold found the forum above the add-on.
	 *
	 * @return void
	 */
	public function test_the_application_boots()
	{
		$this->assertInstanceOf(App::class, $this->app());
	}
}
