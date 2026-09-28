<?php

namespace Hampel\Testing\Concerns;

use Illuminate\Support\helpers;

trait UsesReflection
{
	/** @var array - class::property => [class, property, original value] */
	private $staticPropertiesToRestore = [];

	/**
	 * Returns all traits used by a class, its parent classes and trait of their traits.
	 *
	 * @see helpers::class_uses_recursive
	 *
	 * @param  object|string  $class
	 * @return array
	 */
	protected function classUsesRecursive($class)
	{
		if (is_object($class))
		{
			$class = get_class($class);
		}

		$results = [];

		foreach (array_reverse(class_parents($class)) + [$class => $class] AS $class)
		{
			$results += $this->traitUsesRecursive($class);
		}

		return array_unique($results);
	}

	/**
	 * Returns all traits used by a trait and its traits.
	 *
	 * @see helpers::trait_uses_recursive
	 *
	 * @param  string  $trait
	 * @return array
	 */
	protected function traitUsesRecursive($trait)
	{
		$traits = class_uses($trait);

		foreach ($traits AS $trait)
		{
			$traits += $this->traitUsesRecursive($trait);
		}

		return $traits;
	}

	protected function destroyProperty($class, $property)
	{
		$reflectionClass = new \ReflectionClass($class);
		$reflectionClass->setStaticPropertyValue($property, null);
	}

	/**
	 * Read a static property.
	 *
	 * @param string $class
	 * @param string $property
	 *
	 * @return mixed
	 */
	protected function getStaticProperty($class, $property)
	{
		$reflectionClass = new \ReflectionClass($class);

		return $reflectionClass->getStaticPropertyValue($property);
	}

	/**
	 * Set a static property, restoring what was there when the test finishes.
	 *
	 * A static outlives the application the framework rebuilds for each test, so a value set here
	 * would otherwise be read by every later test in the run - the leak \XF::$apiKey and
	 * \XF::$visitor each needed their own fix for.
	 *
	 * @param string $class
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return void
	 */
	protected function setStaticProperty($class, $property, $value)
	{
		$key = $class . '::' . $property;

		if (!array_key_exists($key, $this->staticPropertiesToRestore))
		{
			$this->staticPropertiesToRestore[$key] = [$class, $property, $this->getStaticProperty($class, $property)];
		}

		$this->writeStaticProperty($class, $property, $value);
	}

	/**
	 * Set a static property without recording it for restoration - for the framework's own use,
	 * where the write IS the teardown, or is undone by it.
	 *
	 * @param string $class
	 * @param string $property
	 * @param mixed $value
	 *
	 * @return void
	 */
	private function writeStaticProperty($class, $property, $value)
	{
		$reflectionClass = new \ReflectionClass($class);
		$reflectionClass->setStaticPropertyValue($property, $value);
	}

	/**
	 * @return void
	 */
	private function restoreStaticProperties()
	{
		foreach ($this->staticPropertiesToRestore AS [$class, $property, $value])
		{
			$this->writeStaticProperty($class, $property, $value);
		}

		$this->staticPropertiesToRestore = [];
	}
}
