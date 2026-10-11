<?php

namespace Hampel\Testing\Integration;

use PHPUnit\Framework\AssertionFailedError;

/**
 * What the output assertions say when they fail.
 *
 * PHPUnit's own string assertions print the whole subject, so a miss against a rendered page buried
 * the one useful fact - the value it looked for - under thousands of lines of escaped markup. These
 * pin the composed messages, and the first test is the one that regresses if anyone puts
 * assertStringContainsString() back: it fails as soon as the haystack is in the message.
 *
 * The messages are read through getMessage(), which carries them, so no subprocess is needed.
 */
class OutputAssertionFailureTest extends TestCase
{
	/**
	 * Markup big enough that printing it would be the problem, with a distinctive string in the
	 * middle that no message has any reason to carry.
	 *
	 * @return string
	 */
	private function bigHtml()
	{
		$filler = str_repeat('<p>padding padding padding padding padding</p>' . "\n", 200);

		return "<div>\n" . $filler . '<span>zzmiddlemarker</span>' . "\n" . $filler . "</div>\n";
	}

	/**
	 * @param callable $assertion
	 *
	 * @return string
	 */
	private function messageFrom(callable $assertion)
	{
		try
		{
			$assertion();
		}
		catch (AssertionFailedError $e)
		{
			// the parent of ExpectationFailedException, so this also catches a PHPUnit::fail()
			return $e->getMessage();
		}

		$this->fail('the assertion was expected to fail, and did not');
	}

	public function test_a_miss_names_the_value_and_the_length_but_not_the_haystack()
	{
		$html = $this->bigHtml();
		$message = $this->messageFrom(function () use ($html)
		{
			$this->assertSee($html, 'nothing here says this');
		});

		$this->assertStringContainsString('nothing here says this', $message);
		$this->assertStringContainsString(strlen($html) . ' characters', $message);

		// the haystack is not in the message, which is the whole point
		$this->assertStringNotContainsString('zzmiddlemarker', $message);
		$this->assertLessThan(
			strlen($html),
			strlen($message),
			'the failure message is as long as the markup it is describing'
		);
	}

	public function test_a_miss_names_the_escaped_form_it_searched_for()
	{
		$message = $this->messageFrom(function ()
		{
			$this->assertSee('<p>nothing</p>', "Bob's thread");
		});

		// the escaping default is a stumble of its own, so both forms are named
		$this->assertStringContainsString('Bob&#039;s thread', $message);
		$this->assertStringContainsString("escaped from 'Bob's thread'", $message);
	}

	public function test_a_miss_of_an_unescaped_value_does_not_claim_it_was_escaped()
	{
		$message = $this->messageFrom(function ()
		{
			$this->assertSee('<p>nothing</p>', 'plain text');
		});

		$this->assertStringContainsString('plain text', $message);
		$this->assertStringNotContainsString('escaped from', $message);
	}

	public function test_a_value_that_should_be_absent_is_located_in_context()
	{
		$html = $this->bigHtml();
		$message = $this->messageFrom(function () use ($html)
		{
			$this->assertDontSee($html, 'zzmiddlemarker');
		});

		$this->assertStringContainsString('zzmiddlemarker', $message);
		$this->assertStringContainsString('at offset ' . strpos($html, 'zzmiddlemarker'), $message);

		// the window carries what is either side of the match, not the match alone
		$this->assertStringContainsString('padding', $message);
		$this->assertStringContainsString('...', $message);
		$this->assertLessThan(strlen($html), strlen($message));
	}

	public function test_an_empty_render_is_explained_rather_than_described_by_length()
	{
		$message = $this->messageFrom(function ()
		{
			$this->assertSee('', 'anything');
		});

		// a template name with the wrong type renders as nothing rather than failing, which is the
		// usual reason a value is missing
		$this->assertStringContainsString('rendered output was empty', $message);
		$this->assertStringContainsString('type prefix', $message);
	}

