<?php namespace Hampel\Testing\Concerns;

trait InteractsWithOptions
{
	protected $originalOptions = [];

	protected function setUpOptions()
	{
		$this->originalOptions = $this->app()->options();

        $this->beforeApplicationDestroyed(function () {
            $this->restoreOptions();
        });
	}

    /**
     * Set an array of options key=>value pairs
     *
     * @param  array  $options
     * @return array
     */
	protected function setOptions(array $newOptions)
	{
		$options = $this->app()->options();

		foreach ($newOptions as $key => $value)
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

	private function restoreOptions()
	{
		$app = $this->app();
		$app['options'] = $this->originalOptions;
	}
}
