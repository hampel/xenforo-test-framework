<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Extension;
use XF\Container;
use XF\Db\Exception;

trait InteractsWithExtension
{
	/**
	 * Re-install the extension for this test.
	 *
	 * `App::setup()` installs one during boot as of 5.0.0, and that is the install which matters
	 * - it is the only one early enough to stop an excluded add-on's `app_setup` listener from
	 * running. This one covers the half-upgraded scaffold the 2.1.0 upgrade notes warn about: a
	 * `tests/TestCase.php` carrying `$addonsToLoad` beside a `tests/CreatesApplication.php` old
	 * enough not to pass it to `setupApp()`. There the application was never told which add-ons
	 * to keep, so filtering here is all the isolation that suite gets - late, partial, and
	 * better than none.
	 *
	 * @return void
	 */
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
					return Extension::forAddOns($this->addonsToLoad, $c['db']);
				}

				return new Extension($c['extension.listeners'], $c['extension.classExtensions']);
			}
			catch (Exception $e)
			{
				return new Extension();
			}
		});
	}
}
