<?php namespace Hampel\Testing;

use XF\Extension as BaseExtension;

class Extension extends BaseExtension
{
    protected static $globalExtensionMap = [];
    protected static $globalInverseExtensionMap = [];

    protected static $globalClassAliasMap = [];

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
