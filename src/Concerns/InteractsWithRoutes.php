<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Extension;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Api\Mvc\Reply\ApiResult;
use XF\App;
use XF\Entity\ApiKey;
use XF\Http\Request;
use XF\Mvc\Controller;
use XF\Mvc\Dispatcher;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Exception as ReplyException;
use XF\Mvc\Reply\Message;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\Reroute;
use XF\Mvc\Reply\View;
use XF\Mvc\RouteMatch;
use XF\Phrase;
use XF\PrintableException;

trait InteractsWithRoutes
{
	/**
	 * How many times a reply may reroute before we give up. XenForo's own loop has no limit, and
	 * a test that spins is worse than one that fails.
	 */
	private const MAX_REROUTES = 10;

	/** route type => [app class type, router container key] */
	private const ROUTE_TYPES = [
		'public' => ['Pub', 'router.public'],
		'admin' => ['Admin', 'router.admin'],
		'api' => ['Api', 'router.api'],
	];

	/** @var bool */
	private $apiKeyActing = false;

	/** @var array - the class types whose setup event has already fired in this test */
	private $appSetupFired = [];

	protected function setUpRoutes()
	{
		$this->beforeApplicationDestroyed(function ()
		{
			$this->restoreApiKey();
		});
	}

	/**
	 * Run api dispatches as a given api key.
	 *
	 * \XF::$apiKey is a static that nothing otherwise resets, so a key set by hand leaks into
	 * every later test in the run. This restores it in teardown.
	 *
	 * Two fields decide what the guards make of a key, and neither is guessable: is_super_user
	 * drives the key_type getter that assertSuperUserKey() reads, and allow_all_scopes
	 * short-circuits hasScope() ahead of the scopes array.
	 *
	 * @param array $values - columns for the key, eg ['is_super_user' => true]
	 *
	 * @return ApiKey
	 */
	protected function actingAsApiKey(array $values = [])
	{
		$key = $this->makeEntity('XF:ApiKey', $values + [
			'api_key' => 'test-' . bin2hex(random_bytes(8)),
			'is_super_user' => true,
			'allow_all_scopes' => true,
			'user_id' => \XF::visitor()->user_id,
		]);

		if (!($key instanceof ApiKey))
		{
			throw new \LogicException(
				'Expected XF:ApiKey to resolve to a ' . ApiKey::class
				. ', got ' . get_class($key)
			);
		}

		\XF::setApiKey($key);
		$this->apiKeyActing = true;

		return $key;
	}

	/**
	 * A super-user key alone does not make \XF::isApiBypassingPermissions() true - that also needs
	 * api_bypass_permissions on the request, which dispatch() cannot send. An endpoint relying on
	 * the bypass is not reachable this way.
	 *
	 * @return void
	 */
	private function restoreApiKey()
	{
		if ($this->apiKeyActing)
		{
			$this->destroyProperty(\XF::class, 'apiKey');
			$this->apiKeyActing = false;
		}
	}

	/**
	 * Dispatch a route and return the reply its controller produced, without rendering it.
	 *
	 * The controller's preDispatch() runs, so its access checks are exercised.
	 *
	 * @param string $routePath - as it appears after the ? in a URL, eg 'help/terms'
	 * @param string $type - 'public', 'admin' or 'api'
	 * @param array $input - GET parameters the route reads, as $_GET would carry them
	 * @param array $server - request server values, as $_SERVER would carry them - eg REMOTE_ADDR,
	 *                        HTTP_USER_AGENT, HTTP_REFERER - merged over the defaults
	 * @param string $method - the request method. A non-GET is sent with a CSRF token the request
	 *                         carries a matching cookie for, so the action behind the check is
	 *                         reachable. Pass your own `_xfToken` in $input to send something else,
	 *                         or use dispatchWithoutCsrfToken() to send none
	 *
	 * @return AbstractReply
	 */
	protected function dispatch(
		$routePath,
		$type = 'public',
		array $input = [],
		array $server = [],
		$method = 'GET'
	)
	{
		return $this->dispatchRequest($routePath, $type, $input, $server, $method, true);
	}

