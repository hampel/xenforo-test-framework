<?php

namespace Hampel\Testing\Integration;

use GuzzleHttp\Psr7\Response;

/**
 * XF builds `reader` and `metadataFetcher` once and they hold the http clients by value, so a fake
 * installed after either has resolved - which includes any second fake in one test - used to be
 * swapped into a client nothing goes on to use.
 */
class HttpFakeOrderTest extends TestCase
{
	public function test_a_second_fake_in_one_test_replaces_the_first()
	{
		// resolving the reader caches it holding the first fake's client
		$this->fakesHttp([new Response(200, [], 'first')]);
		$first = $this->app()->http()->reader()->get('https://example.com/one');
		$this->assertSame('first', (string) $first->getBody());

		// without decaching the reader, this fake is installed into a client nothing uses and
		// the second request drains the first queue: "Mock queue is empty"
		$this->fakesHttp([new Response(200, [], 'second')]);
		$second = $this->app()->http()->reader()->get('https://example.com/two');
		$this->assertSame('second', (string) $second->getBody());
	}

	public function test_a_fake_installed_after_the_reader_resolved()
	{
		$this->app()->http()->reader();

		$this->fakesHttp([new Response(200, [], 'faked')]);

		$response = $this->app()->http()->reader()->get('https://example.com/late');
		$this->assertSame('faked', (string) $response->getBody());
	}
}
