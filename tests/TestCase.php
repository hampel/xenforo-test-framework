<?php

namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * @var string $rootDir path to your XenForo root directory, relative to the addon path
	 *
	 * Set $rootDir to '../../../..' if you use a vendor in your addon id (ie <Vendor/AddonId>)
	 * Otherwise, set this to '../../..' for no vendor
	 *
	 * No trailing slash!
	 */
	protected $rootDir = '../../../..';

	/**
	 * @var array $addonsToLoad an array of XenForo addon ids to load
	 *
	 * Name your own addon here, eg ['MyVendor/MyAddon']. Only those addons are loaded, which keeps
	 * other addons' class extensions, code event listeners and Composer autoloading out of your
	 * tests.
	 *
	 * Leaving it empty loads every installed addon. On a forum where another addon vendors its own
	 * copy of PHPUnit, that copy can win on the class loader and the run dies before the first test
	 * with an error naming PHPUnit internals rather than anything about isolation.
	 */
	protected $addonsToLoad = [];

	/**
	 * Helper function to load mock data from a file (eg json)
	 * To use, create a "mock" folder relative to the tests folder, eg:
	 * 'src/addons/MyVendor/MyAddon/tests/mock'
	 *
	 * @param $file
	 *
	 * @return false|string
	 */
	protected function getMockData($file)
	{
		return file_get_contents(__DIR__ . '/mock/' . $file);
	}
}
