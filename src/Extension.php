<?php namespace Hampel\Testing;

use XF\Extension as BaseExtension;

class Extension extends BaseExtension
{
	protected static $globalExtensionMap = [];

	public function extendClass($class, $fakeBaseClass = null)
	{
		if (array_key_exists($class, self::$globalExtensionMap))
		{
			return self::$globalExtensionMap[$class];
		}

		$extended = parent::extendClass($class, $fakeBaseClass);

		self::$globalExtensionMap[$class] = $extended;

		return $extended;
	}
}
