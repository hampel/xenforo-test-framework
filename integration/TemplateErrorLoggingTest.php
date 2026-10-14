<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * A template error reaches the forum's real error log, and fakesErrors() is how a test opts out.
 * Both halves are asserted, since the positive alone would pass if renderTemplate() stopped
 * logging.
 *
 * The class runs in a transaction, so the rows the control writes are rolled back.
 */
class TemplateErrorLoggingTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_fakes_errors_keeps_template_errors_out_of_the_error_log()
	{
		$this->fakesErrors();
		$template = $this->givenATemplateThatErrors();

		$before = $this->errorLogRows();
		$html = $this->renderTemplate($template);
		$after = $this->errorLogRows();

		$this->assertSame($before, $after, 'fakesErrors() should keep the rows out of xf_error_log');

		// and the errors are still there to assert on - the fake hides them from the forum, not
		// from the test, which is what makes opting out cheap
		$this->assertNotEmpty($this->app()->templater()->getTemplateErrors());
		$this->assertNotSame('', $html);
	}

	public function test_without_the_fake_the_rows_really_are_written()
	{
		$template = $this->givenATemplateThatErrors();

		$before = $this->errorLogRows();
		$this->renderTemplate($template);
		$after = $this->errorLogRows();

		$this->assertGreaterThan(
			$before,
			$after,
			'the control: without fakesErrors() a template error reaches the real logger'
		);
	}

	/**
	 * A template of this test's own that raises a template error when rendered.
	 *
	 * This used to render a core template, on the belief that it could not render cleanly without
	 * a visitor. It renders cleanly: on a forum with no third-party template modifications it
	 * raises nothing at all, and the errors that made this test pass were other add-ons' - a
	 * modification reading a column its own entity extension declares, which isolation leaves
	 * absent. So the test asserted its own premise only on one long-lived install.
	 *
	 * @return string - the type:title to render
	 */
	private function givenATemplateThatErrors()
	{
		$title = 'zzerrorlog_' . substr(md5(uniqid('', true)), 0, 6);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			// XenForo only allows a template to call a method whose name reads as an accessor
			// (XF\Util\Php::nameIndicatesReadOnly), so the identifier goes after a `get_`
			'template' => '<div>{{ $nope.get_zzerrorlog_marker() }}</div>',
			'addon_id' => '',
		]);

		return 'public:' . $title;
	}

	/**
	 * @return int
	 */
	private function errorLogRows()
	{
		return (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_error_log');
	}
}
