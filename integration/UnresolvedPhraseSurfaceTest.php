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
