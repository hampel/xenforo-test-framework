<?php

namespace Hampel\Testing\Integration;

use PHPUnit\Framework\SkippedWithMessageException;
use XF\Entity\SpamTriggerLog;

/**
 * requireClassNotExtended(), for a test whose point is that an add-on's extension is absent.
 *
 * $addonsToLoad cannot remove an extension already resolved in the process: XenForo caches the
 * resolution and aliases an XFCP proxy, which cannot be undeclared. Such a test passes alone and
 * exercises the extension in a full run, so it has to refuse rather than pass.
 */
class IsolationLimitTest extends TestCase
{
	public function test_a_class_nothing_has_extended_runs_the_test()
	{
		$this->requireClassNotExtended(Fixtures\ExtendableThingC::class);

		$this->assertTrue(true, 'the test body runs when the class resolves unextended');
	}

	public function test_an_extended_class_skips_and_says_why()
	{
		$base = Fixtures\ExtendableThingF::class;
		$this->app()->extension()->addClassExtension($base, Fixtures\Ext\ExtendableThingF::class);

		// asserted by catching it: a skip the test took deliberately cannot be read any other way
		try
		{
			$this->requireClassNotExtended($base);
			$this->fail('an extended class should not have run the test');
		}
		catch (SkippedWithMessageException $e)
		{
			$this->assertStringContainsString('already extended in this process', $e->getMessage());
			$this->assertStringContainsString(Fixtures\Ext\ExtendableThingF::class, $e->getMessage());
			$this->assertStringContainsString('--filter IsolationLimitTest', $e->getMessage());
		}
	}

	public function test_an_entity_short_name_is_resolved_without_applying_extensions()
	{
		// the entity manager's own resolver returns the EXTENDED class, so resolving the short name
		// through it would compare a class with itself and never refuse anything
		$this->app()->extension()->addClassExtension(
			SpamTriggerLog::class,
			Fixtures\Ext\SpamTriggerLog::class
		);

		try
		{
			$this->requireClassNotExtended('XF:SpamTriggerLog');
			$this->fail('an extended entity should not have run the test');
		}
		catch (SkippedWithMessageException $e)
		{
			$this->assertStringContainsString(SpamTriggerLog::class, $e->getMessage());
		}
	}
}
