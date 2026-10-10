<?php

namespace Hampel\Testing\Integration;

use PHPUnit\Framework\ExpectationFailedException;

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
		catch (ExpectationFailedException $e)
		{
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
}
