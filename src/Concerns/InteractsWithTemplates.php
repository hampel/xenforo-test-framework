<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;

trait InteractsWithTemplates
{
	/**
	 * What XF\Template\Templater::handleTemplateException() renders in place of a template that
	 * threw, when the forum is in debug mode. Outside debug mode it renders an empty string.
	 */
	private const RENDER_ERROR_MARKUP = '<h3>Template Compilation Error</h3>';

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

		$templater = $this->app()->templater();
		$errorsBefore = count($templater->getTemplateErrors());

		$html = (string) $templater->renderTemplate($template, $params);

		if ($html === '')
		{
			// XenForo renders a name it cannot find as an empty string, so a typo in the title or
			// the type would leave assertDontSee() passing against nothing. Only checked on the
			// empty path: a template that legitimately renders to nothing stays legal, and the
			// usual case costs no query.
			$this->requireTemplateExists($template);
		}

		$this->requireRenderSucceeded($errorsBefore, $html, $template);

		return $html;
	}

	/**
	 * Render one macro out of a template, with the arguments a caller would pass it.
	 *
	 * Worth reaching for when the markup you care about is a macro an add-on adds to a template,
	 * or when rendering the whole template needs parameters the test has no reason to build.
	 *
	 * @param string $template - `type:title`, the template the macro is declared in
	 * @param string $macro - the macro's name, as in `<xf:macro name="...">`
	 * @param array $arguments - the macro's arguments, by name
	 *
	 * @return string
	 */
	protected function renderMacro($template, $macro, array $arguments = [])
	{
		if (strpos($template, ':') === false)
		{
			throw new \LogicException(
				"Template '$template' needs its type - 'public:$template' or 'admin:$template'."
				. ' A macro named in a template XenForo cannot find renders as an empty string'
				. ' rather than failing, so the type is not guessed for you.'
			);
		}

		$templater = $this->app()->templater();
		$errorsBefore = count($templater->getTemplateErrors());

		$html = (string) $templater->renderMacro($template, $macro, $arguments);

		// A macro that does not exist renders as an empty string, exactly as a missing template
		// does - but XenForo records 'Macro ... is unknown' while doing it, which is what this
		// reads. There is no empty-render check beyond that: a macro rendering nothing for the
		// arguments it was given is ordinary.
		$this->requireRenderSucceeded($errorsBefore, $html, "$template::$macro");

		return $html;
	}

	/**
	 * A page parameter the rendered template set, eg the title from `<xf:title>`.
	 *
	 * These do not appear in the rendered HTML, because the markup around them belongs to the page
	 * wrapper rather than to the template - so this is how a test asserts on one. Call it after a
	 * render; the values accumulate on the templater as each template sets them.
	 *
	 * The names are XenForo's own: `pageTitle` for `<xf:title>`, `pageDescription` for
	 * `<xf:description>`, `pageAction` for `<xf:pageaction>` and `pageH1` for `<xf:h1>`.
	 *
	 * @param string $name
	 *
	 * @return string|null - null if the render never set it
	 */
	protected function pageParam($name)
	{
		$params = $this->app()->templater()->pageParams;

		// XenForo stores these pre-escaped, as objects rather than strings
		return isset($params[$name]) ? (string) $params[$name] : null;
	}

	/**
	 * Refuse a render that XenForo swallowed entirely.
	 *
	 * **The templater catches everything.** A template that raises a PHP error, names a macro or a
	 * template that does not exist, or throws part way through is logged and rendering carries on -
	 * so the failure arrives as output rather than as a failure.
	 *
	 * This refuses only the case where nothing came back, because that is the one where an assertion
	 * is testing nothing: assertDontSee() passes against an empty string, and assertSee() fails
	 * pointing at the wrong cause. **A render that errored and still produced markup is returned**,
	 * deliberately - it is much the commoner case, a test asserting on markup that is really there
	 * passes for a good enough reason, and failing it here would turn working suites red on a patch
	 * release. assertNoTemplateErrors() is the opt-in for that.
	 *
	 * The exception half is separate and only fires in debug mode: a template that *throws* renders
	 * as the exception's markup, which is never output worth asserting against. Without debug mode
	 * it renders as an empty string, which the first half catches.
	 *
	 * @param int $errorsBefore - how many errors the templater had recorded before the render
	 * @param string $html
	 * @param string $describe - the template or macro, for the message
	 *
	 * @return void
	 */
	private function requireRenderSucceeded($errorsBefore, $html, $describe)
	{
		if (strpos($html, self::RENDER_ERROR_MARKUP) !== false)
		{
			throw new \LogicException(
				"Rendering '$describe' threw, and XenForo rendered the exception as markup instead of"
				. ' failing: ' . trim(strip_tags($html))
			);
		}

		if ($html !== '')
		{
			return;
		}

		$errors = array_slice($this->app()->templater()->getTemplateErrors(), $errorsBefore);

		if ($errors)
		{
			throw new \LogicException(
				"Rendering '$describe' produced nothing, because it raised " . count($errors)
				. ' error(s) that XenForo logged rather than raised: '
				. implode('; ', array_map([$this, 'describeTemplateError'], $errors))
			);
		}
	}

	/**
	 * Assert that no template this test rendered raised an error.
	 *
	 * The strict form of the guard above, and opt-in for the reason given there: **a template
	 * missing a parameter it reads usually renders most of its markup anyway.** Measured on 2.3.12:
	 * rendering 400 core templates with no parameters raised an error in 103 of them, and 101 of
	 * those still produced over 50 characters - so failing every one would break tests that assert
	 * on markup which is really there.
	 *
	 * Reach for it when you want the render to be right rather than merely to contain what you
	 * asserted, which for a template of your own rendered with its controller's parameters is a
	 * reasonable thing to want.
	 *
	 * @return void
	 */
	protected function assertNoTemplateErrors()
	{
		$errors = $this->app()->templater()->getTemplateErrors();

		PHPUnit::assertSame(
			[],
			array_map([$this, 'describeTemplateError'], $errors),
			'XenForo logs a template error and carries on rendering, so these did not fail the render'
		);
	}

	/**
	 * @param array $error - as XF\Template\Templater::getTemplateErrors() records it
	 *
	 * @return string
	 */
	private function describeTemplateError(array $error)
	{
		return $error['error'] . ' in ' . $error['template'];
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
