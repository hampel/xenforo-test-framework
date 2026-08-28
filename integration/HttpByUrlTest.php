<?php

namespace Hampel\Testing\Integration;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class HttpByUrlTest extends TestCase
{
	public function test_responses_are_chosen_by_url_not_by_order()
	{
		$this->fakesHttpByUrl([
			'*/beta' => new Response(201, [], 'beta body'),
			'*/alpha' => new Response(200, [], 'alpha body'),
		]);

		$client = $this->app()->http()->client();

		// requested in the opposite order to the map - a queue would hand back the wrong one
		$alpha = $client->get('https://example.com/alpha');
		$beta = $client->get('https://example.com/beta');

		$this->assertSame(200, $alpha->getStatusCode());
		$this->assertSame('alpha body', (string) $alpha->getBody());
		$this->assertSame(201, $beta->getStatusCode());
		$this->assertSame('beta body', (string) $beta->getBody());

		$this->assertHttpRequestSentTimes(2);
	}

	public function test_a_catch_all_pattern()
	{
		$this->fakesHttpByUrl(['*' => new Response(204)]);

		$this->assertSame(
			204,
			$this->app()->http()->client()->get('https://example.com/anything')->getStatusCode()
		);
	}

	public function test_a_callable_receives_the_request()
	{
		$this->fakesHttpByUrl([
			'*' => function ($request)
			{
				return new Response(200, [], strtoupper($request->getMethod()));
			},
		]);

		$response = $this->app()->http()->client()->post('https://example.com/thing');

		$this->assertSame('POST', (string) $response->getBody());
	}

	public function test_an_exception_is_thrown_rather_than_returned()
	{
		$this->fakesHttpByUrl([
			'*' => new ConnectException('boom', new Request('GET', 'https://example.com')),
		]);

		$this->expectException(ConnectException::class);

		$this->app()->http()->client()->get('https://example.com/thing');
	}

	public function test_an_unmatched_url_is_an_error_rather_than_a_silent_null()
	{
		$this->fakesHttpByUrl(['*/known' => new Response(200)]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No fake HTTP response matches');

		$this->app()->http()->client()->get('https://example.com/unknown');
	}
}
