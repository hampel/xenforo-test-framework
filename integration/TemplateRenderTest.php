<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Rendering a template is what covers the two checks a reply cannot: that a template modification
 * applied, and that a phrase resolved rather than showing a raw key.
 *
 * The assertions take the html as an argument, so they are exercised against markup written here -
 * stable, and independent of whatever the forum's own templates happen to contain. Rendering is
 * exercised separately against real templates.
 */
class TemplateRenderTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_a_real_template_renders()
	{
		$html = $this->renderTemplate('public:login');

		$this->assertNotSame('', $html);
		$this->assertSee($html, 'login/login');
	}

	public function test_an_admin_template_renders()
	{
		$html = $this->renderTemplate('admin:option_group_list');

		$this->assertNotSame('', $html);
	}

	public function test_a_reply_renders_the_template_it_named()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		$reply = $this->dispatch('options', 'admin');
		$html = $this->renderReply($reply);

		$this->assertNotSame('', $html);
	}

	public function test_a_template_without_its_type_is_refused()
	{
		// the wrong type renders as an empty string rather than failing, so it is not guessed
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("needs its type");

		$this->renderTemplate('login');
	}

	/**
	 * A missing title, a wrong type and a type that does not exist all render as an empty string
	 * with no error, so renderTemplate() has to refuse them.
	 */
	public function test_a_template_that_does_not_exist_is_refused_rather_than_rendering_nothing()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('does not exist in this forum');

		$this->renderTemplate('public:no_such_template_xyz');
	}

	public function test_the_wrong_type_for_a_real_title_is_refused_too()
	{
		// 'login' is a public template; asking for it as admin renders nothing
		$this->expectException(\LogicException::class);

		$this->renderTemplate('admin:login');
	}

	public function test_an_unknown_template_modification_is_reported_as_missing()
	{
		$this->expectException(AssertionFailedError::class);
		$this->expectExceptionMessage('No template modification with the key');

		$this->assertTemplateModificationApplied('no_such_modification_xyz');
	}

	/**
	 * A modification logged as applying at least once. Skips rather than passing vacuously on a
	 * forum with no add-on modifications, since without one this proves nothing either way.
	 */
	public function test_a_modification_that_applies_is_recognised()
	{
		$key = $this->app()->db()->fetchOne(
			'SELECT m.modification_key
				FROM xf_template_modification m
				INNER JOIN xf_template_modification_log l ON l.modification_id = m.modification_id
				WHERE l.apply_count > 0
				LIMIT 1'
		);

		if (!$key)
		{
			$this->markTestSkipped('this forum has no template modification that applies');
		}

		$this->assertTemplateModificationApplied($key);
	}

	public function test_the_expected_value_is_escaped_the_way_a_template_escapes_it()
	{
		$html = '<p>Posted by Bob&#039;s account &amp; friends</p>';

		$this->assertSee($html, "Bob's account & friends");
		$this->assertDontSee($html, 'Someone else');
	}

	public function test_raw_matching_is_available_for_markup()
	{
		$html = '<div class="block">x</div>';

		$this->assertSee($html, '<div class="block">', false);
	}

	public function test_text_assertions_ignore_markup_and_entities()
	{
		$html = "<p>Bob&#039;s\n   <b>thread</b></p>";

		$this->assertSeeText($html, "Bob's thread");
		$this->assertDontSeeText($html, 'someone else');
	}

	public function test_order_is_asserted_not_just_presence()
	{
		$html = '<p>alpha</p><p>beta</p>';

		$this->assertSeeInOrder($html, ['alpha', 'beta']);

		$this->expectException(AssertionFailedError::class);
		$this->assertSeeInOrder($html, ['beta', 'alpha']);
	}

	/**
	 * The templater catches everything a template does wrong, logs it and renders an empty string.
	 * So the reason the assertion below fails is recorded on the templater rather than thrown, and
	 * without the guard the test gets '' and assertDontSee() passes on it.
	 */
	public function test_a_template_that_fails_while_rendering_is_refused()
	{
		$this->makeTemplate('probe_broken', '<xf:set var="$x" value="" />{{ $x.doesNotExist() }}');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Cannot call method doesNotExist');

		$this->renderTemplate('public:probe_broken');
	}

	public function test_a_template_that_renders_cleanly_raises_nothing()
	{
		$this->makeTemplate('probe_clean', '<p>hello</p>');

		$this->assertSee($this->renderTemplate('public:probe_clean'), 'hello');
	}

	public function test_a_macro_renders()
	{
		$this->makeTemplate(
			'probe_macros',
			'<xf:macro name="greeting" arg-who="world"><p>hello {$who}</p></xf:macro>'
		);

		$html = $this->renderMacro('public:probe_macros', 'greeting', ['who' => 'Bob']);

		$this->assertSee($html, 'hello Bob');
	}

	/** a macro that does not exist renders as an empty string, the same way a template does */
	public function test_a_macro_that_does_not_exist_is_refused()
	{
		$this->makeTemplate('probe_macros', '<xf:macro name="greeting"><p>hi</p></xf:macro>');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is unknown');

		$this->renderMacro('public:probe_macros', 'no_such_macro', []);
	}

	public function test_a_macro_needs_its_template_type()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('needs its type');

		$this->renderMacro('probe_macros', 'greeting', []);
	}

	/**
	 * A page title never appears in the rendered template - the markup around it belongs to the
	 * page wrapper - so reading it back is the only way to assert on one.
	 */
	public function test_the_page_parameters_a_template_set_are_readable()
	{
		$this->makeTemplate('probe_titled', '<xf:title>Members of the board</xf:title><p>body</p>');

		$html = $this->renderTemplate('public:probe_titled');

		$this->assertSee($html, 'body');
		$this->assertDontSee($html, 'Members of the board');
		$this->assertSame('Members of the board', $this->pageParam('pageTitle'));
	}

	public function test_a_page_parameter_the_render_never_set_is_null()
	{
		$this->makeTemplate('probe_untitled', '<p>body</p>');
		$this->renderTemplate('public:probe_untitled');

		$this->assertNull($this->pageParam('pageAction'));
	}

	/**
	 * A template usually renders most of its markup even when part of it fails, so refusing every
	 * render that logged an error would fail tests that assert on markup which is really there.
	 * That case is left to assertNoTemplateErrors(), and this pins both halves of the decision.
	 */
	public function test_a_render_that_errors_but_still_produces_markup_is_returned()
	{
		$this->makeTemplate(
			'probe_partial',
			'<p>kept</p><xf:set var="$x" value="" />{{ $x.doesNotExist() }}<p>also kept</p>'
		);

		$html = $this->renderTemplate('public:probe_partial');

		$this->assertSee($html, 'kept');
		$this->assertSee($html, 'also kept');
	}

	public function test_the_strict_assertion_catches_the_same_render()
	{
		$this->makeTemplate(
			'probe_partial',
			'<p>kept</p><xf:set var="$x" value="" />{{ $x.doesNotExist() }}'
		);

		$this->renderTemplate('public:probe_partial');

		$this->expectException(AssertionFailedError::class);

		$this->assertNoTemplateErrors();
	}

	public function test_the_strict_assertion_passes_on_a_clean_render()
	{
		$this->makeTemplate('probe_clean', '<p>hello</p>');

		$this->renderTemplate('public:probe_clean');

		$this->assertNoTemplateErrors();
	}

	/**
	 * Templates are created here rather than assumed of the forum, so the tests do not depend on
	 * what a core template happens to contain. The transaction takes them away again.
	 *
	 * @param string $title
	 * @param string $content
	 *
	 * @return void
	 */
	private function makeTemplate($title, $content)
	{
		$this->createEntity('XF:Template', [
			'type' => 'public',
			'title' => $title,
			'style_id' => 0,
			'template' => $content,
			'addon_id' => '',
		]);
	}
}