	public function test_the_text_assertions_report_the_same_way()
	{
		$html = $this->bigHtml();

		$missing = $this->messageFrom(function () use ($html)
		{
			$this->assertSeeText($html, 'no such words');
		});

		$this->assertStringContainsString('text of the rendered output', $missing);
		$this->assertStringContainsString('no such words', $missing);
		$this->assertStringNotContainsString('zzmiddlemarker', $missing);

		$present = $this->messageFrom(function () use ($html)
		{
			$this->assertDontSeeText($html, 'zzmiddlemarker');
		});

		$this->assertStringContainsString('text of the rendered output', $present);
		$this->assertStringContainsString('zzmiddlemarker', $present);
		$this->assertLessThan(strlen($html), strlen($present));
	}

	public function test_a_match_at_the_very_start_still_fails_and_is_reported()
	{
		// strpos() answers 0 here, which is falsy - the assertions compare against false so that a
		// match at offset zero is still a match
		$message = $this->messageFrom(function ()
		{
			$this->assertDontSee('zzstart and then some more text', 'zzstart');
		});

		$this->assertStringContainsString('at offset 0', $message);
	}

	public function test_a_value_at_the_very_start_is_seen()
	{
		$this->assertSee('zzstart and then some more text', 'zzstart');
	}

	/**
	 * Every one of them carries a message the caller supplied.
	 *
	 * The position differs: it is the fourth argument of the three that escape and the third of the
	 * two that do not, because only some of them have a flag in front of it. So each is called the
	 * way its own signature reads.
	 *
	 * @return array - helper => a call that fails with the message below
	 */
	private function callsWithAMessage()
	{
		$html = '<p>zzpresent</p>';
		$note = 'zzthe diagnosis a reader needs';

		return [
			'assertSee()' => function () use ($html, $note)
			{
				$this->assertSee($html, 'zzabsent', true, $note);
			},
			'assertDontSee()' => function () use ($html, $note)
			{
				$this->assertDontSee($html, 'zzpresent', true, $note);
			},
			'assertSeeText()' => function () use ($html, $note)
			{
				$this->assertSeeText($html, 'zzabsent', $note);
			},
			'assertDontSeeText()' => function () use ($html, $note)
			{
				$this->assertDontSeeText($html, 'zzpresent', $note);
			},
			'assertSeeInOrder()' => function () use ($html, $note)
			{
				$this->assertSeeInOrder($html, ['zzpresent', 'zzabsent'], true, $note);
			},
			'assertNoUnresolvedPhrases()' => function () use ($note)
			{
				$this->assertNoUnresolvedPhrases('<p>probeph_never_defined</p>', 'probeph_', $note);
			},
		];
	}

	public function test_a_supplied_message_reaches_the_failure_of_every_output_assertion()
	{
		foreach ($this->callsWithAMessage() AS $helper => $call)
		{
			$message = $this->messageFrom($call);

			$this->assertStringContainsString(
				'zzthe diagnosis a reader needs',
				$message,
				"$helper dropped the failure message it was given"
			);
		}
	}

	public function test_a_supplied_message_does_not_replace_what_the_assertion_found()
	{
		// the caller's message is prefixed, so both halves are there. A substituted message would
		// satisfy the test above and cost the reader the value and the size of the render
		$message = $this->messageFrom(function ()
		{
			$this->assertSee('<p>zzpresent</p>', 'zzabsent', true, 'zzmy own note');
		});

		$this->assertStringContainsString('zzmy own note', $message);
		$this->assertStringContainsString('zzabsent', $message);
		$this->assertStringContainsString('16 characters', $message);

		$absent = $this->messageFrom(function ()
		{
			$this->assertDontSee('<p>zzpresent</p>', 'zzpresent', true, 'zzmy own note');
		});

		$this->assertStringContainsString('zzmy own note', $absent);
		$this->assertStringContainsString('at offset 3', $absent);
	}

