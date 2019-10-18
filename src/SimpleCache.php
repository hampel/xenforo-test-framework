<?php namespace Hampel\Testing;

use XF\SimpleCache as BaseSimpleCache;

class SimpleCache extends BaseSimpleCache
{
	protected function save()
	{
		// do nothing
	}

	public function setRegistryData(array $data = [])
	{
		if (empty($data))
		{
			$this->data = \XF::app()->get('simpleCache.data');
		}
		else
		{
			$this->data = $data;
		}
	}
}
