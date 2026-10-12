<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * assertNoTemplateErrors()'s filter, which is what makes it usable on a development forum shared
 * with other add-ons: template modifications compile into the install's shared template cache
 * whatever $addonsToLoad says, so a sibling's can raise an error on every render here.
 */
class TemplateErrorFilterTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function renderATemplateThatErrors()
	{
		$this->fakesErrors();
		$title = 'myaddon_broken_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			'template' => '<div>{{ $nope.doesNotExist() }}</div>',
			'addon_id' => '',
		]);

		$this->renderTemplate('public:' . $title);

		// the render records an array per error - ['template' => …, 'error' => …, 'file' => …] -
		// which is why a filter written against the row as a string is a TypeError
		$this->assertNotEmpty(
			$this->app()->templater()->getTemplateErrors(),
			'the render should have recorded an error for these tests to filter'
		);
	}

	public function test_the_blanket_assertion_sees_an_error_from_any_template()
	{
		$this->renderATemplateThatErrors();

		$this->expectException(ExpectationFailedException::class);

		$this->assertNoTemplateErrors();
	}

	public function test_a_filter_matching_nothing_we_own_passes()
	{
		$this->renderATemplateThatErrors();

		// which is the point: our own templates are clean while somebody else's is not
		$this->assertNoTemplateErrors('someoneelse_');
	}

	public function test_a_filter_matching_our_own_template_still_fails()
	{
		$this->renderATemplateThatErrors();

		$this->expectException(ExpectationFailedException::class);

		$this->assertNoTemplateErrors('myaddon_');
	}

	public function test_the_filter_can_be_a_list()
	{
		$this->renderATemplateThatErrors();

		$this->expectException(ExpectationFailedException::class);

		$this->assertNoTemplateErrors(['someoneelse_', 'myaddon_']);
	}

	/**
	 * The shape an add-on that owns no templates is in: its only markup is a modification of a
	 * core template, so the error it is answerable for is raised while rendering somebody else's.
	 */
	private function renderSomeoneElsesTemplateWithOurErrorInIt()
	{
		$this->fakesErrors();
		$title = 'zzsibling_page_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			// XenForo only allows a template to call a method whose name reads as an accessor
			// (XF\Util\Php::nameIndicatesReadOnly), so the identifier goes after a `get_`
			'template' => '<div>{{ $nope.get_myaddon_marker() }}</div>',
			'addon_id' => '',
		]);

		$this->renderTemplate('public:' . $title);

		$errors = $this->app()->templater()->getTemplateErrors();

		$this->assertNotEmpty($errors, 'the render should have recorded an error to filter');
		$this->assertStringContainsString(
			'get_myaddon_marker',
			$errors[0]['error'],
			'the error text should carry our identifier for these tests to find'
		);
	}

	public function test_a_name_filter_cannot_see_an_error_raised_in_somebody_elses_template()
	{
		$this->renderSomeoneElsesTemplateWithOurErrorInIt();

		// the template is not ours, so no name we own matches it - which is why the closure form
		// exists rather than this being the whole story
		$this->assertNoTemplateErrors('myaddon_');
	}

	public function test_a_closure_filter_sees_an_error_by_its_text()
	{
		$this->renderSomeoneElsesTemplateWithOurErrorInIt();

		$this->expectException(ExpectationFailedException::class);

		$this->assertNoTemplateErrors(function (array $error)
		{
			return strpos($error['error'], 'myaddon_') !== false;
		});
	}

	public function test_a_closure_that_keeps_nothing_passes_although_the_render_errored()
	{
		$this->renderSomeoneElsesTemplateWithOurErrorInIt();

		$this->assertNoTemplateErrors(function (array $error)
		{
			return false;
		});
	}

	public function test_a_string_naming_a_php_function_is_still_matched_as_a_template_name()
	{
		$this->fakesErrors();
		$title = 'myaddon_header_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			'template' => '<div>{{ $nope.doesNotExist() }}</div>',
			'addon_id' => '',
		]);

		$this->renderTemplate('public:' . $title);

		// `header` is a PHP function as well as a template name, so a filter that decided by
		// whether the value is callable would call it instead of matching the name
		$this->expectException(ExpectationFailedException::class);

		$this->assertNoTemplateErrors('header');
	}

	public function test_a_filter_list_refuses_a_value_that_is_not_a_string()
	{
		$this->renderATemplateThatErrors();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('takes a template name, a list of names, or a Closure');

		$this->assertNoTemplateErrors(['myaddon_', 42]);
	}

	public function test_a_supplied_message_is_carried_without_hiding_the_errors()
	{
		$this->renderATemplateThatErrors();

		try
		{
			$this->assertNoTemplateErrors('myaddon_', 'zzthe render is missing its wrapper');
			$this->fail('the recorded template error should have failed the assertion');
		}
		catch (ExpectationFailedException $e)
		{
			$this->assertStringContainsString('zzthe render is missing its wrapper', $e->getMessage());

			// prefixed, not substituted: what the assertion itself says survives beside it. The
			// errors are the asserted value rather than part of the message, so they reach the
			// reader through PHPUnit's comparison either way
			$this->assertStringContainsString('carries on rendering', $e->getMessage());
		}
	}
}
