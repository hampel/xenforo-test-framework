<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use XF\Api\Mvc\Reply\ApiResult;
use XF\Entity\ApiKey;
use XF\Http\Request;
use XF\Mvc\Dispatcher;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Message;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\Reroute;
use XF\Mvc\Reply\View;
use XF\Mvc\RouteMatch;
use XF\Phrase;

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
	 *
	 * @return Request
	 */
	private function buildDispatchRequest($routePath, array $input = [])
	{
		$container = $this->app()->container();
		// XF\Options is an ArrayObject, so this reads the option without going through the magic
		// property accessor that static analysis cannot see. The host has to match boardUrl, or a
		// public controller's assertCanonicalBaseUrl() redirects and every reply is a Redirect.
		$host = parse_url((string) $this->app()->options()['boardUrl'], PHP_URL_HOST) ?: 'localhost';

		// the route path cannot carry the parameters - the router takes the whole string as the
		// path, so 'thing?id=1' is a 404 - which is why they come in as an array instead
		$queryString = http_build_query($input);

		$request = new Request(
			$container['inputFilterer'],
			$input,
			[],
			[],
			[
				'REQUEST_METHOD' => 'GET',
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
	 *
	 * @return void
	 */
	protected function assertReplyIsView(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(View::class, $reply, $this->describeReply($reply));
	}

	/**
	 * Assert that the reply is a view rendering the given template.
	 *
	 * @param AbstractReply $reply
	 * @param string $template
	 *
	 * @return void
	 */
	protected function assertReplyTemplate(AbstractReply $reply, $template)
	{
		$this->assertReplyIsView($reply);

		/** @var View $reply */
		PHPUnit::assertSame($template, $reply->getTemplateName());
	}

	/**
	 * Assert that the reply is a view using the given view class, as short name or full class.
	 *
	 * @param AbstractReply $reply
	 * @param string $viewClass
	 *
	 * @return void
	 */
	protected function assertReplyViewClass(AbstractReply $reply, $viewClass)
	{
		$this->assertReplyIsView($reply);

		/** @var View $reply */
		PHPUnit::assertSame($viewClass, $reply->getViewClass());
	}

	/**
	 * Assert that the reply is a view which passed the given parameter to its template.
	 *
	 * @param AbstractReply $reply
	 * @param string $key
	 *
	 * @return void
	 */
	protected function assertReplyParam(AbstractReply $reply, $key)
	{
		$this->assertReplyIsView($reply);

		/** @var View $reply */
		PHPUnit::assertArrayHasKey($key, $reply->getParams());
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
	 * Assert that the reply is a redirect, optionally to a given url.
	 *
	 * @param AbstractReply $reply
	 * @param string|null $url - optional
	 *
	 * @return void
	 */
	protected function assertReplyIsRedirect(AbstractReply $reply, $url = null)
	{
		PHPUnit::assertInstanceOf(Redirect::class, $reply, $this->describeReply($reply));

		if ($url !== null)
		{
			/** @var Redirect $reply */
			PHPUnit::assertSame($url, $reply->getUrl());
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
	 *
	 * @return void
	 */
	protected function assertReplyIsError(AbstractReply $reply, $code = null, $message = null)
	{
		PHPUnit::assertInstanceOf(Error::class, $reply, $this->describeReply($reply));

		if ($code !== null)
		{
			PHPUnit::assertSame($code, $reply->getResponseCode(), $this->describeReply($reply));
		}

		if ($message !== null)
		{
			PHPUnit::assertStringContainsString(
				$message,
				implode(' ', $this->replyErrors($reply)),
				$this->describeReply($reply)
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
	 *
	 * @return void
	 */
	protected function assertReplyIsApiResult(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(ApiResult::class, $reply, $this->describeReply($reply));
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
	 *
	 * @return void
	 */
	protected function assertReplyIsMessage(AbstractReply $reply)
	{
		PHPUnit::assertInstanceOf(Message::class, $reply, $this->describeReply($reply));
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
		$description = 'got ' . get_class($reply) . ' (' . $reply->getResponseCode() . ')';

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
			$description .= ' to ' . $reply->getUrl();
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