	/**
	 * Dispatch a route sending no CSRF token at all, for asserting that the check refuses.
	 *
	 * XenForo answers a missing token with a 400 error reply. Without this, a suite could not tell
	 * the difference between a guard that fires and one that has been deleted, since dispatch()
	 * sends a valid token for every non-GET.
	 *
	 * @param string $routePath
	 * @param string $type
	 * @param array $input
	 * @param array $server
	 * @param string $method - pointless as a GET, which XenForo does not check
	 *
	 * @return AbstractReply
	 */
	protected function dispatchWithoutCsrfToken(
		$routePath,
		$type = 'public',
		array $input = [],
		array $server = [],
		$method = 'POST'
	)
	{
		return $this->dispatchRequest($routePath, $type, $input, $server, $method, false);
	}

	/**
	 * @param string $routePath
	 * @param string $type
	 * @param array $input
	 * @param array $server
	 * @param string $method
	 * @param bool $withCsrfToken
	 *
	 * @return AbstractReply
	 */
	private function dispatchRequest($routePath, $type, array $input, array $server, $method, $withCsrfToken)
	{
		[$classType, $routerKey] = $this->routeTypeConfig($type);

		if ($type === 'admin' && !\XF::visitor()->is_admin)
		{
			throw new \LogicException(
				"Dispatching an admin route needs a visitor with is_admin set - XenForo's admin"
				. " controllers assert it and reroute to the login form, which arrives as an"
				. " ordinary view with a 200 response rather than as an error."
				. " Use actingAsMember(['is_admin' => true])."
			);
		}

		// Controllers and views are resolved through app.classType (XF\App builds them with
		// stringToClass('%s\%s\Controller\%s', $c['app.classType'])), and this package's app
		// forces 'Cli' so that cmd.php-style code works. Every route therefore resolves to a
		// controller class that does not exist, and dispatching gives 'invalid_controller'.
		// This also puts an application of that type in \XF::app() and fires its setup event.
		$this->setAppClassType($type);

		$request = $this->buildDispatchRequest($routePath, $input, $method, $server, [], $withCsrfToken);
		$this->swap('request', function () use ($request)
		{
			return $request;
		});

		$dispatcher = new Dispatcher($this->app(), $request);
		$dispatcher->setRouter($this->app()->container($routerKey));

		$reply = $this->resolveReply($dispatcher, $dispatcher->route($routePath), $routePath);

		// XF\Mvc\Dispatcher::dispatchLoop() triggers the run-once queue, and resolveReply() does not
		// go through it - so without this, deferred work a controller queued during the dispatch
		// never runs. Entity postSave cache rebuilds are the common case. Rethrows, so a failure in
		// deferred work fails the test rather than being logged and swallowed.
		$this->drainRunOnce();

		return $reply;
	}

	/**
	 * Call one controller action directly, with a POST request by default.
	 *
	 * The action runs **without preDispatch()**, so neither the CSRF check nor the controller's
	 * access checks run - which is the difference from dispatch(), rather than the method. Use
	 * dispatch() when the guards are part of what the test is proving.
	 *
	 * Otherwise it matches the dispatcher:
	 *
	 * - a PrintableException, as FormAction::run() throws for an entity with errors, comes back
	 *   as an Error reply with its errors keyed by field;
	 * - a reply thrown as XF\Mvc\Reply\Exception, by assertPostOnly(), assertRecordExists() and
	 *   the permission asserts, comes back as that reply;
	 * - the request is placed in the container as well as passed to the controller;
	 * - a Reroute is followed, and deferred work queued with \XF::runOnce() runs afterwards.
	 *
	 * @param string $controller - 'XF:Option' or a full class name
	 * @param string $action - as it appears in a route, eg 'save', 'toggle', 'delete'
	 * @param string $type - 'public', 'admin' or 'api'
	 * @param array $input - the request input, as $_POST would carry it
	 * @param array $params - route parameters, eg ['advert_id' => 3]
	 * @param string $method - the request method; POST unless you have a reason
	 * @param array $server - request server values, as $_SERVER would carry them, merged over the
	 *                        defaults
	 *
	 * @return AbstractReply
	 */
	protected function callAction(
		$controller,
		$action,
		$type = 'public',
		array $input = [],
		array $params = [],
		$method = 'POST',
		array $server = [],
		array $files = []
	)
	{
		[$classType, $routerKey] = $this->routeTypeConfig($type);

		// same reason as dispatch(): the controller class is resolved through app.classType, and
		// an add-on may rely on the setup event for this type having fired
		$this->setAppClassType($type);

		$request = $this->buildDispatchRequest('', $input, $method, $server, $files);
		$this->swap('request', function () use ($request)
		{
			return $request;
		});

		$this->requireBaseControllerClass($controller);

		$instance = $this->resolveController($controller, $request);
		if (!$instance)
		{
			throw new \LogicException(
				"Controller '$controller' does not exist for route type '$type'"
			);
		}

		// the dispatcher's own normalisation, so 'save' and 'save-draft' mean what a route means
		$actionName = str_replace(' ', '', ucwords(preg_replace('#[^a-z0-9]#i', ' ', $action)));
		$actionMethod = 'action' . $actionName;
		if (!is_callable([$instance, $actionMethod]))
		{
			throw new \LogicException(
				"Controller '" . get_class($instance) . "' has no action '$action' ($actionMethod)"
			);
		}

		$instance->setResponseType($type === 'api' ? 'api' : 'html');
		$parameterBag = new ParameterBag($params);

		try
		{
			$reply = $instance->$actionMethod($parameterBag);
		}
		catch (PrintableException $e)
		{
			$reply = new Error($e->getMessages());
		}
		catch (ReplyException $e)
		{
			$reply = $e->getReply();
		}

		if (!$reply instanceof AbstractReply)
		{
			throw new \LogicException(
				"Action '$action' on '" . get_class($instance) . "' returned no reply"
			);
		}

		$instance->postDispatch($actionName, $parameterBag, $reply);
		$reply->setControllerClass(get_class($instance));
		$reply->setAction($actionName);

		if ($reply instanceof Reroute)
		{
			$dispatcher = new Dispatcher($this->app(), $request);
			$dispatcher->setRouter($this->app()->container($routerKey));

			$reply = $this->resolveReply($dispatcher, $reply->getMatch(), "$controller::$action");
		}

		$this->drainRunOnce();

		return $reply;
	}

