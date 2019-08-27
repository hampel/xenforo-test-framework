<?php namespace Hampel\Testing\Concerns;

use Closure;
use Mockery;

trait InteractsWithContainer
{
    /**
     * Register an instance of an object in the container.
     *
     * @param  mixed  $key
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
     * @param  mixed  $key
     * @param  object  $instance
     * @return object
     */
    protected static function instance($key, $instance)
    {
    	if (is_array($key))
	    {
	    	// [$subcontainer, $key]
		    $key[0][$key[1]] = $instance;
	    }
    	else
	    {
		    self::$app->container()->set($key, $instance);
	    }

        return $instance;
    }

    /**
     * Mock an instance of an object in the container.
     *
     * @param  mixed  $key
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
     * @param  mixed  $key
     * @param  \Closure|null  $mock
     * @return object
     */
    protected static function spy($key, Closure $mock = null)
    {
        return self::instance($key, Mockery::spy(...array_filter(func_get_args())));
    }
}
