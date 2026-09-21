<?php

namespace Hampel\Testing;

use XF\App as BaseApp;
use XF\Container;
use XF\Db\Exception as DbException;

class App extends BaseApp
{
	/**
	 * The add-on ids this application was told to keep, exactly as setup() received them. Empty
	 * means no isolation was asked for. TestCase compares this with $addonsToLoad.
	 *
	 * @var string[]
	 */
	protected $isolatedAddOnIds = [];

	/**
	 * @return string[]
	 */
	public function isolatedAddOnIds()
	{
		return $this->isolatedAddOnIds;
	}

	public function initializeExtra()
	{
		$container = $this->container;

		$container['app.classType'] = 'Cli';
		$container['app.defaultType'] = 'public';
		$container['job.manual.allow'] = true;

		$container['session'] = function (Container $c)
		{
			return $c['session.public'];
		};
	}

	public function setup(array $options = [])
	{
		$addOnIds = !empty($options['xf-addons']) ? $options['xf-addons'] : [];

		$this->isolatedAddOnIds = $addOnIds;

		// isolate addons if required
		if ($addOnIds)
		{
			$addons = [];

			$addOnsComposer = $this->registry()->get('addOnsComposer');
			foreach ($addOnsComposer AS $id => $addon)
			{
				if (in_array($id, $addOnIds))
				{
					$addons[$id] = $addon;
				}
			}

			$this->container['addon.composer'] = $addons;
		}

		$this->installExtension($addOnIds);

		parent::setup($options);
	}

	/**
	 * Install the extension this package uses, before parent::setup() fires `app_setup`.
	 *
	 * It must be installed here: `XF\App::setup()` fires `app_setup` as its last step, so an
	 * extension installed later cannot stop excluded add-ons' listeners running. Filtering
	 * `addon.composer` does not prevent it either, since add-on classes also load through
	 * XenForo's own autoloader.
	 *
	 * With no ids given it still installs this package's Extension, carrying XenForo's full
	 * listener set, because `fakesEvents()` requires it.
	 *
	 * @param string[] $addOnIds - the add-ons to keep, or an empty array to keep all of them
	 *
	 * @return void
	 */
	protected function installExtension(array $addOnIds)
	{
		$this->container['extension'] = function (Container $c) use ($addOnIds)
		{
			$config = $c['config'];
			if (!$config['enableListeners'])
			{
				// listeners are off, so there is nothing for our subclass to record - matches
				// what XenForo's own closure does here
				return new \XF\Extension();
			}

			try
			{
				if ($addOnIds)
				{
					return Extension::forAddOns($addOnIds, $c['db']);
				}

				return new Extension($c['extension.listeners'], $c['extension.classExtensions']);
			}
			catch (DbException $e)
			{
				return new Extension();
			}
		};
	}

	public function preLoadData(array $typeSpecific = [])
	{
		parent::preLoadData($typeSpecific);
	}

	public function start($allowShortCircuit = false)
	{
		parent::start($allowShortCircuit);
	}

	public function run()
	{
		throw new \LogicException("This app is not runnable. Use PHPUnit.");
	}
}
