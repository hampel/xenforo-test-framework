<?php

namespace Hampel\Testing\Integration;

/**
 * XF::start() installs its own error and exception handlers and never removes them. Left alone
 * that marks every test which boots XenForo as risky, and makes failOnRisky unusable.
 *
 * Note that failOnRisky in integration/phpunit.xml is itself the regression test for this: if
 * TestCase stops restoring the handlers, every test in this suite starts failing, not just these.
 */
class ErrorHandlerTest extends TestCase
{
	private function currentErrorHandler()
	{
		$handler = set_error_handler(null);
		restore_error_handler();

		return $handler;
	}

	private function currentExceptionHandler()
	{
		$handler = set_exception_handler(null);
		restore_exception_handler();

		return $handler;
	}

	/** during the test the handlers are XenForo's, as they would be in production */
	public function test_xenforo_handlers_are_active_during_a_test()
	{
		$this->assertSame(['XF', 'handlePhpError'], $this->currentErrorHandler());
		$this->assertSame(['XF', 'handleException'], $this->currentExceptionHandler());
	}

	public function test_the_handlers_can_be_handed_back()
	{
		$this->restoreErrorHandlers();

		$this->assertNotSame(['XF', 'handlePhpError'], $this->currentErrorHandler());
		$this->assertNotSame(['XF', 'handleException'], $this->currentExceptionHandler());
	}
}
