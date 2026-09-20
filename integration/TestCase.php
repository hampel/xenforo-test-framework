<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\TestCase as BaseTestCase;

/**
 * Base class for this package's own integration tests.
 *
 * These are NOT the scaffold in tests/ - that is what consuming add-ons copy, and it must keep
 * working as documented. This directory is export-ignored and never reaches a consumer.
 *
 * Unlike an add-on's suite, this harness lives outside any forum, so $rootDir is absolute and
 * comes from the environment:
 *
 *     XF_ROOT=/srv/www/myforum composer integration
 */
abstract class TestCase extends BaseTestCase
{
	use CreatesApplication;

	/** @var string absolute path to a XenForo 2.3 root */
	protected $rootDir;

	/**
	 * Load no add-ons at all.
	 *
	 * An empty array would load every add-on installed in the forum, and any that ship their
	 * own PHPUnit and Mockery then collide with ours - Mockery registers an expectation in one
	 * instance and verifies it in another, and tests fail with counts of zero. Naming an id
	 * that matches nothing keeps every add-on's Composer autoloading and class extensions out,
	 * which is what testing the framework itself wants.
	 *
	 * It does NOT keep their code event listeners out. app_setup fires at the end of
	 * XF\App::setup(), while the filtered extension container key is installed later, from
	 * setUpTraits() - so every installed add-on's app_setup listener runs before any test does.
	 * Measured 2026-09-20 on a forum with 13 of them: all 13 listener classes loaded.
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
