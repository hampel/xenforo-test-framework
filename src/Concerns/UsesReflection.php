<?php

namespace Hampel\Testing\Concerns;

use Illuminate\Support\helpers;

trait UsesReflection
{
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
}
