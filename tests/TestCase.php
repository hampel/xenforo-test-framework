<?php namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

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
     * Specifying an array of addon ids will cause only those addons to be loaded - useful for isolating your addon for
     * testing purposes
     *
     * Leave empty to load all addons
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
