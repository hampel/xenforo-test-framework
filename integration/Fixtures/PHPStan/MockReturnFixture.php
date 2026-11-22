<?php

namespace Hampel\Testing\Integration\Fixtures\PHPStan;

use Hampel\Testing\Integration\TestCase;
use Mockery\MockInterface;
use XF\Db\AbstractAdapter;
use XF\Http\Request;
use XF\Job\Manager;
use XF\SimpleCache;

/**
 * Analysed by PhpStanMockReturnTest, never run: each marked line is one use of what a mock helper
 * returns, and the marker says whether PHPStan should report it.
 */
class MockReturnFixture extends TestCase
{
	/** @var MockInterface */
	private $kept;

	public function shapes(): MockInterface
	{
		$this->kept = $this->mock('simpleCache', SimpleCache::class); // expect: clean
		$this->kept = $this->spy('simpleCache', SimpleCache::class); // expect: clean
		$this->kept = $this->mockDatabase(); // expect: clean
		$this->kept = $this->mockRequest(); // expect: clean
		$this->kept = $this->mockFs('data'); // expect: clean

		$cache = $this->mock('simpleCache', SimpleCache::class);
		$cache->shouldReceive('getValue')->andReturn(1); // expect: clean
		$cache->getValue('XF', 'key'); // expect: clean
		$this->takesCache($cache); // expect: clean
		$cache->noSuchMethod(); // expect: reported

		$this->takesAdapter($this->mockDatabase()); // expect: clean
		$this->takesRequest($this->mockRequest()); // expect: clean

		$service = $this->mockService('XF:User\Registration'); // expect: reported
		$factory = $this->mockFactory('job.manager', Manager::class); // expect: reported

		return $this->kept;
	}

	private function takesCache(SimpleCache $cache): void
	{
	}

	private function takesAdapter(AbstractAdapter $db): void
	{
	}

	private function takesRequest(Request $request): void
	{
	}
}
