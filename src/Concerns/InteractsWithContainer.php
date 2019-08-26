<?php namespace Hampel\Testing\Concerns;

use Closure;
use Mockery;

trait InteractsWithContainer
{
    /**
     * Register an instance of an object in the container.
     *
     * @param  string  $key
     * @param  object  $instance
     * @return object
     */
    protected static function swap($key, $instance)
    {
        return self::instance($key, $instance);
    }

    /**
     * Register an instance of an object in the container.
     *
     * @param  string  $key
     * @param  object  $instance
     * @return object
     */
    protected static function instance($key, $instance)
    {
        self::$app->container()->set($key, $instance);

        return $instance;
    }

    /**
     * Mock an instance of an object in the container.
     *
     * @param  string  $key
     * @param  \Closure|null  $mock
     * @return object
     */
    protected static function mock($key, Closure $mock = null)
    {
        return self::instance($key, Mockery::mock(...array_filter(func_get_args())));
    }

    /**
     * Spy an instance of an object in the container.
     *
     * @param  string  $abstract
     * @param  \Closure|null  $mock
     * @return object
     */
    protected static function spy($abstract, Closure $mock = null)
    {
        return self::instance($abstract, Mockery::spy(...array_filter(func_get_args())));
    }
}
