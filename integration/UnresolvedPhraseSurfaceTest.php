<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\AssertionFailedError;

/**
 * An add-on's prefix appears in correct output as well as in a phrase that failed to resolve.
 * XenForo's resource standards require a prefix on any column added to a core table, and a form
 * field is named after its column - so the assertion has to tell a field name apart from a key
 * that rendered because nothing could resolve it.
 */
class UnresolvedPhraseSurfaceTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function makeTemplate($title, $contents)
	{
		$this->createEntity('XF:Template', [
			'type' => 'public',
			'title' => $title,
			'style_id' => 0,
			'template' => $contents,
			'addon_id' => '',
		]);
	}

	private function makePhrase($title, $text)
	{
		$this->createEntity('XF:Phrase', [
			'language_id' => 0,
			'title' => $title,
			'phrase_text' => $text,
			'addon_id' => '',
		]);
	}

	public function test_a_field_named_after_a_resolving_phrase_is_not_reported()
	{
		$this->makePhrase('zzsurface_hide_adverts', 'Hide adverts');
		$this->makeTemplate(
			'zzsurface_form',
			'<label for="zzsurface_hide_adverts">{{ phrase(\'zzsurface_hide_adverts\') }}</label>'
			. '<input id="zzsurface_hide_adverts" name="option[zzsurface_hide_adverts]" value="1" />'
		);

		$html = $this->renderTemplate('public:zzsurface_form');

		// the positive beside the negative - the phrase plainly resolved
		$this->assertSee($html, 'Hide adverts');
		$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
	}

	public function test_a_css_class_carrying_the_prefix_is_not_reported()
	{
		$this->makePhrase('zzsurface_title', 'Adverts');
		$this->makeTemplate(
			'zzsurface_classes',
			'<div class="zzsurface_wrapper zzsurface_block">{{ phrase(\'zzsurface_title\') }}</div>'
		);

		$html = $this->renderTemplate('public:zzsurface_classes');

		$this->assertSee($html, 'Adverts');
		$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
	}

	public function test_a_prefix_inside_a_script_block_is_not_reported()
	{
		$this->makeTemplate(
			'zzsurface_script',
			'<div>Fine</div><script>var x = "zzsurface_some_handler";</script>'
		);

		$html = $this->renderTemplate('public:zzsurface_script');

		$this->assertSee($html, 'Fine');
		$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
	}

	public function test_a_key_that_ends_exactly_at_the_prefix_is_reported()
	{
		// the shape of every key XenForo structures for an add-on - the add-on's id comes last, so
		// the key is the prefix and nothing more
		$this->makePhrase('zzsurface_heading', 'Signatures');
		$this->makeTemplate(
			'zzsurface_group',
			'<h1>{{ phrase(\'zzsurface_heading\') }}</h1>'
			. '<p>{{ phrase(\'option_group.zzsurface\') }}</p>'
		);

		$html = $this->renderTemplate('public:zzsurface_group');

		// the positive beside the negative - the defined phrase plainly resolved
		$this->assertSee($html, 'Signatures');

		try
		{
			$this->assertNoUnresolvedPhrases($html, 'zzsurface');
			$this->fail('a key ending at the prefix was not reported');
		}
		catch (AssertionFailedError $e)
		{
			$this->assertStringContainsString('option_group.zzsurface', $e->getMessage());
		}
	}

	public function test_the_reported_key_carries_the_segment_in_front_of_the_prefix()
	{
		// a reader handed only the tail cannot grep for it, so the dotted segment is part of what
		// the failure names
		$this->makeTemplate(
			'zzsurface_tail',
			'<p>{{ phrase(\'option_explain.zzsurface_thing\') }}</p>'
		);

		$html = $this->renderTemplate('public:zzsurface_tail');

		try
		{
			$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
			$this->fail('an unresolved phrase was not reported');
		}
		catch (AssertionFailedError $e)
		{
			$this->assertStringContainsString('option_explain.zzsurface_thing', $e->getMessage());
		}
	}

	public function test_a_segment_that_is_not_identifier_shaped_is_not_taken_into_the_key()
	{
		// a phrase key's segment is an identifier, so a number in front of the prefix is prose
		$this->makeTemplate(
			'zzsurface_digits',
			'<p>2.{{ phrase(\'zzsurface_numbered\') }}</p>'
		);

		$html = $this->renderTemplate('public:zzsurface_digits');

		try
		{
			$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
			$this->fail('an unresolved phrase was not reported');
		}
		catch (AssertionFailedError $e)
		{
			$this->assertStringContainsString('zzsurface_numbered', $e->getMessage());
			$this->assertStringNotContainsString('2.zzsurface_numbered', $e->getMessage());
		}
	}

	public function test_the_prefix_inside_a_longer_word_is_not_reported()
	{
		$this->makePhrase('zzsurface_label', 'Enabled');
		$this->makeTemplate(
			'zzsurface_midword',
			'<p>{{ phrase(\'zzsurface_label\') }} notzzsurface_inner</p>'
		);

		$html = $this->renderTemplate('public:zzsurface_midword');

		$this->assertSee($html, 'Enabled');
		$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
	}

	public function test_a_phrase_that_really_did_not_resolve_is_still_reported()
	{
		$this->makeTemplate(
			'zzsurface_missing',
			'<label>{{ phrase(\'zzsurface_never_defined\') }}</label>'
		);

		$html = $this->renderTemplate('public:zzsurface_missing');

		// XenForo renders the key itself, as visible text
		$this->assertSee($html, 'zzsurface_never_defined');

		try
		{
			$this->assertNoUnresolvedPhrases($html, 'zzsurface_');
			$this->fail('an unresolved phrase in the displayed text was not reported');
		}
		catch (AssertionFailedError $e)
		{
			$this->assertStringContainsString('zzsurface_never_defined', $e->getMessage());
			$this->assertStringContainsString('the rendered output', $e->getMessage());
		}
	}
}
