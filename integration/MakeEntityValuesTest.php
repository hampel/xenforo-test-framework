<?php

namespace Hampel\Testing\Integration;

/**
 * makeEntity() refuses an entity that did not take a value it was given.
 */
class MakeEntityValuesTest extends TestCase
{
	public function test_a_unique_key_already_in_use_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("did not take the value given for 'option_id'");

		// boardTitle exists on every forum, so the uniqueness check rejects it and the column
		// is left null rather than throwing
		$this->makeEntity('XF:Option', ['option_id' => 'boardTitle']);
	}

	public function test_the_message_names_setTrusted_and_the_verifier_error()
	{
		try
		{
			$this->makeEntity('XF:Option', ['option_id' => 'boardTitle']);
			$this->fail('expected a LogicException');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('must be unique', $e->getMessage());
			$this->assertStringContainsString("setTrusted('option_id'", $e->getMessage());
		}
	}

	public function test_an_unused_value_is_accepted()
	{
		$option = $this->makeEntity('XF:Option', ['option_id' => 'notARealOptionAnywhere']);

		$this->assertSame('notARealOptionAnywhere', $option->option_id);
	}

	public function test_a_value_a_verifier_rejects_is_refused_whatever_the_column()
	{
		// XenForo verifies many columns as they are set, not at save time, so an invalid value never
		// lands at all - a test that wants one has to use setTrusted()
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("did not take the value given for 'email'");

		$this->makeEntity('XF:User', ['username' => 'Probe', 'email' => 'not-an-email']);
	}

	public function test_setTrusted_still_sets_a_value_a_verifier_would_reject()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'Probe']);
		$user->setTrusted('email', 'not-an-email');

		$this->assertSame('not-an-email', $user->email);
	}

	public function test_a_null_passed_deliberately_is_not_refused()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'Probe', 'custom_title' => null]);

		$this->assertSame('Probe', $user->username);
	}

	public function test_an_auto_increment_column_is_refused_with_the_call_that_sets_it()
	{
		try
		{
			$this->makeEntity('XF:User', ['user_id' => 42, 'username' => 'Probe']);
			$this->fail('an auto-increment column should have been refused');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString("'user_id'", $e->getMessage());
			$this->assertStringContainsString('read-only or auto-increment', $e->getMessage());

			// the remedy the message prints, which the next test runs
			$this->assertStringContainsString(
				"\$entity->setTrusted('user_id', 42);",
				$e->getMessage()
			);
		}
	}

	public function test_the_remedy_the_refusal_prints_works()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'Probe']);
		$user->setTrusted('user_id', 42);

		$this->assertSame(42, $user->user_id);
		$this->assertSame('Probe', $user->username);
	}

	public function test_createEntity_refuses_it_too_without_writing_anything()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('read-only or auto-increment');

		$this->createEntity('XF:Notice', ['notice_id' => 9001, 'title' => 'Probe']);
	}

	public function test_createUserAccount_refuses_it_as_well()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("'user_id'");

		$this->createUserAccount('probebob', ['user_id' => 42]);
	}

	public function test_an_ordinary_column_is_unaffected()
	{
		// the guard must not narrow what already worked
		$user = $this->makeEntity('XF:User', ['username' => 'Probe', 'user_state' => 'moderated']);

		$this->assertSame('Probe', $user->username);
		$this->assertSame('moderated', $user->user_state);
	}
}
