<?php

namespace Hampel\Testing\Concerns;

use Closure;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Memory\MemoryAdapter;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithFilesystem
{
	/**
	 * Swap a filesystem with a memory based filesystem for which changes will not be persisted, thus avoiding
	 * side effects
	 *
	 * @param $fs - the name of the filesystem to swap (eg `data`, `internal-data`, `code-cache`)
	 *
	 * @return MemoryAdapter
	 */
	protected function swapFs($fs)
	{
		$config = $this->app()->config();
		$config['fsAdapters'][$fs] = function ()
		{
			return new MemoryAdapter();
		};
		$this->swap('config', $config);
		$this->decacheFs();

		return $this->adapterFor($fs);
	}

	protected function assertFsHas($file)
	{
		PHPUnit::assertTrue(
			$this->app()->fs()->has($file),
			"The expected [{$file}] file does not exist."
		);
	}

	protected function assertFsHasNot($file)
	{
		PHPUnit::assertFalse(
			$this->app()->fs()->has($file),
			"The [{$file}] file exists."
		);
	}

	/**
	 * Allow us to mock the local filesystem to assert that certain operations have taken place without any changes
	 * being made
	 *
	 * @param $fs - the name of the filesystem to mock (eg `data`, `internal-data`, `code-cache`)
	 * @param \Closure|null $mock - the mock closure to set expectations on
	 *
	 * @return mixed
	 */
	protected function mockFs($fs, ?\Closure $mock = null)
	{
		$args = func_get_args();
		array_shift($args);

		$config = $this->app()->config();
		$config['fsAdapters'][$fs] = function () use ($args)
		{
			return \Mockery::mock(AdapterInterface::class, ...array_filter($args));
		};
		$this->swap('config', $config);
		$this->decacheFs();

		return $this->adapterFor($fs);
	}

	/**
	 * XF builds its filesystem mounts once, from the config as it stood at the time, and `fs` is its
	 * own cached container entry - so swapping the config does not reach mounts that already exist.
	 * Without this, a swapFs() after anything has touched the filesystem hands back the REAL local
	 * adapter, and the test goes on to read and write the real data directory: the side effects the
	 * helper exists to prevent, with nothing reported.
	 */
	/**
	 * The adapter behind one of XenForo's mounted filesystems.
	 *
	 * MountManager::getFilesystem() is typed to FilesystemInterface, which does not declare
	 * getAdapter() - only the concrete Filesystem does. Narrow it here, so a mount that is not one
	 * says so instead of fatalling on an undefined method.
	 *
	 * @param string $fs
	 *
	 * @return AdapterInterface
	 */
	private function adapterFor($fs)
	{
		$filesystem = $this->app()->fs()->getFilesystem($fs);

		if (!($filesystem instanceof Filesystem))
		{
			throw new \LogicException(
				"Cannot reach the adapter for '$fs': XenForo mounted a "
				. get_class($filesystem) . ', which does not expose getAdapter()'
			);
		}

		return $filesystem->getAdapter();
	}

	private function decacheFs()
	{
		$this->app()->container()->decache('fs');
	}
}
