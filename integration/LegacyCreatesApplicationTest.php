<?php

namespace Hampel\Testing\Integration;

/**
 * An add-on that kept its own tests/CreatesApplication.php after upgrading to 5.0.0.
 *
 * This is the compatibility claim the release rests on: a trait method wins over an inherited
 * one in PHP, so a consumer who does nothing keeps the boot they already had, and migration is
 * something they choose rather than something the upgrade forces. Asserted rather than assumed,
 * because the whole point of owning the boot is that a copy nobody touches goes unnoticed.
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
