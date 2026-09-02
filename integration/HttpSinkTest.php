<?php

namespace Hampel\Testing\Integration;

use GuzzleHttp\Psr7\Response;

/**
 * XF\Http\Reader::getUntrusted($url, $limits, $saveTo) is how an add-on downloads a file, and it
 * works by handing Guzzle a `sink`. A fake that ignores the sink delivers the response, records
 * the request and passes every assertHttpRequestSent() - while writing nothing to the file. Both
 * fakes must honour it, or the two are not interchangeable.
 */
class HttpSinkTest extends TestCase
{
	private $saveTo;

	protected function setUp(): void
	{
		parent::setUp();

		$this->saveTo = tempnam(sys_get_temp_dir(), 'xftf-sink-');
	}

	protected function tearDown(): void
	{
		if ($this->saveTo !== null && file_exists($this->saveTo))
		{
			unlink($this->saveTo);
		}

		parent::tearDown();
	}

	public function test_fakes_http_writes_the_body_to_the_sink()
	{
		$this->fakesHttp([new Response(200, [], 'PAYLOAD')], true);

		$this->app()->http()->reader()->getUntrusted(
			'https://example.com/archive.tar.gz',
			[],
			$this->saveTo
		);

		$this->assertSame('PAYLOAD', file_get_contents($this->saveTo));
	}

	public function test_fakes_http_by_url_writes_the_body_to_the_sink()
	{
		$this->fakesHttpByUrl(['*' => new Response(200, [], 'PAYLOAD')], true);

		$this->app()->http()->reader()->getUntrusted(
			'https://example.com/archive.tar.gz',
			[],
			$this->saveTo
		);

		$this->assertSame('PAYLOAD', file_get_contents($this->saveTo));
	}

	public function test_a_sink_given_as_a_path_is_written_too()
	{
		$this->fakesHttpByUrl(['*' => new Response(200, [], 'PAYLOAD')]);

		$this->app()->http()->client()->get('https://example.com/thing', [
			'sink' => $this->saveTo,
		]);

		$this->assertSame('PAYLOAD', file_get_contents($this->saveTo));
	}

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
}
