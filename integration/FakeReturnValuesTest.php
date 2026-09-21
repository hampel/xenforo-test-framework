<?php

namespace Hampel\Testing\Integration;

use GuzzleHttp\Client;
use Hampel\Testing\DataRegistry;
use Hampel\Testing\Error;
use Hampel\Testing\Job\Manager as JobManager;
use Hampel\Testing\Logger;
use Hampel\Testing\Mail\TestTransport;
use Hampel\Testing\SimpleCache;
use League\Flysystem\Memory\MemoryAdapter;

/**
 * Each fake returns the object the container builds, not the closure handed to swap().
 */
class FakeReturnValuesTest extends TestCase
{
	public function test_fakes_return_the_instance_they_advertise()
	{
		$this->assertInstanceOf(DataRegistry::class, $this->fakesRegistry());
		$this->assertInstanceOf(TestTransport::class, $this->fakesMail());
		$this->assertInstanceOf(JobManager::class, $this->fakesJobs());
		$this->assertInstanceOf(Logger::class, $this->fakesLogger());
		$this->assertInstanceOf(Error::class, $this->fakesErrors());
		$this->assertInstanceOf(SimpleCache::class, $this->fakesSimpleCache());
		$this->assertInstanceOf(Client::class, $this->fakesHttp([]));
	}

	/** swapFs() returned the config array, while documenting "@return MemoryAdapter;" */
	public function test_swap_fs_returns_the_adapter()
	{
		$this->assertInstanceOf(MemoryAdapter::class, $this->swapFs('data'));

		$this->assertFsHasNot('data://probe.txt');
		$this->app()->fs()->write('data://probe.txt', 'contents');
		$this->assertFsHas('data://probe.txt');
	}

	public function test_the_returned_instance_is_the_one_in_the_container()
	{
		$this->assertSame($this->fakesJobs(), $this->app()->jobManager());
		$this->assertSame($this->fakesRegistry(), $this->app()->registry());
	}
}
