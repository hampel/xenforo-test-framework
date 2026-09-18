<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;

trait InteractsWithTemplates
{
	/** app.classType => the template type XenForo stores templates under */
	private const TEMPLATE_TYPES = [
		'Pub' => 'public',
		'Admin' => 'admin',
	];

	/**
	 * Render a template and return its HTML, without a web server.
	 *
	 * This is what covers the checks a reply cannot: that a template modification actually
	 * applied, and that a phrase resolved rather than rendering as a raw key.
	 *
	 * @param string $template - `type:title`, eg 'public:thread_view' - the three types XenForo
	 *                           stores are `public`, `admin` and `email`
	 * @param array $params - the parameters the template reads
	 *
	 * @return string
	 */
	protected function renderTemplate($template, array $params = [])
	{
		if (strpos($template, ':') === false)
		{
			throw new \LogicException(
				"Template '$template' needs its type - 'public:$template', 'admin:$template' or"
				. " 'email:$template'. A name with the wrong type renders as an empty string"
				. ' rather than failing, so the type is not guessed for you.'
			);
		}

		$html = (string) $this->app()->templater()->renderTemplate($template, $params);

		if ($html === '')
		{
			// XenForo renders a name it cannot find as an empty string, so a typo in the title or
			// the type would leave assertDontSee() passing against nothing. Only checked on the
			// empty path: a template that legitimately renders to nothing stays legal, and the
			// usual case costs no query.
			$this->requireTemplateExists($template);
		}

		return $html;
	}

	/**
	 * @param string $template - `type:title`
	 *
	 * @return void
	 */
	private function requireTemplateExists($template)
	{
		[$type, $title] = explode(':', $template, 2);

		$exists = $this->app()->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_template WHERE type = ? AND title = ?',
			[$type, $title]
		);

