<?php namespace Hampel\Testing\Concerns;

trait InteractsWithOptions
{
    /**
     * Set an array of options key=>value pairs
     *
     * @param  array  $options
     * @return array
     */
	protected function setOptions(array $options)
	{
		$options = $this->app()->options();

		foreach ($options as $key => $value)
		{
			$options[$key] = $value;
		}

		return $options;
	}

	protected function setOption($key, $value)
	{
		$options = $this->app()->options();
		$options[$key] = $value;
	}

	protected function resetOptions()
	{
    	$this->app()->container('em')->getRepository('XF:Option')->rebuildOptionCache();
	}
}
