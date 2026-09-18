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
	 * Measured on 2.3.12: a missing title, a wrong type and a type that does not exist all render
	 * as an empty string with no error, so an assertDontSee() against one would have passed while
	 * testing nothing.
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
}
