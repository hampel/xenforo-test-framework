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
	protected function swap($key, $instance)
	{
    	if (is_array($key))
	    {
	    	// [$subcontainer, $key]
		    $key[0][$key[1]] = $instance;
	    }
    	else
	    {
		    $this->app()->container()->set($key, $instance);
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
    protected function mock($key, $abstract, Closure $mock = null)
    {
    	return $this->swap($key, Mockery::mock($abstract, $mock));
    }

    /**
     * Mock a factory builder in the container.
     *
     * @param  mixed  $key
     * @param  string $abstract
     * @param  \Closure|null  $mock
     * @return object
     */
    protected function mockFactory($key, $abstract, Closure $mock = null)
    {
    	return $this->app()->container()->factory($key, function() use ($abstract, $mock)
	    {
	    	return Mockery::mock($abstract, $mock);
	    });
    }

    /**
     * Spy an instance of an object in the container.
     *
     * @param  mixed  $key
     * @param  string $abstract
     * @param  \Closure|null  $mock
     * @return object
     */
    protected function spy($key, $abstract, Closure $mock = null)
    {
    	return $this->swap($key, Mockery::spy($abstract, $mock));
    }
}
