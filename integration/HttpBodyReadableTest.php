<?php

namespace Hampel\Testing\Integration;

use GuzzleHttp\Psr7\Response;

/**
 * A faked response must reach the caller with its body unread.
 *
 * XF\Http\Reader always hands Guzzle a sink - php://temp when the caller gives none - and Guzzle's
 * MockHandler, behind fakesHttp(), reads the body to its end to write it there. Real Guzzle
 * returns the rewound sink as the body, so production never sees this; under the fake,
 * getContents() returned an empty string.
 *
 * Read the body with getContents(), which is how XF core reads a response, and never with a
 * (string) cast: the cast seeks to the start before reading, so it passes whether or not the
 * stream was left at the end. The older http tests here read bodies with the cast, which is why
 * none of them could see this.
 */
class HttpBodyReadableTest extends TestCase
{
	private $saveTo;

	protected function tearDown(): void
	{
		if ($this->saveTo !== null && file_exists($this->saveTo))
		{
			unlink($this->saveTo);
		}

		parent::tearDown();
	}

	public function test_fakes_http_leaves_the_body_readable_through_the_reader()
	{
		$this->fakesHttp([new Response(200, [], '{"token":"issued"}')]);

		$response = $this->app()->http()->reader()->request('post', 'https://example.com/token');

		$this->assertSame('{"token":"issued"}', $response->getBody()->getContents());
	}

	/** rewinding the body must not undo the download it was read for */
	public function test_the_sink_and_the_body_both_hold_the_payload()
	{
		$this->saveTo = tempnam(sys_get_temp_dir(), 'xftf-body-');

		$this->fakesHttp([new Response(200, [], 'PAYLOAD')], true);

		$response = $this->app()->http()->reader()->getUntrusted(
			'https://example.com/archive.tar.gz',
			[],
			$this->saveTo
		);

		$this->assertSame('PAYLOAD', file_get_contents($this->saveTo));
		$this->assertSame('PAYLOAD', $response->getBody()->getContents());
	}
}