	/**
	 * Resolve reroutes ourselves rather than calling XF\Mvc\Dispatcher::dispatchLoop().
	 *
	 * That method catches every exception the controller throws and hands it to
	 * handleControllerError(), which calls \XF::logException($e, true) - and that second argument
	 * is a rollback. Under UsesDatabaseTransactions it would quietly roll back the test's own
	 * transaction, and the exception a test most wants to see is replaced by a generic 500.
	 *
	 * @param Dispatcher $dispatcher
	 * @param RouteMatch $match
	 * @param string $routePath - for the error message only
	 *
	 * @return AbstractReply
	 */
	private function resolveReply(Dispatcher $dispatcher, RouteMatch $match, $routePath)
	{
		$reply = $dispatcher->dispatchFromMatch($match);

		for ($hop = 0; $reply instanceof Reroute; $hop++)
		{
			if ($hop >= self::MAX_REROUTES)
			{
				throw new \LogicException(
					"Route '$routePath' was still rerouting after " . self::MAX_REROUTES . ' hops'
				);
			}

			$reply = $dispatcher->dispatchFromMatch($reply->getMatch());
		}

		return $reply;
	}

	/**
	 * A request a controller will accept. XenForo's own request is built from the superglobals,
	 * which under PHPUnit describe no request at all.
	 *
	 * Server values the caller passes are merged over the defaults here, at construction, because
	 * Request caches what it derives from them - the IP address and the robot name - on first read.
	 * REQUEST_METHOD always comes from $method.
	 *
	 * @param string $routePath
	 * @param array $input
	 * @param string $method
	 * @param array $server
	 *
	 * @return Request
	 */
	/**
	 * Refuse a controller named by an add-on's extension of it.
	 *
	 * An extension inherits from the XFCP proxy XenForo declares while it resolves the class being
	 * extended, so naming the extension loads a parent that does not exist yet - unless something
	 * earlier in the run resolved that class, which makes it pass or fail on test order.
	 *
	 * @param mixed $controller
	 *
	 * @return void
	 */
	private function requireBaseControllerClass($controller)
	{
		if (!is_string($controller) || strpos($controller, '\\') === false)
		{
			return;
		}

		$extension = $this->app()->container('extension');

		if (!($extension instanceof Extension))
		{
			return;
		}

		$baseClass = $extension->classExtendedBy($controller);

		if ($baseClass !== null)
		{
			throw new \LogicException($this->extendedControllerMessage($controller, $baseClass));
		}
	}

