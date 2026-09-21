<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Extension;
use XF\Container;
use XF\Db\Exception;

trait InteractsWithExtension
{
	protected function setUpExtension()
	{
		$this->swap('extension', function (Container $c)
		{
			$config = $c['config'];
			if (!$config['enableListeners'])
			{
				// disable
				return new \XF\Extension();
			}

			try
			{
				if (!empty($this->addonsToLoad))
				{
					// set these directly based on database queries - bypass the container since that only uses cached data
					$listeners = $this->getListenerData($this->addonsToLoad);
					$classExtensions = $this->getExtensionData($this->addonsToLoad);
				}
				else
				{
					$listeners = $c['extension.listeners'];
					$classExtensions = $c['extension.classExtensions'];
				}
			}
			catch (Exception $e)
			{
				$listeners = [];
				$classExtensions = [];
			}

			return new Extension($listeners, $classExtensions);
		});
	}

	private function getListenerData(array $addons)
	{
		$listeners = $this->app()->db()->fetchAll("
            SELECT * FROM xf_code_event_listener AS listener
            LEFT JOIN xf_addon AS addon ON (listener.addon_id = addon.addon_id)
            WHERE listener.active = 1
            AND addon.active = 1
            AND addon.is_processing = 0
            AND listener.addon_id IN (" . $this->app()->db()->quote($addons) . ")
            ORDER BY listener.event_id, listener.execute_order, addon.addon_id
        ");

		$cache = [];

		foreach ($listeners AS $listener)
		{
			$hint = $listener['hint'] !== '' ? $listener['hint'] : '_';
			$cache[$listener['event_id']][$hint][] = [
				$listener['callback_class'],
				$listener['callback_method'],
			];
		}

		return $cache;
	}

	private function getExtensionData(array $addons)
	{
		// don't use finder - use db queries directly because finder needs to be extended and we haven't yet created
		// the extension class!
		$extensions = $this->app()->db()->fetchAll("
            SELECT * FROM xf_class_extension AS extension
            LEFT JOIN xf_addon AS addon ON (extension.addon_id = addon.addon_id)
            WHERE extension.active = 1
            AND addon.active = 1
            AND addon.is_processing = 0
            AND extension.addon_id IN (" . $this->app()->db()->quote($addons) . ")
            ORDER BY extension.execute_order, extension.to_class
        ");

		return $this->mapClassExtensions($extensions);
	}

	/**
	 * Key each extension by the class XenForo will actually ask to extend.
	 *
	 * This is XF 2.3's own ClassExtensionRepository::buildExtensionCacheData(). 2.3 renamed
	 * services, finders, repositories and controllers with a suffix and aliases the old names
	 * forward, so an add-on that also supports 2.2 registers its extension on the old spelling -
	 * XF\Service\User\Login - while 2.3 resolves the class to LoginService and asks for that.
	 * Keyed on the raw spelling, the extension sat where nothing looked, and the isolated
	 * application silently ran XenForo's class instead. The dedupe is core's too: once both
	 * spellings share a bucket, an add-on naming both would otherwise get two proxies.
	 *
	 * @param array $extensions - rows with from_class and to_class
	 *
	 * @return array
	 */
	private function mapClassExtensions(array $extensions)
	{
		$cache = [];

		foreach ($extensions AS $extension)
		{
			$cache[\XF::getClassForAlias($extension['from_class'])][] = $extension['to_class'];
		}

		foreach ($cache AS $fromClass => $toClasses)
		{
			$cache[$fromClass] = array_values(array_unique($toClasses));
		}

		return $cache;
	}
}
