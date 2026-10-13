<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Extension;

/**
 * The refusal when $addonsToLoad would extend a class this run has already resolved.
 *
 * The mirror of the documented case where the list cannot *remove* an extension, and the same
 * cause: the resolution is cached for the whole process and the proxy XenForo aliases to reach the
 * extension is a class_alias() PHP cannot declare twice. So a test class naming more add-ons than
 * its siblings gets their extensions only when it happens to run first.
 *
 * Each test resolves its own base class, because a class can be resolved only once per process -
 * which also keeps these tests independent of the order they run in.
 */
class WideningRefusalTest extends TestCase
{
	public function test_an_extension_added_after_the_class_resolved_is_reported()
	{
		$base = Fixtures\ExtendableThingG::class;

		// one Extension per test is what the framework builds and the cache they share is static,
		// so this is the same shape as a narrow test class running before a wider one
		$narrow = new Extension([], []);
		$this->assertSame($base, $narrow->extendClass($base), 'resolved with no extensions');

		$wide = new Extension([], [$base => [Fixtures\Ext\ExtendableThingG::class]]);

		$this->assertSame(
			[$base => [Fixtures\Ext\ExtendableThingG::class]],
			$wide->extensionsThatCannotApply()
		);
	}

	public function test_the_refusal_names_the_class_the_extension_and_the_remedy()
	{
		// its own base class, resolved here: borrowing the one above would make this test pass or
		// fail on the order the two ran in, which is a standing false alarm in a reverse-order run
		$base = Fixtures\ExtendableThingI::class;
		(new Extension([], []))->extendClass($base);

		$wide = new Extension([], [$base => [Fixtures\Ext\ExtendableThingI::class]]);

		try
		{
			$this->requireExtensionsCanApply($wide);
			$this->fail('an extension that cannot be applied should have been refused');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString($base, $e->getMessage());
			$this->assertStringContainsString(Fixtures\Ext\ExtendableThingI::class, $e->getMessage());
			// the remedy, which is the one thing a reader of this message needs
			$this->assertStringContainsString('one $addonsToLoad for the whole suite', $e->getMessage());
		}
	}

	public function test_a_narrower_list_is_left_alone()
	{
		$base = Fixtures\ExtendableThingH::class;
		$both = [
			Fixtures\Ext\ExtendableThingH::class,
			Fixtures\Ext2\ExtendableThingH::class,
		];

		$wide = new Extension([], [$base => $both]);
		$this->assertSame(Fixtures\Ext2\ExtendableThingH::class, $wide->extendClass($base));

		// fewer extensions than were applied: that is the documented direction, a test can want it
		// deliberately, and requireClassNotExtended() is what asserts on it
		$narrow = new Extension([], [$base => [Fixtures\Ext\ExtendableThingH::class]]);
		$this->assertSame([], $narrow->extensionsThatCannotApply());

		// and the same list again, which is what every test class in a suite with one list has
		$same = new Extension([], [$base => $both]);
		$this->assertSame([], $same->extensionsThatCannotApply());
	}

	public function test_a_class_this_run_has_not_resolved_is_not_reported()
	{
		// nothing is loaded by the check - it compares the recorded extension sets - so a class
		// that exists nowhere is the clearest case of one this run has never resolved
		$extension = new Extension([], ['Zz\NeverResolved' => ['Zz\Ext\NeverResolved']]);

		$this->assertSame([], $extension->extensionsThatCannotApply());
		$this->requireExtensionsCanApply($extension);
	}
}