	/**
	 * An extension registered by an add-on the test did not load is not in the extension map, so
	 * the refusal above cannot see it - PHP raises an Error for the missing proxy instead.
	 *
	 * @param string $controller
	 * @param Request $request
	 *
	 * @return Controller|null
	 */
	private function resolveController($controller, Request $request)
	{
		try
		{
			return $this->app()->controller($controller, $request);
		}
		catch (\Error $e)
		{
			if (strpos($e->getMessage(), 'XFCP_') === false)
			{
				throw $e;
			}

			throw new \LogicException($this->extendedControllerMessage($controller), 0, $e);
		}
	}

	/**
	 * @param string $controller
	 * @param string|null $baseClass
	 *
	 * @return string
	 */
	private function extendedControllerMessage($controller, $baseClass = null)
	{
		$name = $baseClass !== null ? "'" . $baseClass . "'" : 'the class it extends';

		return "'$controller' extends another controller: name $name instead."
			. ' XenForo declares the XFCP proxy an extension inherits from while it resolves the'
			. ' class being extended, so naming the extension works only once something else in the'
			. ' run has resolved that class. Naming the base is also what proves the extension'
			. ' applied, since XenForo resolves it to the most derived class - which is yours.';
	}

	/**
	 * Build a `$_FILES` entry for callAction(), from the contents you want the file to have.
	 *
	 * XF\Http\Upload needs a readable `tmp_name`, so this writes a real temporary file. It is
	 * removed when the test finishes.
	 *
	 * @param string $contents
	 * @param string $name - the filename the upload reports
	 * @param string|null $type - the mime type; guessed from the contents when not given
	 *
	 * @return array
	 */
	protected function uploadedFile($contents, $name = 'upload.txt', $type = null)
	{
		$tempFile = tempnam(sys_get_temp_dir(), 'xftf');

		if ($tempFile === false)
		{
			throw new \LogicException('Could not create a temporary file for the upload');
		}

		file_put_contents($tempFile, $contents);

		$this->beforeApplicationDestroyed(function () use ($tempFile)
		{
			if (file_exists($tempFile))
			{
				unlink($tempFile);
			}
		});

		return [
			'name' => $name,
			'type' => $type ?: (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream',
			'size' => strlen($contents),
			'tmp_name' => $tempFile,
			'error' => UPLOAD_ERR_OK,
		];
	}

	/**
	 * Build a `$_FILES` entry for callAction() from a file on disk.
	 *
	 * The file is copied, so the code under test cannot move or delete your fixture.
	 *
	 * @param string $path
	 * @param string|null $name - the filename the upload reports; the file's own by default
	 * @param string|null $type
	 *
	 * @return array
	 */
	protected function uploadedFileFromPath($path, $name = null, $type = null)
	{
		if (!is_readable($path))
		{
			throw new \LogicException("Cannot read '$path' to upload it");
		}

		return $this->uploadedFile(file_get_contents($path), $name ?: basename($path), $type);
	}

	private function buildDispatchRequest($routePath, array $input = [], $method = 'GET', array $server = [], array $files = [], $withCsrfToken = true)
	{
		$method = strtoupper($method);

		$container = $this->app()->container();
		// XF\Options is an ArrayObject, so this reads the option without going through the magic
		// property accessor that static analysis cannot see. The host has to match boardUrl, or a
		// public controller's assertCanonicalBaseUrl() redirects and every reply is a Redirect.
		$host = parse_url((string) $this->app()->options()['boardUrl'], PHP_URL_HOST) ?: 'localhost';

		// the route path cannot carry the parameters - the router takes the whole string as the
		// path, so 'thing?id=1' is a 404 - which is why they come in as an array instead. A POST
		// carries them in the body, so its query string stays empty
		$queryString = $method === 'GET' ? http_build_query($input) : '';

		// the board index is routed by an empty path, and its canonical url is 'index.php' with no
		// query at all - so a trailing '?' here makes assertCanonicalUrl() redirect the request to
		// itself rather than dispatching it
		$queryParts = array_filter([$routePath, $queryString], function ($part)
		{
			return $part !== '';
		});
		$query = implode('&', $queryParts);

		$defaults = [
			'REQUEST_URI' => '/index.php' . ($query !== '' ? '?' . $query : ''),
			'SCRIPT_NAME' => '/index.php',
			'QUERY_STRING' => $queryString,
			'HTTP_HOST' => $host,
			// a public controller's assertIpNotBanned() throws 'Invalid string IP' on an
			// empty one, which is what a CLI request has
			'REMOTE_ADDR' => '127.0.0.1',
		];

		$cookies = [];

		if ($method !== 'GET' && $withCsrfToken && !isset($input['_xfToken']))
		{
			// XenForo asserts a CSRF token in preDispatch() for anything that is not a GET, and
			// checks it against the `csrf` cookie - so without a matching pair every write returns
			// a 400 and the action is unreachable. The container's own validator derives the token
			// from the cookie, so a pair minted here is the one core will accept. A token the test
			// supplied itself is left alone, which is how an invalid one can still be tested
			$cookieValue = \XF::generateRandomString(16);
			$validator = $container['csrf.validator'];

			$cookies[$container['config']['cookie']['prefix'] . 'csrf'] = $cookieValue;
			$input['_xfToken'] = \XF::$time . ',' . $validator($cookieValue, \XF::$time);
		}

		$request = new Request(
			$container['inputFilterer'],
			$input,
			$files,
			$cookies,
			['REQUEST_METHOD' => $method] + array_replace($defaults, $server)
		);
		$request->setCookiePrefix($container['config']['cookie']['prefix']);

		return $request;
	}

	/**
	 * @param string $type
	 *
	 * @return array - [app class type, router container key]
	 */
	/**
	 * Put an application of the given type in place, as a real request would have.
	 *
	 * dispatch() calls this, so a test usually does not. Call it directly before renderTemplate()
	 * or renderMacro() when what you are rendering depends on the application type.
	 *
	 * The framework boots XF\App itself, which is what makes the container usable from PHPUnit, so
	 * two things an add-on may rely on are otherwise missing: the `app_pub_setup` event and its
	 * siblings never fire, so a container key registered by one does not exist; and a listener
	 * gated on `$app instanceof \XF\Pub\App` - the usual way of saying "only on public pages" -
	 * never runs its body, silently.
	 *
	 * So \XF::app() becomes an instance of XenForo's own app class for that type, sharing this
	 * application's container, and the setup event fires with it once per test. The application
	 * the framework itself uses, and that app() returns, is unchanged.
	 *
	 * @param string $type - 'public', 'admin' or 'api'
	 *
	 * @return App - the stand-in now in \XF::app()
	 */
	protected function setAppClassType($type)
	{
		[$classType, $routerKey] = $this->routeTypeConfig($type);

		$this->swap('app.classType', $classType);

		// XF\App hard-wires `router` to router.public, and XF\Admin\App and XF\Api\App each
		// override it. Without this a link a controller builds with no type - which is what
		// XF\Mvc\Controller::buildLink() does - comes from the public router during an admin or api
		// dispatch, so a redirect carries index.php and a route the public router does not know,
		// and the url cannot be asserted
		$this->swap('router', function () use ($routerKey)
		{
			return $this->app()->container($routerKey);
		});

		$appClass = "XF\\$classType\\App";

		if (!(\XF::app() instanceof $appClass))
		{
			// built without its constructor, because that would re-register every container entry
			// over the top of whatever this test has swapped into it
			$standIn = (new \ReflectionClass($appClass))->newInstanceWithoutConstructor();

			$container = new \ReflectionProperty(App::class, 'container');
			$container->setAccessible(true);
			$container->setValue($standIn, $this->app()->container());

			$this->writeStaticProperty(\XF::class, 'app', $standIn);
		}

		if (!isset($this->appSetupFired[$classType]))
		{
			$this->appSetupFired[$classType] = true;

			// the event XenForo fires at the end of its own setup for this app type
			$this->app()->fire('app_' . strtolower($classType) . '_setup', [\XF::app()]);
		}

		return \XF::app();
	}

	private function routeTypeConfig($type)
	{
		if (!isset(self::ROUTE_TYPES[$type]))
		{
			throw new \LogicException(
				"Unknown route type '$type' - expected one of "
				. implode(', ', array_keys(self::ROUTE_TYPES))
			);
		}

		return self::ROUTE_TYPES[$type];
	}

	/**
	 * Assert that the reply is a view - what a controller returns when it renders a page.
	 *
	 * @param AbstractReply $reply
	 * @param string|null $message - added to the failure, for a test that dispatches several routes
	 *
	 * @return void
	 */
	protected function assertReplyIsView(AbstractReply $reply, $message = null)
	{
		PHPUnit::assertInstanceOf(View::class, $reply, $this->replyFailure($reply, $message));
	}

	/**
	 * Assert that the reply is a view rendering the given template.
	 *
	 * @param AbstractReply $reply
	 * @param string $template
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyTemplate(AbstractReply $reply, $template, $message = null)
	{
		$this->assertReplyIsView($reply, $message);

		/** @var View $reply */
		PHPUnit::assertSame(
			$template,
			$reply->getTemplateName(),
			$this->replyFailure($reply, $message)
		);
	}

