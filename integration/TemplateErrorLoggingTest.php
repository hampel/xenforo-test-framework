<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * A template error travels to the forum's real error log, and fakesErrors() is how a test opts
 * out. Both halves are asserted here because the positive one alone would pass if renderTemplate()
 * had simply stopped logging - which is the unpaired-assertion shape this package keeps shipping.
 *
 * The whole class runs in a transaction, so the control's rows are rolled back with everything
 * else and the forum keeps none of them. That is also, and accidentally, why this package never
 * noticed the behaviour: TemplateRenderTest uses the same trait for an unrelated reason, so its
 * own logging has always been invisible. A consumer suite without it wrote 71 rows a run.
 */
class TemplateErrorLoggingTest extends TestCase
{
	use UsesDatabaseTransactions;

	/** a core template that cannot render cleanly without a visitor, so it errors and carries on */
	private const ERRORING_TEMPLATE = 'public:account_preferences';

	public function test_fakes_errors_keeps_template_errors_out_of_the_error_log()
	{
		$this->fakesErrors();

		$before = $this->errorLogRows();
		$html = $this->renderTemplate(self::ERRORING_TEMPLATE);
		$after = $this->errorLogRows();

		$this->assertSame($before, $after, 'fakesErrors() should keep the rows out of xf_error_log');

		// and the errors are still there to assert on - the fake hides them from the forum, not
		// from the test, which is what makes opting out cheap
		$this->assertNotEmpty($this->app()->templater()->getTemplateErrors());
		$this->assertNotSame('', $html);
	}

	public function test_without_the_fake_the_rows_really_are_written()
	{
		$before = $this->errorLogRows();
		$this->renderTemplate(self::ERRORING_TEMPLATE);
		$after = $this->errorLogRows();

		$this->assertGreaterThan(
			$before,
			$after,
			'the control: without fakesErrors() a template error reaches the real logger'
		);
	}

	/**
	 * @return int
	 */
	private function errorLogRows()
	{
		return (int) $this->app()->db()->fetchOne('SELECT COUNT(*) FROM xf_error_log');
	}
}
