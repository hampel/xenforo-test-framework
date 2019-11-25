<?php namespace Hampel\Testing;

use XF\App as BaseApp;
use XF\Container;

class App extends BaseApp
{
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
		parent::setup($options);
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