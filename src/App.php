<?php

namespace Hampel\Testing;

use XF\App as BaseApp;
use XF\Container;
use XF\Db\Exception as DbException;

class App extends BaseApp
{
	/**
	 * The add-on ids this application was told to keep, exactly as setup() received them.
	 *
	 * Empty means no isolation was asked for. That is not the same as asking for it and having
	 * the request never arrive, and TestCase uses the difference to refuse a half-upgraded
	 * scaffold rather than running without the isolation it was configured for.
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
	 * The ordering is the whole point, and it was wrong until 5.0.0. `XF\App::setup()` fires
	 * `app_setup` as its last step, so an extension swapped in afterwards - which is what a
	 * test-time helper can do - arrives too late to stop a single listener. Every installed
	 * add-on's `app_setup` listener ran whatever $addonsToLoad said, registering container
	 * entries and occasionally throwing, out of a suite that had asked for none of them.
	 *
	 * Filtering `addon.composer` cannot substitute for this: add-on classes are reachable
	 * through XenForo's own autoload path, so a listener resolves whether or not its add-on's
	 * Composer autoloader was registered.
	 *
	 * With no ids given, this still installs our Extension rather than XenForo's, because
	 * `fakesEvents()` needs one - the listener set is XenForo's own in that case.
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
