<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use XF\Api\Mvc\Reply\ApiResult;
use XF\Entity\ApiKey;
use XF\Http\Request;
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
	 * This runs the controller the way XenForo does, which is the point: a directly constructed
	 * controller never runs preDispatch(), and XenForo's own xf-make:controller stub puts access
	 * checks there - so an action invoked directly is tested with its authorisation skipped.
	 *
	 * @param string $routePath - as it appears after the ? in a URL, eg 'help/terms'
	 * @param string $type - 'public', 'admin' or 'api'
	 * @param array $input - GET parameters the route reads, as $_GET would carry them
	 *
	 * @return AbstractReply
	 */
	protected function dispatch($routePath, $type = 'public', array $input = [])
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
		$this->swap('app.classType', $classType);

		$request = $this->buildDispatchRequest($routePath, $input);
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
		\XF::triggerRunOnce(true);

		return $reply;
	}

	/**
	 * Call one controller action directly, with a POST request by default - the half of a
	 * controller that dispatch() cannot reach.
	 *
	 * dispatch() only sends a GET, because XenForo asserts a CSRF token in preDispatch() for
	 * anything else and a test has no session to build one from. So saving, toggling and
	 * deleting went untested. This builds the controller the way the dispatcher does and calls
	 * the action, **without preDispatch()** - which means without the CSRF check and without
	 * preDispatchController(), where access checks usually live. It proves what an action does
	 * once let through, never that the guard lets through the right people. Pair it with
	 * dispatch() for that: a GET to a POST-only action returns 405, and 403 without permission.
	 *
	 * Everything else matches the dispatcher, and each part is something a hand-written version
	 * gets wrong:
	 *
	 * - a PrintableException - what FormAction::run() throws for an entity with errors, so every
	 *   standard admin save - comes back as an Error reply whose errors stay keyed by field;
	 * - a reply thrown as XF\Mvc\Reply\Exception, by assertPostOnly(), assertRecordExists() and
	 *   the permission asserts, comes back as that reply;
	 * - the request is swapped into the container as well as handed to the controller, so code
	 *   reading $app->request() sees the same request as the action's $this->request;
	 * - a Reroute is followed, and deferred work queued with \XF::runOnce() runs afterwards.
	 *
	 * @param string $controller - 'XF:Option' or a full class name
	 * @param string $action - as it appears in a route, eg 'save', 'toggle', 'delete'
	 * @param string $type - 'public', 'admin' or 'api'
	 * @param array $input - the request input, as $_POST would carry it
	 * @param array $params - route parameters, eg ['advert_id' => 3]
	 * @param string $method - the request method; POST unless you have a reason
	 *
	 * @return AbstractReply
	 */
	protected function callAction(
		$controller,
		$action,
		$type = 'public',
		array $input = [],
		array $params = [],
		$method = 'POST'
	)
	{
		[$classType, $routerKey] = $this->routeTypeConfig($type);

		// same reason as dispatch(): the controller class is resolved through app.classType
		$this->swap('app.classType', $classType);

		$request = $this->buildDispatchRequest('', $input, $method);
		$this->swap('request', function () use ($request)
		{
			return $request;
		});

		$instance = $this->app()->controller($controller, $request);
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

		\XF::triggerRunOnce(true);

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
	 * @param string $routePath
	 * @param array $input
	 * @param string $method
	 *
	 * @return Request
	 */
	private function buildDispatchRequest($routePath, array $input = [], $method = 'GET')
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

		$request = new Request(
			$container['inputFilterer'],
			$input,
			[],
			[],
			[
				'REQUEST_METHOD' => $method,
				'REQUEST_URI' => '/index.php?' . $routePath
					. ($queryString !== '' ? '&' . $queryString : ''),
				'SCRIPT_NAME' => '/index.php',
				'QUERY_STRING' => $queryString,
				'HTTP_HOST' => $host,
				// a public controller's assertIpNotBanned() throws 'Invalid string IP' on an
				// empty one, which is what a CLI request has
				'REMOTE_ADDR' => '127.0.0.1',
			]
		);
		$request->setCookiePrefix($container['config']['cookie']['prefix']);

		return $request;
	}

	/**
	 * @param string $type
	 *
	 * @return array - [app class type, router container key]
	 */
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
	 * @param string|null $errorText - optional, matched as a substring of the error text. Named
	 *                                 $message before 4.3.0; positional calls are unaffected
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
	 * @param string|null $message
	 *
	 * @return string
	 */
	private function replyFailure(AbstractReply $reply, $message)
	{
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
