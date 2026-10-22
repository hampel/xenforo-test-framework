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
use XF\Widget\AbstractWidget;
use XF\Widget\WidgetRenderer;

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
	 * Render one widget and return its HTML.
	 *
	 * **A widget that decides not to show itself renders an empty string, and that is preserved** -
	 * seven core widgets do exactly that, so it is XenForo's own idiom for "not applicable to this
	 * visitor" rather than a fault. The three other ways a widget render comes back empty are
	 * faults, and are refused instead, because a test asserting on an absence cannot tell them
	 * apart: a renderer with no template name, a template that does not exist, and a template that
	 * errored. So `assertSame('', $this->renderWidgetHtml(...))` means the widget declined, and
	 * nothing else.
	 *
	 * Those three refusals can only reach a render where the widget **named** a template. A widget
	 * that declines returns before it calls `renderer()`, so there is no template to check and a
	 * decline test on its own cannot notice that the widget's template has gone. Render the
	 * showing case beside it.
	 *
	 * The identifier is either form XenForo accepts, and they are looked up in different places:
	 *
	 * - a **definition class**, `XF\Widget\MembersOnline` - matched against the installed
	 *   definitions, which renders a widget the forum need not have configured anywhere. Its
	 *   stored options are **not** applied, so `$options` is the whole configuration.
	 * - a **widget key**, `forum_overview_members_online` - a widget the forum has configured, with
	 *   your `$options` merged over the ones it was set up with.
	 *
	 * `_title` in `$options` overrides the widget's title, and `context` is passed as the context
	 * parameters rather than as an option. Both are XenForo's own special keys, and `_title` is
	 * honoured on the definition-class form only.
	 *
	 * The style a render needs is installed for you. The application type is not: call
	 * `setAppClassType('public')` first where what the widget renders depends on it. A widget
	 * template is always a `public:` one, because `XF\Widget\AbstractWidget::renderer()` prefixes
	 * the type itself, so the app type changes nothing about which template is found.
	 *
	 * On a forum shared with other add-ons a widget render can carry a **sibling's** template
	 * errors, since modifications compile into the install's shared template cache whatever
	 * `$addonsToLoad` says. That is why only an empty render is refused; pair this with
	 * `assertNoTemplateErrors()` and the prefix of the templates you own for the strict form.
	 *
	 * @param string $identifier - a definition class, or a configured widget's key
	 * @param array $options - the widget's options, plus `_title` and `context` if wanted
	 *
	 * @return string - the rendered HTML, trimmed, or '' if the widget declined to show
	 */
	protected function renderWidgetHtml($identifier, array $options = [])
	{
		$this->requireWidgetIdentifier($identifier);

		$templater = $this->app()->templater();
		$this->installGlobalTemplateData($templater, null);
		$errorsBefore = count($templater->getTemplateErrors());

		$widget = $this->app()->widget()->widget($identifier, $options);

		if (!$widget instanceof AbstractWidget)
		{
			throw new \LogicException(
				"Widget '$identifier' resolved to " . get_debug_type($widget) . ' rather than an '
				. AbstractWidget::class . ', so there is nothing to render.'
			);
		}

		return $this->widgetHtmlFrom($widget->render(), $identifier, $errorsBefore);
	}

	/**
	 * Turn whatever a widget's render() answered into HTML, refusing every empty render but the
	 * one that is deliberate.
	 *
	 * `$rendered` is `mixed` rather than the `string|null` XenForo's own
	 * `XF\Widget\AbstractWidget::render()` declares, because that docblock is wrong: a widget that
	 * means to render answers with a `WidgetRenderer`, as every core widget does. Typing the
	 * parameter honestly is what keeps the check below live - static analysis reading XenForo's
	 * declaration otherwise calls it impossible.
	 *
	 * @param mixed $rendered - a WidgetRenderer, or a string from a widget that declined
	 * @param string $identifier
	 * @param int $errorsBefore
	 *
	 * @return string
	 */
	private function widgetHtmlFrom($rendered, $identifier, $errorsBefore)
	{
		// A widget declines to show by returning a string from its own render(), where one that
		// means to render answers with a WidgetRenderer. That is the only thing separating a
		// deliberate empty render from a broken one, so it is what decides here rather than the
		// emptiness of the output.
		if (!$rendered instanceof WidgetRenderer)
		{
			return trim((string) $rendered);
		}

		$template = (string) $rendered->getTemplateName();

		// AbstractWidget::renderer() prefixes the type itself, so a widget that called it with no
		// name leaves 'public:' here rather than an empty string - which is truthy, so XenForo goes
		// on to render a template whose title is empty and comes back with nothing.
		$title = substr($template, strpos($template, ':') === false ? 0 : strpos($template, ':') + 1);

		if ($title === '')
		{
			throw new \LogicException(
				"Widget '$identifier' asked to render the template '$template', which names no title -"
				. ' so XenForo renders an empty string rather than failing. The widget\'s render()'
				. ' called renderer() without a template name, and an assertion against its output'
				. ' would have passed while testing nothing.'
			);
		}

		$html = trim((string) $rendered);

		if ($html === '')
		{
			// The widget meant to render a template and produced nothing. A template XenForo cannot
			// find renders empty and records no error at all, so the existence check is the only
			// thing that catches it - and without it this would be indistinguishable from a widget
			// that declined.
			$this->requireTemplateExists($template);
		}

		$this->requireRenderSucceeded($errorsBefore, $html, "widget '$identifier' template '$template'");

		return $html;
	}

	/**
	 * Refuse a widget identifier that cannot match either of the two things XenForo looks up.
	 *
	 * A colon is the short `Vendor\AddOn:Name` form, which nothing expands here: an identifier
	 * carrying a backslash is compared **literally** against the stored `definition_class` values,
	 * and `\XF::stringToClass()` is applied to the stored value rather than to what the caller
	 * passed. One without a backslash is looked up as a widget key, and a key is alphanumeric by
	 * XenForo's own column rule, so it can never contain a colon either. Either way the colon form
	 * fails with a message about a definition or a key that does not exist, which names the symptom
	 * rather than the mistake.
	 *
	 * @param mixed $identifier
	 *
	 * @return void
	 */
	private function requireWidgetIdentifier($identifier)
	{
		if (!is_string($identifier) || $identifier === '')
		{
			throw new \LogicException(
				'renderWidgetHtml() needs a widget definition class or a configured widget\'s key,'
				. ' and was given ' . get_debug_type($identifier) . '.'
			);
		}

		if (strpos($identifier, ':') !== false)
		{
			throw new \LogicException(
				"Widget identifier '$identifier' carries a colon, so it is the short"
				. " Vendor\\AddOn:Name form - which XenForo does not expand for a widget. Pass the"
				. ' full definition class, eg \'Vendor\\AddOn\\Widget\\TheWidget\', or the key of a'
				. ' widget the forum has configured, which is alphanumeric and so never carries a'
				. ' colon.'
			);
		}
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
	 * `<xf:sidenav>` and `<xf:sidebar>` are absent from the output as well, and are not page
	 * parameters - read those with `sideNavHtml()` and `sidebarHtml()`.
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
	 * The markup a render contributed to the side navigation, as one string.
	 *
	 * `<xf:sidenav>` compiles to a write into the templater rather than to markup, so what it
	 * produces is in neither the rendered HTML nor the page parameters. The account pages build
	 * their whole sidebar this way, so an add-on adding a link there has nothing to assert on
	 * without this.
	 *
	 * XenForo stores each block pre-escaped, as an `XF\PreEscaped` rather than a string, so the
	 * values are cast rather than concatenated as they come.
	 *
	 * **It accumulates across renders in one test**, as the page parameters do, so a test
	 * rendering twice sees both renders' blocks. XenForo keeps them keyed by block; where a test
	 * needs to know which block a string came from, read
	 * `$this->app()->templater()->getSideNavHtml()` for the array.
	 *
	 * @return string
	 */
	protected function sideNavHtml()
	{
		return implode('', array_map('strval', $this->app()->templater()->getSideNavHtml()));
	}

	/**
	 * The markup a render contributed to the sidebar, as one string.
	 *
	 * `<xf:sidebar>` behaves as `<xf:sidenav>` does - see `sideNavHtml()`, including that it
	 * accumulates across renders. The array is on
	 * `$this->app()->templater()->getSidebarHtml()`.
	 *
	 * @return string
	 */
	protected function sidebarHtml()
	{
		return implode('', array_map('strval', $this->app()->templater()->getSidebarHtml()));
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
	 * The page parameters the render set are searched as well as the html, and so are the side
	 * navigation and the sidebar. `<xf:title>` and its siblings, `<xf:sidenav>` and `<xf:sidebar>`
	 * all compile to a write into the templater rather than to markup, so a key that failed to
	 * resolve in one of them never reaches the output - and a search of the html alone passes
	 * having checked nothing. The failure names which surface each key came from, because a reader
	 * given only the key would look in the html and not find it.
	 *
	 * Pair this with an assertion that the text you expect IS present - output that never rendered
	 * carries no unresolved key either.
	 *
	 * **A prefix matches a key that ends exactly at it**, which is the shape of every key XenForo
	 * structures for you: an option is `option.<optionId>`, its explanation
	 * `option_explain.<optionId>`, an option group's title `option_group.<groupId>` and its
	 * description `option_group_description.<groupId>`, and a permission
	 * `permission.<permissionId>`. The add-on's own id comes last in all of them, so a key is
	 * often the prefix and nothing more. A dotted segment in front of the prefix is reported as
	 * part of the key, so what the failure names is what you can search for.
	 *
	 * **The bare add-on prefix is the wrong argument for an admin option page.** With development
	 * mode on, XenForo displays each option's id beside its title as ordinary text, so every
	 * option on the page is reported whether or not its phrase resolved. Pass the keys instead -
	 * `['option.myaddon_thing', 'option_explain.myaddon_thing']` - or assert on the page's own
	 * phrases by value. The ids are displayed only in development mode, so a bare prefix there
	 * passes on one install and fails on another.
	 *
	 * @param string $html
	 * @param string|string[] $prefix - your add-on's phrase prefix, eg `myaddon_`, a whole phrase
	 *                                 key, or several of either where an older prefix survives a
	 *                                 renaming
	 * @param string|null $message - prefixed to the failure, which goes on naming the keys and the
	 *                                surface each came from
	 *
	 * @return void
	 */
	protected function assertNoUnresolvedPhrases($html, $prefix, $message = null)
	{
		$surfaces = ['the rendered output' => $this->displayedTextOf($html)]
			+ $this->renderedPageParams()
			+ $this->renderedNavSurfaces();
		$found = [];

		foreach ($surfaces AS $surface => $subject)
		{
			// an add-on with a phrase predating its naming convention has more than one prefix, and
			// renaming a phrase loses any customisation of it - so the old ones are permanent
			foreach ((array) $prefix AS $onePrefix)
			{
				// the key can end exactly at the prefix, because XenForo's structured keys put the
				// add-on's own id last - an option group's title is `option_group.<groupId>`. And
				// a dotted segment in front of the prefix is part of the key rather than context,
				// so it is matched too: a reader handed only the tail cannot grep for it. The
				// segment has to be identifier-shaped, which is what a phrase key's is.
				preg_match_all(
					'/\b(?:[a-z_][a-z0-9_]*\.)?' . preg_quote($onePrefix, '/') . '[a-z0-9_]*/i',
					$subject,
					$matches
				);

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
	 * **To pair it with a dispatch, hand it the reply's own parameters** -
	 * `renderTemplateWithoutModification($key, $reply->getParams())`. That is what renderReply()
	 * renders the template with, so the template's own parameters are the same on both sides.
	 *
	 * One thing still differs: renderReply() passes the reply to getGlobalTemplateData() and this
	 * does not, so `$xf.reply` - the controller, action and template of the page - is absent here.
	 * It matters for a template that reads it, which in core is a generated advertising template
	 * restricted to particular pages.
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

		// tempnam() creates the file, so this writes to that same path rather than appending an
		// extension to the name - include() does not care about the extension, and a suffixed path
		// would leave the created file behind on every call
		$file = tempnam(sys_get_temp_dir(), 'xftpl');
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
	private function renderedNavSurfaces()
	{
		$surfaces = [];

		foreach (['side navigation' => 'getSideNavHtml', 'sidebar' => 'getSidebarHtml'] AS $name => $method)
		{
			$html = implode('', array_map('strval', $this->app()->templater()->$method()));

			if ($html !== '')
			{
				$surfaces["the $name"] = $this->displayedTextOf($html);
			}
		}

		return $surfaces;
	}

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

		$data = $this->buildGlobalTemplateData($templater, $reply);
		$templater->addDefaultParam('xf', $data);
		$this->installedGlobalTemplateData = $data;
	}

	/**
	 * The `xf` template parameter, as a render would see it.
	 *
	 * Reach for this to assert on what a `templater_global_data` listener of yours contributed.
	 * XenForo fires that event from inside `getGlobalTemplateData()`, so reading the result proves
	 * the listener is registered with the class, method and signature XenForo will actually call -
	 * which calling the listener's method directly cannot.
	 *
	 * **Call it rather than `XF::app()->getGlobalTemplateData()`.** That method reads the style off
	 * the templater, which only a real request otherwise sets, so called directly it answers
	 * `style => null` - and a template handed that logs an error and renders nothing for the block
	 * that reads it. This installs the style first, exactly as a render does.
	 *
	 * @param AbstractReply|null $reply - as XenForo passes one while rendering a reply
	 *
	 * @return array
	 */
	protected function globalTemplateData(?AbstractReply $reply = null)
	{
		return $this->buildGlobalTemplateData($this->app()->templater(), $reply);
	}

	/**
	 * Build the `xf` parameter, with the style a render needs installed first.
	 *
	 * @param Templater $templater
	 * @param AbstractReply|null $reply
	 *
	 * @return array
	 */
	private function buildGlobalTemplateData(Templater $templater, ?AbstractReply $reply)
	{
		// before the global data is built, because getGlobalTemplateData() reads the style off the
		// templater and a null one reaches every template as $xf.style
		$this->installTemplaterStyle($templater);

		// on the application in \XF::app() rather than this one: setAppClassType() puts a Pub, Admin
		// or Api application there, and getGlobalTemplateData() fires templater_global_data with
		// whichever application built it - which is what a listener gated on the app type reads.
		// Without a stand-in installed the two are the same object.
		return \XF::app()->getGlobalTemplateData($reply);
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
	 * **An add-on that owns no templates needs a closure rather than a name.** Where the add-on's
	 * only markup is a template modification of a core template, the errors it is answerable for
	 * are raised while rendering a template somebody else owns - and so are a sibling add-on's. A
	 * closure is handed each error and says whether it is yours:
	 *
	 * ```php
	 * $this->assertNoTemplateErrors(function (array $error)
	 * {
	 *     return strpos($error['error'], 'myaddon_') !== false;
	 * });
	 * ```
	 *
	 * The keys are XenForo's own - `template` as `type:title`, `error` as the message, `type` as
	 * the PHP error constant, and `file` and `line`. Returning true keeps the error, so the
	 * assertion fails on it.
	 *
	 * **A string is always a template name, never called.** `header`, `compact` and `link` are all
	 * PHP functions as well as plausible template names, so the form decides this rather than
	 * whether the value happens to be callable: pass a closure to filter by anything else.
	 *
	 * @param string|string[]|\Closure|null $templateFilter - a template name to match, a list of
	 *                                                        them, or a closure given each error;
	 *                                                        null asserts on every error raised
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertNoTemplateErrors($templateFilter = null, $message = null)
	{
		$errors = $this->app()->templater()->getTemplateErrors();

		if ($templateFilter instanceof \Closure)
		{
			$errors = array_filter($errors, $templateFilter);
		}
		else if ($templateFilter !== null)
		{
			$needles = $this->templateNameNeedles($templateFilter);

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
	 * The template names assertNoTemplateErrors() was given, as a list.
	 *
	 * @param mixed $templateFilter - a name or a list of them by contract, anything at all in
	 *                                practice: the guard is what turns a caller's mistake into an
	 *                                explanation rather than a TypeError inside strpos()
	 *
	 * @return string[]
	 */
	private function templateNameNeedles($templateFilter)
	{
		$needles = (array) $templateFilter;

		foreach ($needles AS $needle)
		{
			if (!is_string($needle))
			{
				throw new \LogicException(
					'assertNoTemplateErrors() takes a template name, a list of names, or a Closure'
					. ' given each error - not ' . get_debug_type($needle)
					. '. A callable that is not a Closure is not accepted, because a string is'
					. ' always matched as a template name.'
				);
			}
		}

		return $needles;
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
	 * A reply usually carries a bare template name, and the type then comes from the app class type
	 * dispatch() swapped in - so call this after a dispatch rather than before one.
	 *
	 * **A name that already carries a type is rendered as named.** Core's standard delete plugin
	 * answers with 'public:delete_confirm' for an admin controller as well as a public one, because
	 * the control panel reuses that template, so the dispatch type is not prepended to a name that
	 * has one.
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

		$templateName = $reply->getTemplateName();

		// a reply can name its template with the type already on it, and core does: the standard
		// delete plugin answers 'public:delete_confirm' for an admin controller as well as a public
		// one, because the control panel reuses that template. So the type is prepended to a bare
		// name only, which is the rule the rest of this trait already follows.
		$template = strpos($templateName, ':') === false
			? self::TEMPLATE_TYPES[$classType] . ':' . $templateName
			: $templateName;

		return $this->renderWithGlobalData($template, $reply->getParams(), $reply);
	}

	/**
	 * Assert that every template modification the add-on registers still applies.
	 *
	 * A modification whose `find` no longer matches is silent: XenForo logs it with status `ok`
	 * and an apply count of zero, so nothing fails and the markup it inserts is simply absent. A
	 * suite with no assertion on that markup passes identically either way, which is the same
	 * shape as a class extension that stops applying.
	 *
	 * **The add-on must be enabled, but not idle.** XenForo gathers modifications with
	 * `whereAddOnActive()` and no `disableProcessing` option, so a disabled add-on's are excluded
	 * while one part way through an install still has its applied - the opposite of the extension
	 * cache, which checks both. So a disabled add-on is refused here and a mid-install one is not.
	 *
	 * Reads what the forum has, so an edit to `_output/template_modifications/` that was never
	 * imported is invisible to this - a test globbing that directory catches the drift a commit
	 * without an import leaves, and the two are worth having together.
	 *
	 * @param string|null $addOnId - defaults to the add-on $addonsToLoad isolates, when it names one
	 *
	 * @return array<string, int> - each modification key, mapped to the number of times it applied
	 */
	protected function assertAddOnTemplateModificationsApply($addOnId = null)
	{
		$addOnId = $addOnId ?: $this->soleIsolatedAddOnId('whose template modifications to check');

		$db = $this->app()->db();
		$modifications = $this->enabledTemplateModificationsOf($addOnId);

		$applied = [];
		$absent = [];

		foreach ($modifications AS $modification)
		{
			// the log carries a row per template a modification was applied to, so the count has
			// to be summed; status is 'ok' whether or not the find matched anything
			$count = (int) $db->fetchOne(
				'SELECT SUM(l.apply_count)
					FROM xf_template_modification_log AS l
					INNER JOIN xf_template_modification AS m ON (m.modification_id = l.modification_id)
					WHERE m.modification_key = ?',
				$modification['modification_key']
			);

			if ($count > 0)
			{
				$applied[$modification['modification_key']] = $count;
			}
			else
			{
				$absent[] = $modification['modification_key'] . ' on ' . $modification['type']
					. ':' . $modification['template'] . ' applied 0 times, so its find no longer matches';
			}
		}

		PHPUnit::assertSame(
			[],
			$absent,
			"These template modifications of '$addOnId' are enabled and apply to nothing, so the"
			. ' markup they insert is absent from every render'
		);

		return $applied;
	}

	/**
	 * Assert that every template modification the add-on registers applies exactly this many
	 * times in each template it reaches.
	 *
	 * assertAddOnTemplateModificationsApply() says applied at least once. A `str_replace`
	 * modification whose find matches twice inserts its block twice, and passes that. The count
	 * is checked per log row rather than summed, because XenForo logs a row per template the
	 * modification was applied to - one per style holding its own copy of the template - so a
	 * sum counts styles as well as matches.
	 *
	 * @param int $times - the apply count every row must carry, at least 1
	 * @param string|null $addOnId - defaults to the add-on $addonsToLoad isolates, when it names one
	 *
	 * @return array<string, int> - each modification key, mapped to the number of times it applied
	 */
	protected function assertAddOnTemplateModificationsApplyTimes($times, $addOnId = null)
	{
		$this->requireApplyTimes($times, 'assertAddOnTemplateModificationsApplyTimes()');

		$addOnId = $addOnId ?: $this->soleIsolatedAddOnId('whose template modifications to check');

		$applied = [];
		$wrong = [];

		foreach ($this->enabledTemplateModificationsOf($addOnId) AS $modification)
		{
			$rows = $this->templateModificationLogRows($modification['modification_id']);
			$total = array_sum(array_column($rows, 'apply_count'));

			if ($total === 0)
			{
				$wrong[] = $modification['modification_key'] . ' on ' . $modification['type']
					. ':' . $modification['template'] . ' applied 0 times, so its find no longer matches';
				continue;
			}

			$applied[$modification['modification_key']] = $total;

			foreach ($rows AS $row)
			{
				if ((int) $row['apply_count'] !== $times)
				{
					$wrong[] = $modification['modification_key'] . ' applied ' . $row['apply_count']
						. ' times in ' . $row['where'] . ', not ' . $times;
				}
			}
		}

		PHPUnit::assertSame(
			[],
			$wrong,
			"These template modifications of '$addOnId' do not apply exactly $times "
			. ($times === 1 ? 'time' : 'times') . ' in every template they reach'
		);

		return $applied;
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
	 * Assert that a template modification applies exactly this many times in each template it
	 * reaches.
	 *
	 * assertTemplateModificationApplied() says applied at least once, and a `str_replace`
	 * modification whose find matches twice inserts its block twice. The count is checked per
	 * log row rather than summed: XenForo logs a row per template the modification was applied
	 * to, one per style holding its own copy, so a sum counts styles as well as matches.
	 *
	 * @param string $modificationKey - as in `_output/template_modifications/`
	 * @param int $times - the apply count every row must carry, at least 1
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertTemplateModificationAppliedTimes($modificationKey, $times, $message = null)
	{
		$this->requireApplyTimes($times, 'assertTemplateModificationAppliedTimes()');

		$modification = $this->app()->db()->fetchRow(
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
				'assertTemplateModificationAppliedTimes()'
			));
		}

		$rows = $this->templateModificationLogRows($modification['modification_id']);
		$total = array_sum(array_column($rows, 'apply_count'));

		if ($total === 0)
		{
			PHPUnit::fail($this->failureMessage(
				$message,
				"Template modification '$modificationKey' on {$modification['type']}:"
				. "{$modification['template']} applied 0 times"
				. ($modification['enabled'] ? '' : ' (and it is disabled)')
				. " - its find no longer matches. XenForo logs that as status 'ok', so only the apply"
				. ' count shows it.',
				'assertTemplateModificationAppliedTimes()'
			));
		}

		$wrong = [];

		foreach ($rows AS $row)
		{
			if ((int) $row['apply_count'] !== $times)
			{
				$wrong[] = 'applied ' . $row['apply_count'] . ' times in ' . $row['where'] . ', not ' . $times;
			}
		}

		PHPUnit::assertSame(
			[],
			$wrong,
			$this->failureMessage(
				$message,
				"Template modification '$modificationKey' on {$modification['type']}:"
				. "{$modification['template']} does not apply exactly $times "
				. ($times === 1 ? 'time' : 'times') . ' in every template it reaches - '
				. implode('; ', $wrong) . '. A find that matches more than once inserts the block'
				. ' that many times.',
				'assertTemplateModificationAppliedTimes()'
			)
		);
	}

	/**
	 * The enabled template modifications an add-on registers, refusing the three states in which
	 * an assertion over them would pass having checked nothing.
	 *
	 * @param string $addOnId
	 *
	 * @return array<int, array<string, mixed>> - modification_id, modification_key, type, template
	 */
	private function enabledTemplateModificationsOf($addOnId)
	{
		$db = $this->app()->db();

		$addOn = $db->fetchRow('SELECT addon_id, active FROM xf_addon WHERE addon_id = ?', $addOnId);

		if (!$addOn)
		{
			throw new \LogicException(
				"'$addOnId' is not among the installed add-ons on this forum, so it registers no"
				. ' template modification and this would pass having checked nothing. Check the id.'
			);
		}

		if (!$addOn['active'])
		{
			throw new \LogicException(
				"'$addOnId' is installed but disabled, so XenForo leaves its template modifications"
				. ' out of every render and none of them apply. Enable the add-on, or name one that'
				. ' is enabled.'
			);
		}

		$modifications = $db->fetchAll(
			'SELECT modification_id, modification_key, type, template
				FROM xf_template_modification
				WHERE addon_id = ? AND enabled = 1
				ORDER BY modification_key',
			$addOnId
		);

		if (!$modifications)
		{
			throw new \LogicException(
				"'$addOnId' is installed and registers no enabled template modification, so there is"
				. ' nothing to assert and this would pass having checked nothing. Remove the'
				. ' assertion, or name the add-on that owns the modifications. An add-on whose'
				. ' modifications are all disabled is the other way to reach this.'
			);
		}

		return $modifications;
	}

	/**
	 * The log rows XenForo wrote for a modification: one per template it was applied to, each
	 * naming that template, or its id where the template row has since gone.
	 *
	 * @param int $modificationId
	 *
	 * @return array<int, array<string, mixed>> - template_id, apply_count, and `where` for a failure
	 */
	private function templateModificationLogRows($modificationId)
	{
		$rows = $this->app()->db()->fetchAll(
			'SELECT l.template_id, l.apply_count, t.style_id, t.type, t.title
				FROM xf_template_modification_log AS l
				LEFT JOIN xf_template AS t ON (t.template_id = l.template_id)
				WHERE l.modification_id = ?
				ORDER BY l.template_id',
			$modificationId
		);

		foreach ($rows AS $i => $row)
		{
			$rows[$i]['apply_count'] = (int) $row['apply_count'];
			$rows[$i]['where'] = $row['title'] === null
				? "template id {$row['template_id']}"
				: "{$row['type']}:{$row['title']} (style {$row['style_id']})";
		}

		return $rows;
	}

	/**
	 * Refuse an apply count that is not a positive integer.
	 *
	 * Zero is refused rather than meaning "does not apply": a modification applying nowhere is
	 * what the plain assertion fails on, and asserting it would pin a stale find as correct.
	 *
	 * @param mixed $times - mixed on purpose, so the guard is live whatever the caller passed
	 * @param string $helper
	 *
	 * @return void
	 */
	private function requireApplyTimes($times, $helper)
	{
		if (!is_int($times) || $times < 1)
		{
			throw new \LogicException(
				"$helper takes the number of times the modification must apply in each template it"
				. ' reaches, as a positive integer - ' . var_export($times, true) . ' given. To assert'
				. ' only that it applies at all, use the assertion without Times.'
			);
		}
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
			. ' The call this takes is ' . $this->callForm($helper) . '.'
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
				. ' is nothing for an escaping flag to do and they do not take one. The call this takes'
				. ' is ' . $this->callForm($helper) . '.'
			);
		}

		return $message === null || $message === '' ? $description : "$message - $description";
	}

	/**
	 * The call an assertion takes, written out, for a refusal about which argument went where.
	 *
	 * Read off the method rather than written out at each call site, so it cannot disagree with the
	 * signature it is telling the reader to match - the three that escape take the flag third and
	 * the message fourth, the text ones take no flag at all, and that difference is the whole
	 * reason these refusals exist.
	 *
	 * @param string $helper - the assertion's name, as the refusals carry it, with or without ()
	 *
	 * @return string
	 */
	private function callForm($helper)
	{
		$name = str_replace('()', '', $helper);

		if (!method_exists($this, $name))
		{
			return $helper;
		}

		$parameters = [];

		foreach ((new \ReflectionMethod($this, $name))->getParameters() AS $parameter)
		{
			$parameters[] = '$' . $parameter->getName();
		}

		return $name . '(' . implode(', ', $parameters) . ')';
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
