<?php

namespace Hampel\Testing\Integration;

/**
 * Only a real request sets a style on the templater, so without one of our own every template
 * saw `$xf.style` as null - and a core template calling a method on it logged an error and
 * rendered nothing for that block, which is markup a test could then assert was absent.
 */
class TemplateStyleTest extends TestCase
{
	public function test_a_public_render_has_a_style_and_no_style_error()
	{
		$this->fakesErrors();
		$this->actingAsMember();

		$html = $this->renderReply($this->dispatch('account/preferences'));

		$this->assertNotNull(
			$this->app()->templater()->getStyle(),
			'the templater was left without a style'
		);

		$errors = array_filter(
			$this->app()->templater()->getTemplateErrors(),
			function (array $error)
			{
				return strpos($error['error'], 'isVariationsEnabled') !== false;
			}
		);

		$this->assertSame([], $errors, 'a core template failed on a null $xf.style');

		// the positive beside the negative: the block that was silently not rendering
		$this->assertSee($html, 'account/preferences');
	}

	/**
	 * The style has to be the master one, whose id is 0 and which is already the templater's
	 * default. Installing the visitor's own style instead moves the templater to that style id,
	 * where no `admin:` template exists - so this fails with "Template admin:option_group_list is
	 * unknown" rather than anything mentioning styles.
	 */
	public function test_an_admin_render_still_finds_its_own_templates()
	{
		$this->fakesErrors();
		$this->actingAsAdministrator(['option' => true], ['is_super_admin' => true]);

		$html = $this->renderReply($this->dispatch('options', 'admin'));

		$this->assertNotNull($this->app()->templater()->getStyle());
		$this->assertNoTemplateErrors();
		$this->assertSee($html, 'option_group_list', false);
	}

	public function test_a_style_the_test_installed_is_not_replaced()
	{
		$mine = $this->app()->container()['style.fallback'];
		$this->app()->templater()->setStyle($mine);

		$this->renderTemplate('public:login');

		$this->assertSame($mine, $this->app()->templater()->getStyle());
	}
}
