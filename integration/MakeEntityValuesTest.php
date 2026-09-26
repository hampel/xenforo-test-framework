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
}
