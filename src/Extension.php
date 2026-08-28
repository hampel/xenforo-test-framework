<?php

namespace Hampel\Testing;

use XF\Extension as BaseExtension;

class Extension extends BaseExtension
{
	protected static $globalExtensionMap = [];
	protected static $globalInverseExtensionMap = [];

	protected static $globalClassAliasMap = [];

	/**
	 * When faking, code events are recorded and listeners are not run - see
	 * Concerns\InteractsWithEvents.
	 *
	 * @var bool
	 */
	protected $fakeEvents = false;

	/** @var array */
	protected $firedEvents = [];

	/**
	 * @param bool $enabled
	 *
	 * @return void
	 */
	public function setFakeEventMode($enabled = true)
	{
		$this->fakeEvents = $enabled;
	}

	/**
	 * @return bool
	 */
	public function isFakingEvents()
	{
		return $this->fakeEvents;
	}

	/**
	 * @return array - each entry is ['event' => string, 'args' => array, 'hint' => string|null]
	 */
	public function getFiredEvents()
	{
		return $this->firedEvents;
	}

	/**
	 * Record the event, and in fake mode stop it reaching any listener.
	 *
	 * @param string $event
	 * @param array $args
	 * @param string|null $hint
	 *
	 * @return bool
	 */
	public function fire($event, array $args = [], $hint = null)
	{
		if (!$this->fakeEvents)
		{
			return parent::fire($event, $args, $hint);
		}

		$this->firedEvents[] = [
			'event' => $event,
			'args' => $args,
			'hint' => $hint,
		];

		// no listener ran, so nothing vetoed the event
		return true;
	}

	/**
	 * @param $class
	 *
	 * @param $fakeBaseClass
	 * @return mixed|string
	 * @throws \Exception
	 *
	 * Maintain a global extension map so we don't try to re-extend classes for every test that gets run
	 */
	public function extendClass($class, $fakeBaseClass = null)
	{
		if (array_key_exists($class, self::$globalExtensionMap))
		{
			return self::$globalExtensionMap[$class];
		}

		$extended = parent::extendClass($class, $fakeBaseClass);

		self::$globalExtensionMap[$class] = $extended;
		self::$globalInverseExtensionMap[$extended] = $class;

		return $extended;
	}

	public function getAliasedClass(string $alias): string
	{
		if (isset(self::$globalClassAliasMap[$alias]))
		{
			return self::$globalClassAliasMap[$alias];
		}

		$aliased = parent::getAliasedClass($alias);

		self::$globalClassAliasMap[$alias] = $aliased;

		return $aliased;
	}

	public function resolveExtendedClassToRoot($class)
	{
		$originalClass = $class;

		if (is_object($class))
		{
			$class = get_class($class);
		}
		else if (($class[0] ?? null) === '\\')
		{
			$class = substr($class, 1);
		}

		if (isset(self::$globalInverseExtensionMap[$class]))
		{
			$this->inverseExtensionMap[$class] = self::$globalInverseExtensionMap[$class];
		}

		return parent::resolveExtendedClassToRoot($originalClass);
	}
}
