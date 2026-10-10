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

	/**
	 * The case the test above cannot show, because it builds its client after both fakes.
	 *
	 * An add-on's sub-container builds its client once and keeps it, so the client making the
	 * requests was built under the first fake. Each fake used to build its own handler stack, which
	 * that client could not be holding - so the second fake installed a queue nothing read, and the
	 * first one's drained queue answered 'Mock queue is empty' from inside the code under test.
	 */
	public function test_a_second_fake_reaches_a_client_built_before_it()
	{
		$this->fakesHttp([new Response(200, [], 'first')]);
		$held = $this->createClient();

		$this->assertSame('first', $held->get('/thing')->getBody()->getContents());

		$this->fakesHttp([new Response(200, [], 'second')]);

		$this->assertSame('second', $held->get('/thing')->getBody()->getContents());
	}

	/** The same thing for the shared client, where a test kept it rather than re-fetching it. */
	public function test_a_second_fake_reaches_the_shared_client_held_in_a_variable()
	{
		$this->fakesHttp([new Response(200, [], 'first')]);
		$shared = $this->app()->http()->client();

		$this->assertSame(
			'first',
			$shared->get('https://api.example.invalid/thing')->getBody()->getContents()
		);

		$this->fakesHttp([new Response(200, [], 'second')]);

		$this->assertSame(
			'second',
			$shared->get('https://api.example.invalid/thing')->getBody()->getContents()
		);
	}

	/** And re-fetching it from the container, which worked before and must keep working. */
	public function test_a_second_fake_reaches_the_shared_client_fetched_again()
	{
		$this->fakesHttp([new Response(200, [], 'first')]);
		$this->app()->http()->client()->get('https://api.example.invalid/thing');

		$this->fakesHttp([new Response(200, [], 'second')]);

		$response = $this->app()->http()->client()->get('https://api.example.invalid/thing');

		$this->assertSame('second', $response->getBody()->getContents());
	}

	public function test_changing_fake_kind_reaches_a_client_built_before_it()
	{
		$this->fakesHttp([new Response(200, [], 'from the queue')]);
		$held = $this->createClient();

		$this->assertSame('from the queue', $held->get('/thing')->getBody()->getContents());

		// only the base handler is replaced, so the stack the client holds is still the one in use
		$this->fakesHttpByUrl(['*' => new Response(200, [], 'by url')]);

		$this->assertSame('by url', $held->get('/thing')->getBody()->getContents());
	}

	public function test_a_second_fake_by_url_replaces_the_map_for_a_client_built_before_it()
	{
		$this->fakesHttpByUrl(['*thing*' => new Response(200, [], 'first map')]);
		$held = $this->createClient();

		$this->assertSame('first map', $held->get('/thing')->getBody()->getContents());

		$this->fakesHttpByUrl(['*thing*' => new Response(200, [], 'second map')]);

		$this->assertSame('second map', $held->get('/thing')->getBody()->getContents());
	}

	/** The history is per test rather than per fake, which reusing the stack has to preserve. */
	public function test_the_history_spans_every_fake_in_the_test()
	{
		$this->fakesHttp([new Response(200, [], 'first')]);
		$held = $this->createClient();
		$held->get('/one');

		$this->fakesHttp([new Response(200, [], 'second')]);
		$held->get('/two');

		$this->assertHttpRequestSentTimes(2);
		$this->assertSame(
			['/one', '/two'],
			array_map(function ($request)
			{
				return $request->getUri()->getPath();
			}, $this->getHttpRequests())
		);
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
