<?php

namespace Hampel\Testing\Integration;

/**
 * renderBbCode() and mockFind().
 */
class BbCodeRenderTest extends TestCase
{
	public function test_html_wraps_and_simple_html_does_not()
	{
		$this->assertSame(
			'<div class="bbWrapper"><b>bold</b></div>',
			trim($this->renderBbCode('[b]bold[/b]'))
		);

		$this->assertSame('<b>bold</b>', trim($this->renderBbCode('[b]bold[/b]', 'simpleHtml')));
	}

	public function test_the_output_can_be_asserted_on_in_part()
	{
		$html = $this->renderBbCode('[url]https://example.invalid/[/url]');

		$this->assertSee($html, 'example.invalid');
		$this->assertNoTemplateErrors();
	}

	public function test_bb_code_clean_returns_bb_code()
	{
		$this->assertSame('[b]bold[/b]', trim($this->renderBbCode('[b]bold[/b]', 'bbCodeClean')));
	}

	public function test_empty_bb_code_renders_an_empty_wrapper()
	{
		// the wrapper is still produced, so the guard for an empty render never sees empty input
		$this->assertSame('<div class="bbWrapper"></div>', trim($this->renderBbCode('')));
	}

	public function test_mock_find_returns_the_entity_given()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'Found']);

		$this->mockFind('XF:User', 99, $user);

		$this->assertSame($user, $this->app()->em()->find('XF:User', 99));
	}

	public function test_mock_find_can_report_a_missing_record()
	{
		$this->mockFind('XF:User', 1, null);

		// user 1 exists on this forum, so a null here is the mock being consulted
		$this->assertNull($this->app()->em()->find('XF:User', 1));
	}
}
