<?php namespace Hampel\Testing\XF;

class Extension extends \XF\Extension
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
