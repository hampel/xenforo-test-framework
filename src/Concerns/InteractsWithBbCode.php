<?php namespace Hampel\Testing\Concerns;


trait InteractsWithBbCode
{
	/**
	 * Type options:
	 *  bbCodeClean - renders a cleaned version of the BBCode itself
	 *  editorHtml - a blended HTML and BBCode version for display in the editor
	 *  emailHtml - a simplified HTML suitable for display in emails
	 *  html - the default fully rendered HTML output for browsers
	 *  simpleHtml - a simplified HTML suitable for display in signatures and so on
	 *
	 * @param string $expectedHtml - the htmlOutput you expect to receive
	 * @param string $bbCode - the bbCode you want to render
	 * @param string $type - (optional) the type of output to render - see list above. Defaults to 'html'
	 * @param string $context - (optional) the context
	 * @param mixed $content - (optional) the content (usually entity) being rendered
	 */
	protected function assertBbCode($expectedHtml, $bbCode, $type = 'html', $context = 'unitTest', $content = null)
	{
		$this->assertEquals($expectedHtml, $this->app()->bbCode()->render($bbCode, $type, $context, $content));
	}
}