		if (!$exists)
		{
			throw new \LogicException(
				"Template '$template' does not exist in this forum, and XenForo renders a name it"
				. ' cannot find as an empty string rather than failing - so an assertion against'
				. ' it would have passed while testing nothing. Check the type and the title, and'
				. ' remember an add-on template only exists here once the add-on is installed.'
			);
		}
	}

	/**
	 * Render the template a dispatched reply named, with the parameters it passed.
	 *
	 * The reply carries a bare template name; the type comes from the app class type dispatch()
	 * swapped in, so call this after a dispatch rather than before one.
	 *
	 * This renders the **template**, not the page: there is no navigation, header or footer
	 * around it, because those come from the Pub and Admin app classes rather than from the
	 * template. Assertions about a page's furniture are still a job for a browser or a request
	 * against a real forum.
	 *
	 * @param AbstractReply $reply - as returned by dispatch()
	 *
	 * @return string
	 */
	protected function renderReply(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(View::class, $reply, 'only a view reply names a template');

		/** @var View $reply */
		$classType = $this->app()->container('app.classType');

		if (!isset(self::TEMPLATE_TYPES[$classType]))
		{
			throw new \LogicException(
				"Cannot render a reply while the app class type is '$classType' - dispatch() sets"
				. ' it to Pub or Admin, so render the reply from the same test that dispatched it.'
			);
		}

		return $this->renderTemplate(
			self::TEMPLATE_TYPES[$classType] . ':' . $reply->getTemplateName(),
			$reply->getParams()
		);
	}

	/**
	 * Assert that a template modification is actually matching something.
	 *
	 * More direct than looking for its effect in rendered HTML, and it works for a modification
	 * whose insertion has no distinctive markup to search for.
	 *
	 * **XenForo logs a modification that matches nothing as `ok`.** The status only says the
	 * modification ran, not that its `find` still matches - so a modification silently stopped
	 * applying by a XenForo upgrade stays `ok` with an apply count of zero. The count is the part
	 * that answers the question.
	 *
	 * @param string $modificationKey - as in `_output/template_modifications/`
	 *
	 * @return void
	 */
	protected function assertTemplateModificationApplied($modificationKey)
	{
		$db = $this->app()->db();

		$modification = $db->fetchRow(
			'SELECT modification_id, type, template, enabled
				FROM xf_template_modification
				WHERE modification_key = ?',
			$modificationKey
		);

		if (!$modification)
		{
			PHPUnit::fail(
				"No template modification with the key '$modificationKey' exists in this forum."
				. ' These assertions read what the forum has, so the add-on has to be installed'
				. ' and its data imported - an edit to _output/ alone is invisible here.'
			);
		}

		$applied = (int) $db->fetchOne(
			'SELECT SUM(apply_count) FROM xf_template_modification_log WHERE modification_id = ?',
			$modification['modification_id']
		);

		PHPUnit::assertGreaterThan(
			0,
			$applied,
			"Template modification '$modificationKey' on {$modification['type']}:"
			. "{$modification['template']} applied 0 times"
			. ($modification['enabled'] ? '' : ' (and it is disabled)')
			. " - its find no longer matches. XenForo logs that as status 'ok', so only the apply"
			. ' count shows it.'
		);
	}

	/**
	 * Assert that rendered output contains the given text.
	 *
	 * @param string $html - as returned by renderTemplate() or renderReply()
	 * @param string $value
	 * @param bool $escape - escape the expected value the way a template would, which is what
	 *                       makes an apostrophe or an ampersand match
	 *
	 * @return void
	 */
	protected function assertSee($html, $value, $escape = true)
	{
		PHPUnit::assertStringContainsString(
			$this->escapeExpected($value, $escape),
			$html,
			$this->describeHtml($html)
		);
	}

	/**
	 * Assert that rendered output does not contain the given text.
	 *
	 * @param string $html
	 * @param string $value
	 * @param bool $escape
	 *
	 * @return void
	 */
	protected function assertDontSee($html, $value, $escape = true)
	{
		PHPUnit::assertStringNotContainsString(
			$this->escapeExpected($value, $escape),
			$html,
			$this->describeHtml($html)
		);
	}

	/**
	 * Assert that the text of rendered output contains the given text, ignoring the markup.
	 *
	 * Tags are stripped and entities decoded first, so this matches what a reader sees - 'Bob's
	 * thread' is found in markup that emitted `Bob&#039;s thread`, and text split across a tag
	 * boundary still matches.
	 *
	 * @param string $html
	 * @param string $value
	 *
	 * @return void
	 */
	protected function assertSeeText($html, $value)
	{
		PHPUnit::assertStringContainsString(
			$value,
			$this->textOf($html),
			$this->describeHtml($html)
		);
	}

	/**
	 * Assert that rendered output does not contain the given text, ignoring the markup.
	 *
	 * @param string $html
	 * @param string $value
	 *
	 * @return void
	 */
	protected function assertDontSeeText($html, $value)
	{
		PHPUnit::assertStringNotContainsString(
			$value,
			$this->textOf($html),
			$this->describeHtml($html)
		);
	}

	/**
	 * Assert that rendered output contains the given values, in the order given.
	 *
	 * @param string $html
	 * @param array $values
	 * @param bool $escape
	 *
	 * @return void
	 */
	protected function assertSeeInOrder($html, array $values, $escape = true)
	{
		$offset = 0;

		foreach ($values AS $value)
		{
			$expected = $this->escapeExpected($value, $escape);
			$position = strpos($html, $expected, $offset);

			PHPUnit::assertNotFalse(
				$position,
				"Failed asserting that '$expected' appears after the values before it. "
				. $this->describeHtml($html)
			);

			$offset = $position + strlen($expected);
		}
	}

	/**
	 * The readable text of rendered output, for asserting on what a reader sees.
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	protected function textOf($html)
	{
		$text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'utf-8');

		return trim(preg_replace('/\s+/', ' ', $text));
	}

	/**
	 * XenForo escapes template output with htmlspecialchars($value, ENT_QUOTES, 'utf-8') - see
	 * XF::escapeString() - so an expected value has to be escaped the same way to match.
	 *
	 * @param string $value
	 * @param bool $escape
	 *
	 * @return string
	 */
	private function escapeExpected($value, $escape)
	{
		return $escape ? htmlspecialchars((string) $value, ENT_QUOTES, 'utf-8') : (string) $value;
	}

	/**
	 * A failure message that says what was rendered, since the usual cause of a miss is an empty
	 * string - a template name with the wrong type renders as nothing rather than failing.
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	private function describeHtml($html)
	{
		$length = strlen($html);

		if ($length === 0)
		{
			return 'The rendered output was empty - check the template type prefix, since a name'
				. ' XenForo cannot find renders as an empty string rather than failing.';
		}

		return "Rendered $length characters.";
	}
}
