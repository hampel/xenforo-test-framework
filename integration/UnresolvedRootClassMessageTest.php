<?php

namespace Hampel\Testing\Integration;

use XF\Mvc\Controller;
use XF\Pub\Controller\IndexController;

/**
 * A controller, entity or service constructed with new asks the extension system for its root
 * class, and XenForo throws when nothing has resolved the class's extension chain. Because this
 * package keeps that resolution for the whole run, the same new passes once another test has
 * resolved the chain, so the message names the remedy and the order-dependence.
 */
class UnresolvedRootClassMessageTest extends TestCase
{
	public function test_a_class_nothing_resolved_is_refused_with_the_remedy()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('stdClass was asked for its root class');

		$this->app()->extension()->resolveExtendedClassToRoot(\stdClass::class);
	}

	public function test_the_message_names_the_remedy_and_keeps_xenforo_s_own()
	{
		try
		{
			$this->app()->extension()->resolveExtendedClassToRoot(new \stdClass());
			$this->fail('no exception');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('$app->extendClass()', $e->getMessage());
			$this->assertStringContainsString('earlier test in the run', $e->getMessage());
			$this->assertInstanceOf(\LogicException::class, $e->getPrevious());
			$this->assertStringContainsString('extendClass was never called', $e->getPrevious()->getMessage());
		}
	}

	/**
	 * The remedy the message gives, done: once the application has resolved the chain, the
	 * constructor's own call answers.
	 */
	public function test_a_class_the_application_resolved_answers_its_root()
	{
		$class = $this->app()->extendClass(IndexController::class);
		$controller = new $class($this->app(), $this->app()->request());

		$this->assertInstanceOf(Controller::class, $controller);
		$this->assertSame(
			IndexController::class,
			$this->app()->extension()->resolveExtendedClassToRoot($controller)
		);
	}
}
