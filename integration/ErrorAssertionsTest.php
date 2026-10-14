<?php

namespace Hampel\Testing\Integration;

use PHPUnit\Framework\ExpectationFailedException;

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

	public function test_the_messages_of_everything_logged_are_readable()
	{
		$this->fakesErrors();

		$this->app()->error()->logError('first thing');
		\XF::logException(new \RuntimeException('second thing'), false, 'MyAddon: ');

		// the prefix is part of the message, as it is in the logged row
		$this->assertSame(
			['first thing', 'MyAddon: second thing'],
			$this->getLoggedMessages()
		);
	}

	public function test_text_absent_from_every_logged_message_is_asserted()
	{
		$this->fakesErrors();

		\XF::logException(new \RuntimeException('refused by the api'), false, 'MyAddon: ');

		// the positive half: something was logged, so the assertion below examined a message
		$this->assertExceptionLogged(\RuntimeException::class);
		$this->assertNothingLoggedContains('hunter2');
	}

	public function test_text_present_in_a_logged_message_fails_the_assertion()
	{
		$this->fakesErrors();

		\XF::logException(new \RuntimeException('token hunter2 was refused'), false, 'MyAddon: ');

		try
		{
			$this->assertNothingLoggedContains('hunter2');
			$this->fail('a logged message containing the text should have failed the assertion');
		}
		catch (ExpectationFailedException $e)
		{
			// the needle is named in the message; the offending message itself is the asserted
			// value, so it reaches the reader through PHPUnit's own comparison and a caller's
			// failure message cannot hide it
			$this->assertStringContainsString("'hunter2'", $e->getMessage());

			$comparison = $e->getComparisonFailure();
			$this->assertNotNull($comparison, 'the offending messages are the compared value');
			$this->assertStringContainsString(
				'hunter2 was refused',
				print_r($comparison->getActual(), true)
			);
		}
	}

	public function test_an_empty_needle_is_refused()
	{
		$this->fakesErrors();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('found at position 0 of anything');

		$this->assertNothingLoggedContains('');
	}
}
