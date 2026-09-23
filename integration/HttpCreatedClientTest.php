<?php

namespace Hampel\Testing\Integration;

use GuzzleHttp\Psr7\Response;

/**
 * A client built with $app->http()->createClient() is faked too.
 *
 * An add-on needing its own base_uri or headers cannot use the shared client, and every request
 * such a client makes went to the real service while a fake was installed.
 *
 * Every request here is faked. The controls that prove these tests fail without the interception
 * are run by hand, because the unfaked client reaches the network.
 */
class HttpCreatedClientTest extends TestCase
{
	private function createClient(array $options = [])
	{
		return $this->app()->http()->createClient($options + ['base_uri' => 'https://api.example.invalid']);
	}

	public function test_a_created_client_uses_the_fake()
	{
		$this->fakesHttp([new Response(200, [], 'faked')]);

		$response = $this->createClient()->get('/thing');

		$this->assertSame('faked', $response->getBody()->getContents());
	}

	public function test_the_clients_own_options_survive()
	{
		$this->fakesHttp([new Response(200, [], 'faked')]);

		$this->createClient(['headers' => ['X-Probe' => 'kept']])->get('/thing');

		$request = $this->getHttpHistory()[0]['request'];

		$this->assertSame('https://api.example.invalid/thing', (string) $request->getUri());
		$this->assertSame('kept', $request->getHeaderLine('X-Probe'));
	}

	public function test_a_created_client_is_recorded_for_the_request_assertions()
	{
		$this->fakesHttp([new Response(200, [], 'faked')]);

		$this->createClient()->get('/thing');

		$this->assertHttpRequestSent(function ($request)
		{
			return (string) $request->getUri() === 'https://api.example.invalid/thing';
		});
	}

	public function test_fakes_http_by_url_covers_a_created_client()
	{
		$this->fakesHttpByUrl(['*' => new Response(200, [], 'by url')]);

		$response = $this->createClient()->get('/thing');

		$this->assertSame('by url', $response->getBody()->getContents());
	}

	public function test_a_second_fake_replaces_the_first()
	{
		$this->fakesHttp([new Response(200, [], 'first')]);
		$this->fakesHttp([new Response(200, [], 'second')]);

		$response = $this->createClient()->get('/thing');

		$this->assertSame('second', $response->getBody()->getContents());
	}

	public function test_faking_events_does_not_restore_the_live_client()
	{
		// fakesEvents() stops add-on listeners running, and the interception is a listener - so
		// without an exemption for the framework's own, this request would leave the machine
		$this->fakesEvents();
		$this->fakesHttp([new Response(200, [], 'faked')]);

		$response = $this->createClient()->get('/thing');

		$this->assertSame('faked', $response->getBody()->getContents());
	}

	public function test_a_client_created_before_the_fake_is_not_retrospectively_faked()
	{
		// the listener rebuilds the client as it is created, so one built earlier keeps the real
		// handler - which is why a test should install its fake first
		$client = $this->createClient();

		$this->fakesHttp([new Response(200, [], 'faked')]);

		$this->assertNotSame($this->app()->http()->client(), $client);
	}
}
