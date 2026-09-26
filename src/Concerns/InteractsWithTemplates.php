<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use XF\Http\Response;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;
use XF\Template\Templater;

trait InteractsWithTemplates
{
	/**
	 * The `xf` parameter this trait last installed, so one the test set itself can be told apart.
	 *
	 * @var array|null
	 */
	private $installedGlobalTemplateData = null;

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
		return $this->renderWithGlobalData($template, $params, null);
	}

	/**
	 * @param string $template
	 * @param array $params
	 * @param AbstractReply|null $reply - passed on to getGlobalTemplateData(), as XenForo does
	 *
	 * @return string
	 */
	private function renderWithGlobalData($template, array $params, ?AbstractReply $reply)
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
		$this->installGlobalTemplateData($templater, $reply);
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
		$this->installGlobalTemplateData($templater, null);
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
	 * Install the `xf` template parameter - $xf.options, $xf.visitor, $xf.time and the rest - as
	 * XenForo's App::preRender() does, from getGlobalTemplateData(). Rebuilt for every render, so
	 * $xf.visitor follows actingAs(). An `xf` parameter the test installed itself is left alone.
	 *
	 * @param Templater $templater
	 * @param AbstractReply|null $reply
	 *
	 * @return void
	 */
	/**
	 * Render BB code and return the HTML, for asserting on part of it.
	 *
	 * assertBbCode() compares the whole output, which is no use for a tag that renders through a
	 * template - there is too much markup to match exactly. This hands back the string for
	 * assertSee() and friends, and for assertNoTemplateErrors().
	 *
	 * @param string $bbCode
	 * @param string $type - html (the default), simpleHtml, emailHtml, bbCodeClean or editorHtml
	 * @param string $context
	 * @param mixed $content - the content being rendered, typically an entity
	 *
	 * @return string
	 */
	protected function renderBbCode($bbCode, $type = 'html', $context = 'unitTest', $content = null)
	{
		$templater = $this->app()->templater();
		$this->installGlobalTemplateData($templater, null);
		$errorsBefore = count($templater->getTemplateErrors());

		$html = (string) $this->app()->bbCode()->render($bbCode, $type, $context, $content);

		// only fires on an empty render that also logged an error, so BB code that legitimately
		// renders to nothing is returned as it is
		$this->requireRenderSucceeded($errorsBefore, $html, "BB code '$bbCode' as $type");

		return $html;
	}

	/**
	 * Render a reply through the raw renderer and return the response it produced.
	 *
	 * A file download or other raw view builds its body and its headers in renderRaw(), which the
	 * HTML renderer never calls - so renderReply() cannot see either. The body is set on the
	 * response, so one object carries both.
	 *
	 * @param AbstractReply $reply - a view reply from dispatch() or callAction()
	 *
	 * @return Response
	 */
	protected function renderRawReply(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(View::class, $reply, 'only a view reply names a view class');

		/** @var View $reply */
		$renderer = $this->app()->renderer('raw');
		$templateName = $reply->getTemplateName();
		$params = $reply->getParams();

		$body = $renderer->renderView($reply->getViewClass(), $templateName, $params);

		$response = $this->app()->response();
		$response->body($body);

		return $response;
	}

	/**
	 * Assert that every phrase in the rendered output resolved.
	 *
	 * A phrase XenForo cannot find renders as its own key, so an add-on's phrase keys appearing in
	 * the output means a phrase is missing. Searching for the key alone is not enough: for an
	 * administrator with the embedTemplateNames option on, the templater writes each template's own
	 * name into its first tag, and that name carries your prefix too. Those attributes are stripped
	 * before the search.
	 *
	 * Pair this with an assertion that the text you expect IS present - output that never rendered
	 * carries no unresolved key either.
	 *
	 * @param string $html
	 * @param string $prefix - your add-on's phrase prefix, eg `myaddon_`
	 * @param string $message
	 *
	 * @return void
	 */
	protected function assertNoUnresolvedPhrases($html, $prefix, $message = '')
	{
		$stripped = preg_replace('/\sdata-(template|inner-template)-name="[^"]*"/i', '', $html);

		preg_match_all('/\b' . preg_quote($prefix, '/') . '[a-z0-9_]+/i', $stripped, $matches);

		$keys = array_values(array_unique($matches[0]));

		PHPUnit::assertSame(
			[],
			$keys,
			$message ?: 'these phrases did not resolve and rendered as their own keys: ' . implode(', ', $keys)
		);
	}

	private function installGlobalTemplateData(Templater $templater, ?AbstractReply $reply)
	{
		$defaultParams = new \ReflectionProperty(Templater::class, 'defaultParams');
		$defaultParams->setAccessible(true);
		$current = $defaultParams->getValue($templater)['xf'] ?? null;

		if ($current !== null && $current !== $this->installedGlobalTemplateData)
		{
			return;
		}

		$data = $this->app()->getGlobalTemplateData($reply);
		$templater->addDefaultParam('xf', $data);
		$this->installedGlobalTemplateData = $data;
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
	 * The strict form of the guard above. It is opt-in because a template missing a parameter
	 * usually still renders most of its markup.
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

		return $this->renderWithGlobalData(
			self::TEMPLATE_TYPES[$classType] . ':' . $reply->getTemplateName(),
			$reply->getParams(),
			$reply
		);
	}

	/**
	 * Assert that a template modification is actually matching something.
	 *
	 * A modification whose `find` matches nothing is still logged with status `ok`, so this reads
	 * its apply count instead.
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
