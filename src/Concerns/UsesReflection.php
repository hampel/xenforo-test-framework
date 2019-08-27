<?php namespace Hampel\Testing\Concerns;

trait UsesReflection
{
	/**
	 * Returns all traits used by a class, its parent classes and trait of their traits.
	 *
	 * @see \Illuminate\Support\helpers::class_uses_recursive
	 *
	 * @param  object|string  $class
	 * @return array
	 */
    protected function classUsesRecursive($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class)) + [$class => $class] as $class) {
            $results += $this->traitUsesRecursive($class);
        }

        return array_unique($results);
    }

    /**
     * Returns all traits used by a trait and its traits.
     *
     * @see \Illuminate\Support\helpers::trait_uses_recursive
     *
     * @param  string  $trait
     * @return array
     */
    protected function traitUsesRecursive($trait)
    {
        $traits = class_uses($trait);

        foreach ($traits as $trait) {
            $traits += $this->traitUsesRecursive($trait);
        }

        return $traits;
    }

    protected function destroyProperty($class, $property)
    {
		$reflectionProperty = new \ReflectionProperty($class, $property);
		$reflectionProperty->setAccessible(true);
		$reflectionProperty->setValue(null);
    }
}
