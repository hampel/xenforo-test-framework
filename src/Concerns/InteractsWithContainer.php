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
    protected function swap($key, $instance)
    {
        return $this->instance($key, $instance);
    }

    /**
     * Register an instance of an object in the container.
     *
     * @param  string  $key
     * @param  object  $instance
     * @return object
     */
    protected function instance($key, $instance)
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
    protected function mock($key, Closure $mock = null)
    {
        return $this->instance($key, Mockery::mock(...array_filter(func_get_args())));
    }

    /**
     * Spy an instance of an object in the container.
     *
     * @param  string  $abstract
     * @param  \Closure|null  $mock
     * @return object
     */
    protected function spy($abstract, Closure $mock = null)
    {
        return $this->instance($abstract, Mockery::spy(...array_filter(func_get_args())));
    }
}
