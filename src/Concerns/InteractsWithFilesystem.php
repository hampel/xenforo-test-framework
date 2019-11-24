<?php namespace Hampel\Testing\Concerns;

use Closure;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Memory\MemoryAdapter;
use Mockery;

trait InteractsWithFilesystem
{
	protected function swapFs($fs)
	{
		$config = $this->app()->config();
		$config['fsAdapters'][$fs] = function ()
		{
			return new MemoryAdapter();
		};
		$this->swap('config', $config);
	}

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
