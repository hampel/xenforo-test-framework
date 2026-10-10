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
}
