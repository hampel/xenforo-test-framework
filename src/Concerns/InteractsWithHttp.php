<?php namespace Hampel\Testing\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\Assert as PHPUnit;

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
		$handlerStack->push(Middleware::history($this->history));
		$http = $this->app()->http();

		return $this->swap([$http, $untrusted ? 'clientUntrusted' : 'client'], function ($c) use ($http, $handlerStack) {
			return $http->createClient(['handler' => $handlerStack]);
		});
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
		return array_map(function ($item) {
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
        if (is_numeric($callback)) {
            return $this->assertHttpRequestSentTimes($callback);
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
        $callback = $callback ?: function () {
            return true;
        };

        $sentRequests = $this->getHttpRequests();

        return array_filter($sentRequests, function ($request) use ($callback) {
            return $callback($request);
        });
    }
}
