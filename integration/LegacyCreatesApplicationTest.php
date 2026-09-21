<?php

namespace Hampel\Testing\Integration;

/**
 * An add-on that still has its own tests/CreatesApplication.php. A trait method takes precedence
 * over an inherited one, so its boot is used rather than the framework's.
 */
trait LegacyCreatesApplication
{
	/** @var bool */
	public $legacyBootRan = false;

	public function createApplication()
	{
		require_once "{$this->rootDir}/src/XF.php";

		\XF::start($this->rootDir);

		$this->legacyBootRan = true;

		return \XF::setupApp('Hampel\Testing\App', ['xf-addons' => $this->addonsToLoad]);
	}
}

class LegacyCreatesApplicationTest extends TestCase
{
	use LegacyCreatesApplication;

	public function test_a_consumers_own_boot_still_wins_over_the_inherited_one()
	{
		$this->assertTrue(
			$this->legacyBootRan,
			'the trait method should have booted the application, not TestCase::createApplication()'
		);
	}

	public function test_and_that_boot_still_gets_add_on_isolation()
	{
		$this->assertSame(['None/None'], $this->app()->isolatedAddOnIds());
	}
}
