<?php

namespace Hampel\Testing\Integration;

use XF\Mvc\Reply\Error;

/**
 * An error reply's errors are usually XF\Phrase objects, and a phrase reaches the language, the
 * database adapter and its configuration - so dumping one prints the database password. The
 * framework's own output must never do that, which is what these tests pin.
 *
 * **Every assertion here compares with strpos() into assertTrue()/assertFalse(), including the
 * positive ones.** PHPUnit's string assertions print the haystack when they fail, so a test
 * guarding a leak would cause one the moment it caught a regression - and the positive assertion
 * that stops this passing vacuously is the one that would do it, since the haystack it prints is
 * the dumped object. Measured: with the cast removed, the positive form leaked the password into
 * the failure output and this form does not.
 */
class ReplyDescriptionSafetyTest extends TestCase
{
	public function test_describing_a_reply_renders_the_phrase_and_carries_no_credentials()
	{
		$reply = new Error([\XF::phrase('do_not_have_permission')]);
		$description = $this->describeReply($reply);

		// the positive half: without it, an empty description would pass everything below. Asserted
		// as a boolean so that a regression cannot print the description it is objecting to
		$this->assertTrue(
			strpos($description, 'do not have permission') !== false,
			'describeReply() renders the error phrase to its text'
		);

		foreach ($this->forbiddenInOutput() AS $what => $secret)
		{
			$this->assertFalse(
				strpos($description, $secret) !== false,
				"describeReply() must not carry the $what"
			);
		}
	}

	public function test_reading_the_errors_renders_them_and_carries_no_credentials()
	{
		$reply = new Error([\XF::phrase('do_not_have_permission')]);
		$errors = $this->replyErrors($reply);

		// assertCount prints sizes rather than contents, so it is safe as it stands
		$this->assertCount(1, $errors);
		$this->assertTrue(
			strpos($errors[0], 'do not have permission') !== false,
			'replyErrors() renders the error phrase to its text'
		);

		foreach ($this->forbiddenInOutput() AS $what => $secret)
		{
			$this->assertFalse(
				strpos(implode(' ', $errors), $secret) !== false,
				"replyErrors() must not carry the $what"
			);
		}
	}

	public function test_the_assertion_on_an_error_reply_compares_text_rather_than_objects()
	{
		$reply = new Error([\XF::phrase('do_not_have_permission')]);

		// matching on text is what keeps a failure's own output free of the objects
		$this->assertReplyIsError($reply, null, 'do not have permission');
	}

	/**
	 * The credentials that must not appear, read from the forum's own configuration.
	 *
	 * Skips rather than passes when there is nothing to look for: a forum configured without a
	 * password would make every assertion above vacuous.
	 *
	 * @return array<string, string>
	 */
	private function forbiddenInOutput()
	{
		$config = [];
		include $this->rootDir . '/src/config.php';

		$secrets = array_filter([
			'database password' => (string) ($config['db']['password'] ?? ''),
			'database user' => (string) ($config['db']['username'] ?? ''),
		]);

		if (!$secrets)
		{
			$this->markTestSkipped(
				'this forum names no database credentials, so there is nothing to look for'
			);
		}

		return $secrets;
	}
}
