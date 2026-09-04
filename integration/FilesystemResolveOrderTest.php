<?php

namespace Hampel\Testing\Integration;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Memory\MemoryAdapter;
use XF\LocalFsAdapter;

/**
 * swapFs() and mockFs() rewrite the config, but XenForo builds its filesystem mounts once and caches
 * them under `fs`. Anything that touched the filesystem first therefore used to keep the real local
 * adapter - so the helper returned something that was not a fake, and the test read and wrote the
 * real data directory while reporting nothing.
 */
class FilesystemResolveOrderTest extends TestCase
{
	public function test_swap_fs_after_the_filesystem_has_been_resolved()
	{
		$this->app()->fs();

		$adapter = $this->swapFs('data');

		$this->assertInstanceOf(MemoryAdapter::class, $adapter);
	}

	public function test_mock_fs_after_the_filesystem_has_been_resolved()
	{
		$this->app()->fs();

		$adapter = $this->mockFs('data');

		$this->assertInstanceOf(AdapterInterface::class, $adapter);
		$this->assertNotInstanceOf(LocalFsAdapter::class, $adapter);
	}

	/** A second swap in one test is the same problem seen from the other end. */
	public function test_a_second_swap_replaces_the_first()
	{
		$first = $this->swapFs('data');
		$second = $this->swapFs('data');

		$this->assertInstanceOf(MemoryAdapter::class, $second);
		$this->assertNotSame($first, $second);
	}

	/**
	 * And the swap must actually be the filesystem the app uses, not merely an object of the right
	 * class - a write must not reach the real data directory.
	 */
	public function test_the_swapped_adapter_is_the_one_the_app_writes_through()
	{
		// Absolute, because config('externalDataPath') is the relative 'data' and an assertion
		// against that would resolve against the working directory and pass without meaning anything.
		$onDisk = $this->rootDir . '/data/probe-must-not-persist.txt';

		// If the fix is ever removed, this test writes to the real data directory - which is the
		// defect it exists to catch. Report that, but clean it up too, so a run that demonstrates the
		// bug does not leave a file behind in someone's forum.
		$this->beforeApplicationDestroyed(function () use ($onDisk)
		{
			if (file_exists($onDisk))
			{
				unlink($onDisk);
			}
		});

		$this->assertFileDoesNotExist($onDisk, 'the probe file is left over from an earlier run');

		$this->app()->fs();

		$adapter = $this->swapFs('data');

		$this->app()->fs()->write('data://probe-must-not-persist.txt', 'probe');

		$this->assertTrue($adapter->has('probe-must-not-persist.txt'));
		$this->assertFileDoesNotExist($onDisk);
	}
}
