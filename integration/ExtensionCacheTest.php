<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Extension;
use Hampel\Testing\Integration\Fixtures\ExtendableThingB;
use Hampel\Testing\Integration\Fixtures\ExtendableThingC;

/**
 * The static extension cache, which persists across a run by design.
 *
 * It has to be keyed by the name XenForo will actually extend, and it has to notice an extension
 * added after it cached the unextended class - both failed only in a full run, never alone.
 */
class ExtensionCacheTest extends TestCase
{
	public function test_every_spelling_of_a_class_shares_one_cache_entry()
	{
		$base = Fixtures\ExtendableThingD::class;
		$extensions = [$base => [Fixtures\Ext\ExtendableThingD::class]];

		// one Extension per test is what the framework builds, and the cache they share is static -
		// so this is the same shape as two tests resolving the class by different names. Keyed by the
		// spelling as passed, the second misses the cache, extends again, and PHP refuses to alias
		// the proxy class a second time
		$first = (new Extension([], $extensions))->extendClass('\\' . $base);
		$second = (new Extension([], $extensions))->extendClass($base);

		$this->assertSame(Fixtures\Ext\ExtendableThingD::class, $first);
		$this->assertSame($first, $second);
	}

	public function test_an_extension_added_after_the_class_was_resolved_still_applies()
	{
		$base = ExtendableThingB::class;

		// resolved with no extensions, which is what the cache then holds
		$this->assertSame($base, $this->app()->extendClass($base));

		$this->app()->extension()->addClassExtension($base, Fixtures\Ext\ExtendableThingB::class);

		$extended = $this->app()->extendClass($base);

		$this->assertSame(Fixtures\Ext\ExtendableThingB::class, $extended);
		$this->assertSame('extended base', (new $extended())->describe());
	}

	public function test_extending_a_class_twice_in_one_run_is_refused()
	{
		$base = ExtendableThingC::class;
		$this->app()->extension()->addClassExtension($base, Fixtures\Ext\ExtendableThingC::class);
		$this->app()->extendClass($base);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('already been extended in this run');

		$this->app()->extension()->addClassExtension($base, Fixtures\Ext2\ExtendableThingC::class);
	}
}
