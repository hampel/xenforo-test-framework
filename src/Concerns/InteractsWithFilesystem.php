<?php namespace Hampel\Testing\Concerns;

use League\Flysystem\Memory\MemoryAdapter;
use XF\Container;

trait InteractsWithFilesystem
{
	protected function swapFs($fs)
	{
		$adapters = $this->app()->config('fsAdapters');

		$adapters[$fs] = function ()
		{
			return new MemoryAdapter();
		};
	}
}
