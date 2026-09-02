<?php

namespace Hampel\Testing\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use PHPUnit\Framework\Assert as PHPUnit;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

trait InteractsWithHttp
{
	private $history = [];

	/**
	 * Mock the Http client
	 *
	 * @param array $responseStack - an array of Guzzle Psr7 Responses or Request Exceptions to return, one for each request
	 * @param bool $untrusted - set to true when using the untrusted client
	 *
	 * @return Client
	 */
	protected function fakesHttp(array $responseStack, $untrusted = false)
	{
		$handlerStack = HandlerStack::create(new MockHandler($responseStack));

		return $this->installHttpFake($handlerStack, $untrusted);
	}

	/**
	 * Mock the Http client, choosing the response by URL rather than by call order.
	 *
	 * fakesHttp() hands out responses from a queue, so a test breaks when the code under test
	 * changes the order it makes requests in, or makes one more than expected. This matches on
	 * the request URL instead.
	 *
	 * Patterns are fnmatch() patterns tried in order, so `*` on its own is a catch-all and
	 * should come last. A request matching nothing is an error rather than a silent null: a
	 * test should say which calls it expects.
	 *
	 * @param array $responseMap - pattern => Guzzle Psr7 Response, exception, or callable
	 *                             receiving the request
	 * @param bool $untrusted - set to true when using the untrusted client
	 *
	 * @return Client
	 */
	protected function fakesHttpByUrl(array $responseMap, $untrusted = false)
	{
		$handler = function (RequestInterface $request, array $options) use ($responseMap)
		{
			$url = (string) $request->getUri();

			foreach ($responseMap AS $pattern => $response)
			{
				if ($pattern !== '*' && !fnmatch($pattern, $url))
				{
					continue;
				}

				if (is_callable($response))
				{
					$response = $response($request);
				}

				if ($response instanceof \Throwable)
				{
					return Create::rejectionFor($response);
				}

				if ($response instanceof ResponseInterface)
				{
					$this->writeHttpSink($response, $options);
				}

				return Create::promiseFor($response);
			}

			throw new \RuntimeException(
				"No fake HTTP response matches [{$url}] - add a pattern for it, or '*' to catch "
					. 'anything unmatched.'
			);
		};

		return $this->installHttpFake(HandlerStack::create($handler), $untrusted);
	}

	/**
	 * Install a faked Http client built around the given handler stack.
	 *
	 * @param HandlerStack $handlerStack
	 * @param bool $untrusted - set to true when using the untrusted client
	 *
	 * @return Client
	 */
	private function installHttpFake(HandlerStack $handlerStack, $untrusted)
	{
		$handlerStack->push(Middleware::history($this->history));

		$http = $this->app()->http();
		$key = $untrusted ? 'clientUntrusted' : 'client';

		$this->swap([$http, $key], function ($c) use ($http, $handlerStack)
		{
			return $http->createClient(['handler' => $handlerStack]);
		});

		// XF builds `reader` and `metadataFetcher` once and they hold the clients by value, so
		// without this a fake installed after either has already resolved - which includes any
		// second fake in the same test - is swapped into a client nothing goes on to use.
		$container = $http->container();
		$container->decache('reader');
		$container->decache('metadataFetcher');

		return $container[$key];
	}

	/**
	 * Write a faked response body to the request's sink, if it asked for one.
	 *
	 * Guzzle's own handlers do this, including the MockHandler behind fakesHttp(), and
	 * XF\Http\Reader::getUntrusted($url, $limits, $saveTo) relies on it to download to a file.
	 * A fake that skips it delivers the response and writes nothing, which looks like a bug in
	 * the code under test.
	 *
	 * @param ResponseInterface $response
	 * @param array $options - the Guzzle request options
	 *
	 * @return void
	 */
	private function writeHttpSink(ResponseInterface $response, array $options)
	{
		if (!isset($options['sink']))
		{
			return;
		}

		$contents = (string) $response->getBody();
		$sink = $options['sink'];

		if (is_resource($sink))
		{
			fwrite($sink, $contents);
		}
		else if (is_string($sink))
		{
			file_put_contents($sink, $contents);
		}
		else if ($sink instanceof StreamInterface)
		{
			$sink->write($contents);
		}
	}

	/**
	 * Return an array of all Http client history
	 *
	 * @return array
	 */
	protected function getHttpHistory()
	{
		return $this->history;
	}

	/**
	 * Return an array of all Http client requests made
	 *
	 * @return array
	 */
	protected function getHttpRequests()
	{
		return array_map(function ($item)
		{
			return $item['request'] ?: null;
		}, $this->getHttpHistory());
	}

	/**
	 * Assert if request was sent based on a truth-test callback.
	 *
	 * @param  callable|int|null  $callback
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertHttpRequestSent($callback = null)
	{
		if (is_numeric($callback))
		{
			$this->assertHttpRequestSentTimes($callback);
			return;
		}

		$sentRequests = $this->sentHttpRequests($callback);

		PHPUnit::assertTrue(
			count($sentRequests) > 0,
			"The expected request was not sent."
		);
	}

	/**
	 * Assert that a request was sent a number of times.
	 *
	 * @param int $times
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertHttpRequestSentTimes($times = 1)
	{
		$sentRequests = $this->getHttpRequests();

		PHPUnit::assertTrue(
			($count = count($sentRequests)) === $times,
			"The expected request was sent {$count} times instead of {$times} times."
		);
	}

	/**
	 * Determine if request was not sent based on a truth-test callback.
	 *
	 * @param  callable|null  $callback
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertHttpRequestNotSent($callback = null)
	{
		$sentRequests = $this->sentHttpRequests($callback);

		PHPUnit::assertTrue(
			count($sentRequests) === 0,
			"Unexpected request was sent."
		);
	}

	/**
	 * Assert that no requests were sent.
	 *
	 * @return void
	 *
	 * @throws \Exception
	 */
	protected function assertNoHttpRequestSent()
	{
		$sentRequests = $this->getHttpRequests();

		PHPUnit::assertEmpty($sentRequests, 'Requests were sent unexpectedly.');
	}

	/**
	 * Get all of the sent requests matching a truth-test callback.
	 *
	 * @param  callable|null  $callback
	 * @return array
	 *
	 * @throws \Exception
	 */
	private function sentHttpRequests($callback = null)
	{
		$callback = $callback ?: function ()
		{
			return true;
		};

		$sentRequests = $this->getHttpRequests();

		return array_filter($sentRequests, function ($request) use ($callback)
		{
			return $callback($request);
		});
	}
}
