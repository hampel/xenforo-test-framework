<?php

namespace Hampel\Testing\Integration;

/**
 * assertExceptionLogged() takes its filter as an optional second argument, so
 * assertErrorLogged() reads as though its message is optional too. It was not, and omitting it
 * gave an ArgumentCountError rather than the obvious "something was logged" assertion.
 */
class ErrorAssertionsTest extends TestCase
{
	public function test_an_error_can_be_asserted_without_naming_its_message()
	{
		$this->fakesErrors();

		$this->app()->error()->logError('something went wrong');

		$this->assertErrorLogged();
		$this->assertErrorLogged('something went wrong');
	}

	public function test_no_error_can_be_asserted_without_naming_a_message()
	{
		$this->fakesErrors();

		$this->assertErrorNotLogged();

		$this->app()->error()->logError('something went wrong');

		$this->assertErrorNotLogged('a different message');
	}
}
