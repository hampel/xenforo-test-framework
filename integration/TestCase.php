<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\TestCase as BaseTestCase;

/**
 * Base class for this package's own integration tests.
 *
 * These are not the scaffold in tests/, which consuming add-ons copy. This directory is
 * export-ignored.
 *
 * Unlike an add-on's suite, this harness lives outside any forum, so $rootDir is absolute and
 * comes from the environment:
 *
 *     XF_ROOT=/srv/www/myforum composer integration
 */
abstract class TestCase extends BaseTestCase
{
	/**
	 * Absolute path to a XenForo 2.3 root, from the environment.
	 *
	 * The suite runs on the framework's own createApplication(), so it tests that boot.
	 *
	 * @var string
	 */
	protected $rootDir;

	/**
	 * Load no add-ons at all.
	 *
	 * An empty array would load every add-on installed in the forum, and any that ship their
	 * own PHPUnit and Mockery then collide with ours. An id that matches nothing keeps every
	 * add-on's autoloading, class extensions and code event listeners out.
	 *
	 * @var array
	 */
	protected $addonsToLoad = ['None/None'];

	protected function setUp(): void
	{
		$root = getenv('XF_ROOT');

		if (!$root || !is_file("{$root}/src/XF.php"))
		{
			$this->markTestSkipped(
				'Set XF_ROOT to a XenForo 2.3 root to run the integration tests, '
				. 'eg XF_ROOT=/srv/www/myforum composer integration'
			);
		}

		$this->rootDir = $root;

		parent::setUp();
	}
}
