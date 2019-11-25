<?php namespace Hampel\Testing\Concerns;

trait InteractsWithExtension
{
	protected function setUpExtension()
	{
 		$this->swap('extension', function (\XF\Container $c) {
			$config = $c['config'];
			if (!$config['enableListeners'])
			{
				// disable
				return new \XF\Extension();
			}

			try
			{
				$listeners = $c['extension.listeners'];
				$classExtensions = $c['extension.classExtensions'];
			}
			catch (\XF\Db\Exception $e)
			{
				$listeners = [];
				$classExtensions = [];
			}

			return new \Hampel\Testing\Extension($listeners, $classExtensions);
		});
	}

	protected function isolateAddon($addon)
	{
		if (empty($addon)) return;

		$classExtensions = $this->getExtensionCacheData($addon);
		$this->app()->extension()->setClassExtensions($classExtensions);

		$listeners = $this->getListenerCacheData($addon);
		$this->app()->extension()->setListeners($listeners);
	}

	private function getExtensionCacheData($addon)
	{
		$extensions = $this->app()->finder('XF:ClassExtension')
			->whereAddOnActive(['disableProcessing' => true])
			->where('active', 1)
			->where('addon_id', '=', $addon)
			->order(['execute_order'])
			->fetch();

		$cache = [];

		foreach ($extensions AS $extension)
		{
			$cache[$extension->from_class][] = $extension->to_class;
		}

		return $cache;
	}

	private function getListenerCacheData($addon)
	{
		$listeners = $this->app()->finder('XF:CodeEventListener')
			->whereAddOnActive(['disableProcessing' => true])
			->where('active', 1)
			->where('addon_id', '=', $addon)
			->order(['event_id', 'execute_order'])
			->fetch();

		$cache = [];

		foreach ($listeners AS $listener)
		{
			$hint = $listener['hint'] ? $listener['hint'] : '_';
			$cache[$listener['event_id']][$hint][] = [$listener['callback_class'], $listener['callback_method']];
		}

		return $cache;
	}
}
