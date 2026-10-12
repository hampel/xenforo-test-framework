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

	/**
	 * The string form of assertExceptionLogged() matches the message as it was logged, which
	 * carries the prefix the caller passed to \XF::logException() in front of the exception's
	 * own message. Matching the prefix is the case the string form was added for.
	 */
	public function test_a_logged_exception_matches_on_its_prefix_and_on_its_own_message()
	{
		$this->fakesErrors();

		\XF::logException(new \RuntimeException('smtp is down'), false, 'MyAddon: ');

		$this->assertExceptionLogged(\RuntimeException::class, 'MyAddon: ');
		$this->assertExceptionLogged(\RuntimeException::class, 'smtp is down');
	}

	public function test_a_logged_exception_does_not_match_a_prefix_it_was_not_given()
	{
		$this->fakesErrors();

		\XF::logException(new \RuntimeException('smtp is down'), false, 'MyAddon: ');

		$this->assertExceptionNotLogged(\RuntimeException::class, 'SomeoneElse: ');
	}
}
