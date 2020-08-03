<?php namespace Hampel\Testing\Concerns;

use Closure;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Memory\MemoryAdapter;
use PHPUnit\Framework\Assert as PHPUnit;
use Mockery;

trait InteractsWithFilesystem
{
	/**
	 * Swap a filesystem with a memory based filesystem for which changes will not be persisted, thus avoiding
	 * side effects
	 *
	 * @param $fs - the name of the filesystem to swap (eg `data`, `internal-data`, `code-cache`)
	 */
	protected function swapFs($fs)
	{
		$config = $this->app()->config();
		$config['fsAdapters'][$fs] = function ()
		{
			return new MemoryAdapter();
		};
		$this->swap('config', $config);
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
	 * @param Closure|null $mock - the mock closure to set expectations on
	 *
	 * @return mixed
	 */
	protected function mockFs($fs, Closure $mock = null)
	{
		$args = func_get_args();
		array_shift($args);

		$config = $this->app()->config();
		$config['fsAdapters'][$fs] = function () use ($args)
		{
			return Mockery::mock(AdapterInterface::class, ...array_filter($args));
		};
		$this->swap('config', $config);
		return $this->app()->fs()->getFilesystem($fs)->getAdapter();
	}
}