	public function test_a_message_does_not_hide_the_unresolved_phrase_keys()
	{
		// the keys are the entire diagnosis here, and this assertion used to substitute the
		// caller's message for them
		$message = $this->messageFrom(function ()
		{
			$this->assertNoUnresolvedPhrases(
				'<p>probeph_never_defined and probeph_nor_this</p>',
				'probeph_',
				'zzmy own note'
			);
		});

		$this->assertStringContainsString('zzmy own note', $message);
		$this->assertStringContainsString('probeph_never_defined', $message);
		$this->assertStringContainsString('probeph_nor_this', $message);
	}

	public function test_a_message_written_where_the_escaping_flag_goes_is_refused()
	{
		// a non-empty string is truthy, so it would silently be the default and the message would
		// be gone - which is the state this parameter was added to end
		$calls = [
			'assertSee()' => function ()
			{
				$this->assertSee('<p>zzpresent</p>', 'zzpresent', 'a message in the wrong slot');
			},
			'assertDontSee()' => function ()
			{
				$this->assertDontSee('<p>zzpresent</p>', 'zzabsent', 'a message in the wrong slot');
			},
			'assertSeeInOrder()' => function ()
			{
				$this->assertSeeInOrder('<p>zzpresent</p>', ['zzpresent'], 'a message in the wrong slot');
			},
		];

		foreach ($calls AS $helper => $call)
		{
			try
			{
				$call();
				$this->fail("$helper accepted a failure message as its escaping flag");
			}
			catch (\LogicException $e)
			{
				$this->assertStringContainsString($helper, $e->getMessage());
				$this->assertStringContainsString('escaping flag', $e->getMessage());
			}
		}
	}

	public function test_an_escaping_flag_written_where_the_message_goes_is_refused()
	{
		// the text assertions take no flag, so a false meant for one lands in the message
		$calls = [
			'assertSeeText()' => function ()
			{
				$this->assertSeeText('<p>zzpresent</p>', 'zzpresent', false);
			},
			'assertDontSeeText()' => function ()
			{
				$this->assertDontSeeText('<p>zzpresent</p>', 'zzabsent', false);
			},
		];

		foreach ($calls AS $helper => $call)
		{
			try
			{
				$call();
				$this->fail("$helper accepted a bool as its failure message");
			}
			catch (\LogicException $e)
			{
				$this->assertStringContainsString($helper, $e->getMessage());
				$this->assertStringContainsString('strip the markup', $e->getMessage());
			}
		}
	}

	public function test_the_escaping_flag_still_works_positionally()
	{
		// every existing call that turns escaping off passes it here, so the refusal above must not
		// reach a bool
		// escaping off searches for exactly what was passed, so here the markup is raw
		$this->assertSee("<p>Bob's thread</p>", "Bob's thread", false);
		$this->assertDontSee('<p>plain</p>', "Bob's thread", false);
		$this->assertSeeInOrder("<p>Bob's thread</p>", ["Bob's"], false);

		// escaping on searches for the entity a template would have emitted
		$this->assertSee('<p>Bob&#039;s thread</p>', "Bob's thread", true);
	}

	public function test_a_passing_assertion_is_unaffected_by_a_message()
	{
		$this->assertSee('<p>zzpresent</p>', 'zzpresent', true, 'never read');
		$this->assertDontSee('<p>zzpresent</p>', 'zzabsent', true, 'never read');
		$this->assertSeeText('<p>zzpresent</p>', 'zzpresent', 'never read');
		$this->assertDontSeeText('<p>zzpresent</p>', 'zzabsent', 'never read');
		$this->assertSeeInOrder('<p>one two</p>', ['one', 'two'], true, 'never read');
		$this->assertNoUnresolvedPhrases('<p>a real label</p>', 'probeph_', 'never read');
	}

	public function test_a_modification_that_does_not_exist_carries_the_message_too()
	{
		// the only one of these that fails through PHPUnit::fail() rather than an assertion
		$message = $this->messageFrom(function ()
		{
			$this->assertTemplateModificationApplied('zz_no_such_modification', 'zzmy own note');
		});

		$this->assertStringContainsString('zzmy own note', $message);
		$this->assertStringContainsString('zz_no_such_modification', $message);
	}
}
