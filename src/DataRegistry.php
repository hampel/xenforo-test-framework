<?php namespace Hampel\Testing;

use XF\DataRegistry as BaseDataRegistry;

class DataRegistry extends BaseDataRegistry
{
	// if we're in fakeMode, then don't actually update the database
	protected $fakeMode = false;

	public function setFakeMode($enabled = true)
	{
		$this->fakeMode = $enabled;
	}

	public function set($key, $value)
	{
		// don't update database if we're in fake mode
		if (!$this->fakeMode)
		{
			$this->db->query("
				INSERT INTO xf_data_registry
					(data_key, data_value)
				VALUES
					(?, ?)
				ON DUPLICATE KEY UPDATE
					data_value = VALUES(data_value)
			", [$key, serialize($value)]);
		}

		$this->setInCache($key, $value);

		$this->localData[$key] = $value;
	}

	public function delete($keys)
	{
		if (!is_array($keys))
		{
			$keys = [$keys];
		}
		else if (!$keys)
		{

			return;
		}

		// don't update database if we're in fake mode
		if (!$this->fakeMode)
		{
			$this->db->delete('xf_data_registry', 'data_key IN (' . $this->db->quote($keys) . ')');
		}

		if ($this->cache)
		{
			foreach ($keys AS $key)
			{
				$this->cache->delete($this->getCacheId($key));
			}
		}

		foreach ($keys AS $key)
		{
			$this->localData[$key] = null;
		}
	}
}