	/**
	 * Assert that the reply is a view using the given view class, as short name or full class.
	 *
	 * @param AbstractReply $reply
	 * @param string $viewClass
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyViewClass(AbstractReply $reply, $viewClass, $message = null)
	{
		$this->assertReplyIsView($reply, $message);

		/** @var View $reply */
		PHPUnit::assertSame(
			$viewClass,
			$reply->getViewClass(),
			$this->replyFailure($reply, $message)
		);
	}

	/**
	 * Assert that the reply is a view which passed the given parameter to its template.
	 *
	 * @param AbstractReply $reply
	 * @param string $key
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyParam(AbstractReply $reply, $key, $message = null)
	{
		$this->assertReplyIsView($reply, $message);

		/** @var View $reply */
		PHPUnit::assertArrayHasKey(
			$key,
			$reply->getParams(),
			$this->replyFailure($reply, $message)
		);
	}

	/**
	 * Assert that a view reply passed a given parameter, and that its value is the one expected.
	 *
	 * This is the four-argument shape, so that a comparison cannot be mistaken for a failure
	 * message: assertReplyParam() asserts a parameter is there, this asserts what it holds.
	 * Comparison is strict, as assertSame() is.
	 *
	 * @param AbstractReply $reply
	 * @param string $key
	 * @param mixed $expected
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyParamSame(AbstractReply $reply, $key, $expected, $message = null)
	{
		$this->assertReplyParam($reply, $key, $message);

		/** @var View $reply */
		PHPUnit::assertSame(
			$expected,
			$reply->getParam($key),
			$this->replyFailure($reply, $message)
		);
	}

	/**
	 * A parameter the reply passed to its template, for asserting on its value.
	 *
	 * @param AbstractReply $reply
	 * @param string $key
	 *
	 * @return mixed
	 */
	protected function replyParam(AbstractReply $reply, $key)
	{
		$this->assertReplyParam($reply, $key);

		/** @var View $reply */
		return $reply->getParam($key);
	}

	/**
	 * Assert that the reply is a redirect, optionally to a given url and of a given kind.
	 *
	 * **A redirect reply has no http status of its own**, so getResponseCode() answers 200 whether
	 * it is permanent or not - the code is chosen later, by the renderer, which maps permanent to
	 * a 301 and temporary to a 303. Assert the type rather than the code.
	 *
	 * @param AbstractReply $reply
	 * @param string|null $url - optional
	 * @param string|null $type - optional, 'permanent' or 'temporary'
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyIsRedirect(AbstractReply $reply, $url = null, $type = null, $message = null)
	{
		PHPUnit::assertInstanceOf(Redirect::class, $reply, $this->replyFailure($reply, $message));

		/** @var Redirect $reply */
		if ($url !== null)
		{
			PHPUnit::assertSame($url, $reply->getUrl(), $this->replyFailure($reply, $message));
		}

		if ($type !== null)
		{
			$known = [Redirect::PERMANENT, Redirect::TEMPORARY];

			if (!in_array($type, $known, true))
			{
				throw new \LogicException(
					"Unknown redirect type '$type' - expected one of " . implode(', ', $known)
				);
			}

			PHPUnit::assertSame($type, $reply->getType(), $this->replyFailure($reply, $message));
		}
	}

	/**
	 * Assert that the reply is an error, optionally with a given response code.
	 *
	 * A route that does not exist arrives here as a 404, and a permission-gated route refusing
	 * the visitor as a 403 - so this is how a test shows that a guard actually guards.
	 *
	 * @param AbstractReply $reply
	 * @param int|null $code - optional http response code
	 * @param string|null $errorText - optional, matched as a substring of the error text
	 * @param string|null $message - added to the failure, for a test that dispatches several routes
	 *
	 * @return void
	 */
	protected function assertReplyIsError(AbstractReply $reply, $code = null, $errorText = null, $message = null)
	{
		PHPUnit::assertInstanceOf(Error::class, $reply, $this->replyFailure($reply, $message));

		if ($code !== null)
		{
			PHPUnit::assertSame(
				$code,
				$reply->getResponseCode(),
				$this->replyFailure($reply, $message)
			);
		}

		if ($errorText !== null)
		{
			PHPUnit::assertStringContainsString(
				$errorText,
				implode(' ', $this->replyErrors($reply)),
				$this->replyFailure($reply, $message)
			);
		}
	}

	/**
	 * The error messages a reply carries, rendered as plain text.
	 *
	 * Worth reaching for whenever more than one guard denies with the same status code: two
	 * different refusals are both a 403, so a test that asserts only the code passes whichever
	 * fired - and keeps passing when the guard it meant to cover is deleted.
	 *
	 * @param AbstractReply $reply
	 *
	 * @return array
	 */
	protected function replyErrors(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(Error::class, $reply, $this->describeReply($reply));

		/** @var Error $reply */
		return array_map(function ($error)
		{
			// XenForo's errors are usually phrases; 'raw' keeps the text unescaped
			return $error instanceof Phrase ? $error->render('raw') : (string) $error;
		}, $reply->getErrors());
	}

	/**
	 * Assert that the reply is an api result - what an api route returns instead of a view.
	 *
	 * @param AbstractReply $reply
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyIsApiResult(AbstractReply $reply, $message = null)
	{
		PHPUnit::assertInstanceOf(ApiResult::class, $reply, $this->replyFailure($reply, $message));
	}

	/**
	 * The rendered body of an api reply, for asserting on the fields a client will actually see.
	 *
	 * Note that rendering is not recursive: a nested entity comes back as another result object
	 * needing its own render(), rather than as data.
	 *
	 * @param AbstractReply $reply
	 *
	 * @return mixed
	 */
	protected function replyApiResult(AbstractReply $reply)
	{
		$this->assertReplyIsApiResult($reply);

		/** @var ApiResult $reply */
		return $reply->getApiResult()->render();
	}

	/**
	 * Assert that the reply is a simple message, as returned by an action with nothing to render.
	 *
	 * @param AbstractReply $reply
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertReplyIsMessage(AbstractReply $reply, $message = null)
	{
		PHPUnit::assertInstanceOf(Message::class, $reply, $this->replyFailure($reply, $message));
	}

	/**
	 * A failure message carrying both what the caller said and what the reply actually was.
	 *
	 * @param AbstractReply $reply
	 * @param mixed $message - a string or null by contract, anything at all in practice: the guard
	 *                         below is what turns a caller's mistake into an explanation
	 *
	 * @return string
	 */
	private function replyFailure(AbstractReply $reply, $message)
	{
		if ($message !== null && !is_string($message))
		{
			// the trailing argument of every reply assertion is a failure message. Passed a value -
			// reaching for Laravel's assertViewHas($key, $value) shape - the assertion still passes,
			// whatever the reply holds, because nothing compares it
			throw new \LogicException(
				'The last argument of a reply assertion is a failure message, not an expected value.'
				. ' To compare a value, use assertReplyParamSame($reply, $key, $expected), or read it'
				. ' with replyParam() and assert on it yourself.'
			);
		}

		$description = $this->describeReply($reply);

		return $message === null || $message === '' ? $description : "$message - $description";
	}

	/**
	 * What the reply actually was, for a failure message. A dispatch that went wrong usually
	 * produces an error reply whose text says why, and that text is the useful part.
	 *
	 * @param AbstractReply $reply
	 *
	 * @return string
	 */
	private function describeReply(AbstractReply $reply)
	{
		// a redirect's code is not its own - the renderer picks 301 or 303 from the type later -
		// so printing getResponseCode()'s 200 beside it would be the misreading this avoids
		$description = 'got ' . get_class($reply)
			. ($reply instanceof Redirect ? '' : ' (' . $reply->getResponseCode() . ')');

		if ($reply instanceof Error)
		{
			$errors = array_map(function ($error)
			{
				return (string) $error;
			}, $reply->getErrors());

			$description .= ': ' . implode('; ', $errors);
		}
		else if ($reply instanceof Message)
		{
			$description .= ': ' . $reply->getMessage();
		}
		else if ($reply instanceof Redirect)
		{
			$description .= ' (' . $reply->getType() . ') to ' . $reply->getUrl();
		}
		else if ($reply instanceof View)
		{
			$description .= ' rendering template ' . var_export($reply->getTemplateName(), true);
		}
		else if ($reply instanceof ApiResult)
		{
			$description .= ' carrying an api result';
		}

		return $description;
	}
}
