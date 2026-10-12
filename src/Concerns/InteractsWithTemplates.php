<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use XF\Entity\TemplateModification;
use XF\Http\Response;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\View;
use XF\Repository\TemplateModificationRepository;
use XF\Style;
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
	 * Render a reply through the JSON renderer and return the decoded document.
	 *
	 * For an endpoint whose view shapes its own response in renderJson(). renderRawReply() cannot
	 * stand in: the raw renderer asks the view for renderRaw(), which a JSON view does not have, so
	 * the body comes back empty.
	 *
	 * @param AbstractReply $reply - a view reply from dispatch() or callAction()
	 *
	 * @return array - what the view returned from renderJson()
	 */
	protected function renderJsonReply(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(View::class, $reply, 'only a view reply names a view class');

		/** @var View $reply */
		if ($this->app()->container('app.classType') === 'Cli')
		{
			throw new \LogicException(
				'The application class type is still Cli, so a view class cannot be resolved and'
				. " XenForo would answer with its own empty document instead of your view's."
				. " Dispatch the route first, or call setAppClassType('public')."
			);
		}

		$renderer = $this->app()->renderer('json');

		// asked before rendering, because XF falls back to a document of its own when the view cannot
		// answer: status ok, plus the template rendered as html. It is well formed and carries
		// nothing of the view's, so a loose assertion passes against it. Do not detect it by shape -
		// its content is empty for a view with no template name, which is exactly the shape a
		// JSON-only endpoint has, and full for one with a template
		$view = $renderer->createViewObject(
			$reply->getViewClass(),
			$reply->getTemplateName(),
			$reply->getParams()
		);

		if (!method_exists($view, 'renderJson'))
		{
			// a view class that does not exist for this application type resolves to XF\Mvc\View,
			// which has no renderJson() either - so one check covers both, and naming what was
			// resolved is what tells the two apart
			throw new \LogicException(
				"'" . $reply->getViewClass() . "' cannot answer JSON: it resolved to "
				. get_class($view) . ', which has no renderJson(), so XenForo would answer with a'
				. ' document of its own instead. A view class that does not exist for this'
				. " application type resolves to XF\\Mvc\\View - check app.classType and the class name."
			);
		}

		return $renderer->renderView(
			$reply->getViewClass(),
			$reply->getTemplateName(),
			$reply->getParams()
		);
	}

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
	 * the output means a phrase is missing.
	 *
	 * **Only the text the page displays is searched**, because that is where an unresolved phrase
	 * appears - everything inside a tag is an attribute, and an add-on's prefix belongs in plenty
	 * of them legitimately. XenForo's resource standards require a prefix on any column added to a
	 * core table, and a form field is named after its column, so `name="option[myaddon_thing]"` is
	 * correct output for a phrase that resolved perfectly well. The same goes for an id, a label's
	 * `for`, a CSS class, and the template names the templater writes into the first tag for an
	 * administrator with the embedTemplateNames option on. `<script>` and `<style>` blocks are
	 * dropped too, since they hold identifiers rather than prose.
	 *
	 * The cost is that a phrase which failed to resolve *inside* an attribute - the `value` of a
	 * submit button, say - is not seen here. Assert on that one directly.
	 *
	 * The page parameters the render set are searched as well as the html. `<xf:title>` and its
	 * siblings compile to a write into the templater rather than to markup, so a key that failed
	 * to resolve in one of them never reaches the output - and a search of the html alone passes
	 * having checked nothing. The failure names which surface each key came from, because a reader
	 * given only the key would look in the html and not find it.
	 *
	 * Pair this with an assertion that the text you expect IS present - output that never rendered
	 * carries no unresolved key either.
	 *
	 * @param string $html
	 * @param string|string[] $prefix - your add-on's phrase prefix, eg `myaddon_`, or several of
	 *                                 them where an older one survives a renaming
	 * @param string|null $message - prefixed to the failure, which goes on naming the keys and the
	 *                                surface each came from
	 *
	 * @return void
	 */
	protected function assertNoUnresolvedPhrases($html, $prefix, $message = null)
	{
		$surfaces = ['the rendered output' => $this->displayedTextOf($html)]
			+ $this->renderedPageParams();
		$found = [];

		foreach ($surfaces AS $surface => $subject)
		{
			// an add-on with a phrase predating its naming convention has more than one prefix, and
			// renaming a phrase loses any customisation of it - so the old ones are permanent
			foreach ((array) $prefix AS $onePrefix)
			{
				preg_match_all('/\b' . preg_quote($onePrefix, '/') . '[a-z0-9_]+/i', $subject, $matches);

				foreach ($matches[0] AS $key)
				{
					$found["$key (in $surface)"] = true;
				}
			}
		}

		$keys = array_keys($found);

		PHPUnit::assertSame(
			[],
			$keys,
			$this->failureMessage(
				$message,
				'these phrases did not resolve and rendered as their own keys: ' . implode(', ', $keys),
				'assertNoUnresolvedPhrases()'
			)
		);
	}

	/**
	 * Render a template with one of its template modifications left out.
	 *
	 * This is the control for a test that asserts a modification put something on the page: render
	 * with it, assert the markup is there, render without it, assert it is not. Mutating the test's
	 * own expectations proves much less, and disabling the modification for real recompiles the
	 * install's templates for every other suite running against it.
	 *
	 * Nothing is written: the template's text is read, the other modifications are applied to it by
	 * XenForo's own repository, and the result is compiled and rendered in memory. The forum's
	 * compiled templates, its modification rows and the style cache are all untouched, so this is
	 * safe on a shared development install and inside a transaction.
	 *
	 * The modification has to exist and be enabled, because a render missing a modification that
	 * was never applying is identical to a render missing one that was - and the assertion a test
	 * writes afterwards would pass for the wrong reason.
	 *
	 * @param string $modificationKey - the modification to leave out, as
	 *                                  assertTemplateModificationApplied() takes it
	 * @param array $params - template parameters, as renderTemplate() takes them
	 *
	 * @return string
	 */
	protected function renderTemplateWithoutModification($modificationKey, array $params = [])
	{
		$modification = $this->app()->db()->fetchRow(
			'SELECT modification_id, type, template, enabled
				FROM xf_template_modification
				WHERE modification_key = ?',
			$modificationKey
		);

		if (!$modification)
		{
			throw new \LogicException(
				"No template modification with the key '{$modificationKey}' exists in this forum."
				. ' This reads what the forum has, so the add-on has to be installed and its data'
				. ' imported - an edit to _output/ alone is invisible here.'
			);
		}

		if (!$modification['enabled'])
		{
			throw new \LogicException(
				"The template modification '{$modificationKey}' is disabled, so every render"
				. ' already leaves it out and rendering without it would prove nothing.'
			);
		}

		$type = $modification['type'];
		$title = $modification['template'];

		$template = $this->app()->finder('XF:Template')
			->where(['type' => $type, 'title' => $title, 'style_id' => 0])
			->fetchOne();

		if (!$template)
		{
			throw new \LogicException(
				"The template '{$type}:{$title}' that '{$modificationKey}' modifies does not exist."
			);
		}

		$others = [];

		foreach ($this->modificationsFor($type, $title) AS $candidate)
		{
			if (!($candidate instanceof TemplateModification))
			{
				throw new \LogicException(
					'Expected XF:TemplateModification to resolve to a '
					. TemplateModification::class . ', got ' . get_debug_type($candidate)
				);
			}

			if ($candidate->modification_key !== $modificationKey)
			{
				$others[$candidate->modification_id] = $candidate;
			}
		}

		$repository = $this->app()->repository('XF:TemplateModification');

		if (!($repository instanceof TemplateModificationRepository))
		{
			throw new \LogicException(
				'Expected XF:TemplateModification to resolve to a '
				. TemplateModificationRepository::class . ', got ' . get_debug_type($repository)
			);
		}

		$status = [];
		$text = $repository->applyTemplateModifications($template->template, $others, $status);

		return $this->renderTemplateText($type, $title, $text, $params);
	}

	/**
	 * The enabled modifications for a template, in the order XenForo applies them.
	 *
	 * @param string $type
	 * @param string $title
	 *
	 * @return AbstractCollection
	 */
	private function modificationsFor($type, $title)
	{
		return $this->app()->finder('XF:TemplateModification')
			->where(['type' => $type, 'template' => $title, 'enabled' => 1])
			->whereAddOnActive()
			->order(['execution_order', 'modification_key'])
			->fetch();
	}

	/**
	 * Compile the given template text and render it under the given template's name.
	 *
	 * The templater checks its own in-memory cache before it looks for a compiled file, so putting
	 * the compiled code there is enough to render text that is not in the database - and nothing on
	 * disk or in the forum changes. The entry is put back as it was afterwards, so a later render of
	 * the same template in the same test gets the real one.
	 *
	 * @param string $type
	 * @param string $title
	 * @param string $text
	 * @param array $params
	 *
	 * @return string
	 */
	private function renderTemplateText($type, $title, $text, array $params)
	{
		$templater = $this->app()->templater();
		$compiled = $this->app()->templateCompiler()->compile($text);

		$file = tempnam(sys_get_temp_dir(), 'xftest') . '.php';
		file_put_contents($file, "<?php\n" . $compiled . ";\n");

		try
		{
			$data = include $file;
		}
		finally
		{
			unlink($file);
		}

		$key = $this->app()->language()->getId() . '-' . $templater->getStyleId()
			. '-' . $type . '-' . $title;

		$cacheProperty = new \ReflectionProperty(Templater::class, 'templateCache');
		$cacheProperty->setAccessible(true);

		$cache = $cacheProperty->getValue($templater);
		$had = array_key_exists($key, $cache);
		$previous = $had ? $cache[$key] : null;

		$cache[$key] = $data;
		$cacheProperty->setValue($templater, $cache);

		try
		{
			return $this->renderTemplate($type . ':' . $title, $params);
		}
		finally
		{
			$cache = $cacheProperty->getValue($templater);

			if ($had)
			{
				$cache[$key] = $previous;
			}
			else
			{
				unset($cache[$key]);
			}

			$cacheProperty->setValue($templater, $cache);
		}
	}

	/**
	 * The text a page displays, with every tag and every scripted block removed.
	 *
	 * An unresolved phrase renders as visible text, so this is the whole surface where one can
	 * appear - and dropping the markup is what stops an attribute that legitimately carries an
	 * add-on's prefix being reported as a phrase that failed.
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	private function displayedTextOf($html)
	{
		$withoutScripts = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $html);

		return preg_replace('/<[^>]*>/', ' ', $withoutScripts);
	}

	/**
	 * The page parameters this render set, keyed by how a failure should describe each one.
	 *
	 * The four names are XenForo's own, read from the template compiler rather than assumed:
	 * `<xf:title>`, `<xf:description>`, `<xf:h1>` and `<xf:pageaction>`. They accumulate on the
	 * templater as each template sets them, so a test rendering twice sees both renders' values.
	 *
	 * @return array - description => value
	 */
	private function renderedPageParams()
	{
		$surfaces = [];

		foreach (['pageTitle', 'pageDescription', 'pageH1', 'pageAction'] AS $name)
		{
			$value = $this->pageParam($name);

			if ($value !== null && $value !== '')
			{
				$surfaces["the $name page parameter"] = $value;
			}
		}

		return $surfaces;
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
	private function installGlobalTemplateData(Templater $templater, ?AbstractReply $reply)
	{
		$defaultParams = new \ReflectionProperty(Templater::class, 'defaultParams');
		$defaultParams->setAccessible(true);
		$current = $defaultParams->getValue($templater)['xf'] ?? null;

		if ($current !== null && $current !== $this->installedGlobalTemplateData)
		{
			return;
		}

		// before the global data is built, because getGlobalTemplateData() reads the style off the
		// templater and a null one reaches every template as $xf.style
		$this->installTemplaterStyle($templater);

		// on the application in \XF::app() rather than this one: setAppClassType() puts a Pub, Admin
		// or Api application there, and getGlobalTemplateData() fires templater_global_data with
		// whichever application built it - which is what a listener gated on the app type reads.
		// Without a stand-in installed the two are the same object.
		$data = \XF::app()->getGlobalTemplateData($reply);
		$templater->addDefaultParam('xf', $data);
		$this->installedGlobalTemplateData = $data;
	}

	/**
	 * Give the templater a style, which only a real request otherwise does.
	 *
	 * `XF\Pub\App` and `XF\Admin\App` both set one while serving a page, and
	 * `getGlobalTemplateData()` reads it back to build `$xf.style`. This package boots the base
	 * `XF\App`, which sets none - so without this every template sees `$xf.style` as null, and a
	 * core template calling a method on it logs an error and renders nothing for that block. Ten
	 * public and two admin templates read it on a stock 2.3 install.
	 *
	 * The style installed is `style.fallback`, the master style `XF\Admin\App` uses, because its id
	 * is 0 and that is already the templater's default - so template resolution is exactly what it
	 * was and only `$xf.style` changes. **The visitor's own style is deliberately not used:** it
	 * would move the templater to that style id, and an `admin:` template does not exist there, so
	 * an admin render would fail to find its own templates. A test that needs a particular style
	 * sets one itself, and a style already set is left alone.
	 *
	 * The icon renderer gets the same style because `XF\IconRenderer::getStyle()` is declared to
	 * return a `Style` while the property is nullable, so leaving it unset is a TypeError waiting
	 * for whatever first asks.
	 *
	 * @param Templater $templater
	 *
	 * @return void
	 */
	private function installTemplaterStyle(Templater $templater)
	{
		if ($templater->getStyle())
		{
			return;
		}

		$style = $this->app()->container()['style.fallback'];

		if (!($style instanceof Style))
		{
			throw new \LogicException(
				'Expected the style factory to give a ' . Style::class . ', got '
				. get_debug_type($style)
			);
		}

		$templater->setStyle($style);
		\XF::app()->iconRenderer()->setStyle($style);
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
	 * On a development forum shared with other add-ons the blanket form describes the forum rather
	 * than the add-on: template modifications compile into the install's shared template cache
	 * whatever $addonsToLoad says, so a sibling's modification reading a column its own entity
	 * extension declares raises an error on every render here. Pass the prefix of the templates you
	 * own to assert on yours alone.
	 *
	 * @param string|string[]|null $templateFilter - matched against the template name; null asserts
	 *                                               on every error the render raised
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertNoTemplateErrors($templateFilter = null, $message = null)
	{
		$errors = $this->app()->templater()->getTemplateErrors();

		if ($templateFilter !== null)
		{
			$needles = (array) $templateFilter;

			// matched against the template name rather than the whole row: the row also holds the
			// error message, and an add-on's name can appear in somebody else's error text
			$errors = array_filter($errors, function (array $error) use ($needles)
			{
				foreach ($needles AS $needle)
				{
					if (strpos($error['template'], $needle) !== false)
					{
						return true;
					}
				}

				return false;
			});
		}

		PHPUnit::assertSame(
			[],
			array_values(array_map([$this, 'describeTemplateError'], $errors)),
			$this->failureMessage(
				$message,
				'XenForo logs a template error and carries on rendering, so these did not fail the render',
				'assertNoTemplateErrors()'
			)
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
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertTemplateModificationApplied($modificationKey, $message = null)
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
			PHPUnit::fail($this->failureMessage(
				$message,
				"No template modification with the key '$modificationKey' exists in this forum."
				. ' These assertions read what the forum has, so the add-on has to be installed'
				. ' and its data imported - an edit to _output/ alone is invisible here.',
				'assertTemplateModificationApplied()'
			));
		}

		$applied = (int) $db->fetchOne(
			'SELECT SUM(apply_count) FROM xf_template_modification_log WHERE modification_id = ?',
			$modification['modification_id']
		);

		PHPUnit::assertGreaterThan(
			0,
			$applied,
			$this->failureMessage(
				$message,
				"Template modification '$modificationKey' on {$modification['type']}:"
				. "{$modification['template']} applied 0 times"
				. ($modification['enabled'] ? '' : ' (and it is disabled)')
				. " - its find no longer matches. XenForo logs that as status 'ok', so only the apply"
				. ' count shows it.',
				'assertTemplateModificationApplied()'
			)
		);
	}

	/**
	 * Assert that rendered output contains the given text.
	 *
	 * @param string $html - as returned by renderTemplate() or renderReply()
	 * @param string $value
	 * @param bool $escape - escape the expected value the way a template would, which is what
	 *                       makes an apostrophe or an ampersand match
	 * @param string|null $message - prefixed to the failure, which goes on naming the value and the
	 *                               size of the render
	 *
	 * @return void
	 */
	protected function assertSee($html, $value, $escape = true, $message = null)
	{
		$this->requireSearchValue($value, 'assertSee()');
		$this->requireEscapeFlag($escape, 'assertSee()');

		$expected = $this->escapeExpected($value, $escape);

		// not assertStringContainsString(), which prints the whole haystack on failure - a page is
		// tens of thousands of characters and the needle it did not find is the only useful part
		PHPUnit::assertNotFalse(
			strpos((string) $html, $expected),
			$this->failureMessage($message, $this->describeMissing($html, $expected, $value), 'assertSee()')
		);
	}

	/**
	 * Assert that rendered output does not contain the given text.
	 *
	 * @param string $html
	 * @param string $value
	 * @param bool $escape
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertDontSee($html, $value, $escape = true, $message = null)
	{
		$this->requireSearchValue($value, 'assertDontSee()');
		$this->requireEscapeFlag($escape, 'assertDontSee()');

		$expected = $this->escapeExpected($value, $escape);
		$position = strpos((string) $html, $expected);

		PHPUnit::assertFalse(
			$position,
			$this->failureMessage(
				$message,
				$this->describePresent($html, $expected, $value, $position),
				'assertDontSee()'
			)
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
	 * @param string|null $message - prefixed to the failure. There is no escaping flag here: the
	 *                               markup is stripped before the search, so there is nothing to
	 *                               escape the value against
	 *
	 * @return void
	 */
	protected function assertSeeText($html, $value, $message = null)
	{
		$this->requireSearchValue($value, 'assertSeeText()');

		$text = $this->textOf($html);

		PHPUnit::assertNotFalse(
			strpos($text, (string) $value),
			$this->failureMessage(
				$message,
				$this->describeMissing($text, (string) $value, null, 'text of the rendered output'),
				'assertSeeText()'
			)
		);
	}

	/**
	 * Assert that rendered output does not contain the given text, ignoring the markup.
	 *
	 * @param string $html
	 * @param string $value
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertDontSeeText($html, $value, $message = null)
	{
		$this->requireSearchValue($value, 'assertDontSeeText()');

		$text = $this->textOf($html);
		$position = strpos($text, (string) $value);

		PHPUnit::assertFalse(
			$position,
			$this->failureMessage(
				$message,
				$this->describePresent($text, (string) $value, null, $position, 'text of the rendered output'),
				'assertDontSeeText()'
			)
		);
	}

	/**
	 * Assert that rendered output contains the given values, in the order given.
	 *
	 * @param string $html
	 * @param array $values
	 * @param bool $escape
	 * @param string|null $message - prefixed to the failure, which goes on naming the value that
	 *                               was out of order
	 *
	 * @return void
	 */
	protected function assertSeeInOrder($html, array $values, $escape = true, $message = null)
	{
		$this->requireEscapeFlag($escape, 'assertSeeInOrder()');

		if ($values === [])
		{
			throw new \LogicException(
				'assertSeeInOrder() was given no values, so it asserts nothing at all. Name the'
				. ' values you expect, in the order you expect them.'
			);
		}

		$offset = 0;

		foreach ($values AS $value)
		{
			$this->requireSearchValue($value, 'assertSeeInOrder()');

			$expected = $this->escapeExpected($value, $escape);
			$position = strpos($html, $expected, $offset);

			PHPUnit::assertNotFalse(
				$position,
				$this->failureMessage(
					$message,
					"Failed asserting that '$expected' appears after the values before it. "
					. $this->describeHtml($html),
					'assertSeeInOrder()'
				)
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
	 * Refuse an empty string to search for.
	 *
	 * strpos() answers 0 for an empty needle, which is found in anything - so an empty value makes
	 * a positive assertion unable to fail and a negative one unable to pass. Either way the
	 * assertion has stopped being about the output, which is worse than not having it. A test
	 * reaching here has usually built the value from something that came back empty itself.
	 *
	 * @param string $value
	 * @param string $helper - the assertion that was called, for the message
	 *
	 * @return void
	 */
	private function requireSearchValue($value, $helper)
	{
		if ((string) $value !== '')
		{
			return;
		}

		throw new \LogicException(
			"$helper was given an empty string to search for, which says nothing about the output:"
			. ' an empty value is found at position 0 of anything, so the assertion cannot fail -'
			. ' and its negative counterpart cannot pass. Assert on the text you expect, or on the'
			. ' length of the render.'
		);
	}

	/**
	 * Refuse anything but a bool where the escaping flag goes.
	 *
	 * The trailing argument of these assertions is a failure message, and of the three that escape
	 * it is the fourth rather than the third - so a message written in the third position lands on
	 * this flag, where a non-empty string is simply truthy and the default. Nothing would change and
	 * the message would be gone, which is the silence this parameter was added to end.
	 *
	 * @param mixed $escape - a bool by contract, anything at all in practice
	 * @param string $helper - the assertion that was called, for the message
	 *
	 * @return void
	 */
	private function requireEscapeFlag($escape, $helper)
	{
		if (is_bool($escape))
		{
			return;
		}

		throw new \LogicException(
			"The third argument of $helper is the escaping flag, which is a bool - a failure message"
			. ' goes after it. Passed a string this parameter is merely truthy, so the assertion would'
			. ' behave exactly as it does by default and the message would say nothing to anybody.'
		);
	}

	/**
	 * A failure message carrying what the caller said and what the assertion found.
	 *
	 * The caller's message is prefixed rather than substituted. What these assertions compose is the
	 * value searched for, the size of the render and a window around an unwanted match - a reader
	 * needs that as well as the diagnosis, and an assertion that threw one away in favour of the
	 * other would make supplying a message cost something.
	 *
	 * @param mixed $message - a string or null by contract, anything at all in practice
	 * @param string $description - what the assertion itself has to say
	 * @param string $helper - the assertion that was called, for the message
	 *
	 * @return string
	 */
	private function failureMessage($message, $description, $helper)
	{
		if ($message !== null && !is_string($message))
		{
			// the text assertions take no escaping flag, so a false meant for one lands here
			throw new \LogicException(
				"The last argument of $helper is a failure message, not a flag or an expected value."
				. ' assertSeeText() and assertDontSeeText() strip the markup before searching, so there'
				. ' is nothing for an escaping flag to do and they do not take one.'
			);
		}

		return $message === null || $message === '' ? $description : "$message - $description";
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
	 * Why a value was not found, without printing the haystack.
	 *
	 * PHPUnit's own string assertions print the whole subject on failure, which for a rendered page
	 * is thousands of lines of escaped markup with the one useful fact - the needle it looked for -
	 * at the end of it. So these helpers compose the failure instead, and the assertions use
	 * assertTrue()/assertFalse().
	 *
	 * @param string $haystack
	 * @param string $expected the value actually searched for, escaped where the caller asked
	 * @param string|null $raw what the caller passed, where escaping may have changed it
	 * @param string $subject what the haystack is, for the message
	 *
	 * @return string
	 */
	private function describeMissing($haystack, $expected, $raw = null, $subject = 'rendered output')
	{
		$message = "Failed asserting that the $subject contains '$expected'";

		if ($raw !== null && (string) $raw !== $expected)
		{
			// the escaping default is itself a stumble, so say what was searched for and why
			$message .= " - escaped from '$raw', which is what a template emits";
		}

		return $message . '. ' . $this->describeHtml($haystack);
	}

	/**
	 * Why a value was found where it should not have been, with a window around the first match.
	 *
	 * Here the match itself is the useful part, so the message carries its surroundings rather than
	 * the length alone.
	 *
	 * @param string $haystack
	 * @param string $expected
	 * @param string|null $raw
	 * @param int|false $position
	 * @param string $subject
	 *
	 * @return string
	 */
	private function describePresent(
		$haystack,
		$expected,
		$raw = null,
		$position = false,
		$subject = 'rendered output'
	)
	{
		$message = "Failed asserting that the $subject does not contain '$expected'";

		if ($raw !== null && (string) $raw !== $expected)
		{
			$message .= " - escaped from '$raw'";
		}

		if ($position === false)
		{
			// the assertion passed, so this is never read; built anyway rather than returning null
			return $message . '. ' . $this->describeHtml($haystack);
		}

		return $message . ", at offset $position, in: " . $this->windowAround($haystack, $position, strlen($expected))
			. ' ' . $this->describeHtml($haystack);
	}

	/**
	 * The text either side of a match, whitespace collapsed so it stays readable in a terminal.
	 *
	 * @param string $haystack
	 * @param int $position
	 * @param int $length
	 *
	 * @return string
	 */
	private function windowAround($haystack, $position, $length)
	{
		$context = 60;

		$start = max(0, $position - $context);
		$end = min(strlen($haystack), $position + $length + $context);

		$window = preg_replace('/\s+/', ' ', substr($haystack, $start, $end - $start));

		return ($start > 0 ? '...' : '') . trim((string) $window) . ($end < strlen($haystack) ? '...' : '');
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
