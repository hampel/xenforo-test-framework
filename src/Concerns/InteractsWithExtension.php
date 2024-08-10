<?php namespace Hampel\Testing\Concerns;

use XF\Container;

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
            catch (\XF\Db\Exception $e)
            {
                $listeners = [];
                $classExtensions = [];
            }

            return new \XF\Extension($listeners, $classExtensions);
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
                $listener['callback_method']
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

        $cache = [];

        foreach ($extensions AS $extension)
        {
            $cache[$extension['from_class']][] = $extension['to_class'];
        }

        return $cache;
    }
}
