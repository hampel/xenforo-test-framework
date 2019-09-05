<?php namespace Hampel\Testing\Concerns;

trait InteractsWithExtension
{
	protected function setUpExtension()
	{
 		$this->swap('extension', function (Container $c) {
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

			return new \Hampel\Testing\XF\Extension($listeners, $classExtensions);
		});
	}
}
