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
     * @param  string $abstract
     * @param  \Closure|null  $mock
     * @return object
     */
    protected static function mock($key, $abstract, Closure $mock = null)
    {
        return self::swap($key, Mockery::mock($abstract, $mock));
    }

    /**
     * Spy an instance of an object in the container.
     *
     * @param  mixed  $key
     * @param  string $abstract
     * @param  \Closure|null  $mock
     * @return object
     */
    protected static function spy($key, $abstract, Closure $mock = null)
    {
        return self::instance($key, Mockery::spy($abstract, $mock));
    }
}
