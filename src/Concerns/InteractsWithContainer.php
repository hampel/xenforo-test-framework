<?php

namespace Hampel\Testing\Concerns;

use Closure;
use XF\SubContainer\AbstractSubContainer;

trait InteractsWithContainer
{
	/**
	 * Register an instance of an object in the container.
	 *
	 * @param  mixed  $key - the container key to be swapped
	 * @param  mixed  $instance - the value to swap in: an object, a closure the container will
	 *                            resolve, or a plain value such as the config array
	 *
	 * @return mixed - the value that was swapped in
	 */
	protected function swap($key, $instance)
	{
		if (is_array($key))
		{
			if (is_subclass_of($key[0], AbstractSubContainer::class))
			{
				// [$subcontainer (object), $key (string)]
				$subContainer = $key[0];
			}
			else
			{
				// [$subcontainer (string), $key (string)]
				$subContainer = $this->app()->container($key[0]);
			}

			$subContainer->container()->set($key[1], $instance);
		}
		else
		{
			$this->app()->container()->set($key, $instance);
		}

		return $instance;
	}

	/**
	 * Set a value in the application config - the values from config.php, which are NOT options.
	 *
	 * The two are easy to confuse and fail differently: setOption() writes somewhere XenForo reads
	 * on demand, while a config value is usually read ONCE, where the container builds whatever
	 * consumes it, and the consumer then keeps the value rather than the config. So call this before
	 * the code under test resolves anything - and if the consuming container key may already have
	 * been built, decache it, the way fakesMail() decaches `mailer`.
	 *
	 * @param string $key - the config key to set
	 * @param mixed $value - the value to set it to
	 *
	 * @return array - the config array as swapped in
	 */
	protected function setConfig($key, $value)
	{
		$config = $this->app()->config();
		$config[$key] = $value;

		$this->swap('config', $config);

		return $config;
	}

	/**
	 * Mock an instance of an object in the container.
	 *
	 * @param  mixed  $key - the container key to be swapped with a mock
	 * @param  string $abstract - the base class or interface to use for the mock
	 * @param  \Closure|null  $mock - (optional) the mock closure to define expectations on
	 * @return object - the mock object
	 */
	protected function mock($key, $abstract, ?\Closure $mock = null)
	{
		$args = func_get_args();
		array_shift($args);

		return $this->swap($key, \Mockery::mock(...array_filter($args)));
	}

	/**
	 * Mock a factory builder in the container.
	 *
	 * @param  mixed  $key - the container key to be swapped with a mock
	 * @param  string $abstract - the base class or interface to use for the mock
	 * @param  \Closure|null  $mock - (optional) the mock closure to define expectations on
	 * @return object
	 */
	protected function mockFactory($key, $abstract, ?\Closure $mock = null)
	{
		return $this->app()->container()->factory($key, function () use ($abstract, $mock)
		{
			$args = [$abstract, $mock];

			return \Mockery::mock(...array_filter($args));
		});
	}

	/**
	 * Mock a Service class
	 *
	 * @param string $shortName - shortname for the class in Addon_Id:Class format
	 * @param \Closure|null $mock - (optional) the mock closure to define expectations on
	 *
	 * @return object
	 */
	protected function mockService($shortName, ?\Closure $mock = null)
	{
		$class = \XF::stringToClass($shortName, '\%s\Service\%s');

		// Resolve the class the way XF's own service factory does, then insist it exists. Mockery
		// will happily build an untyped double of a class name that resolves to nothing, so a
		// misspelled short name would otherwise produce a test that passes while asserting against
		// nothing at all. Resolving first also means the mock is typed as the class XF would really
		// have built, so an add-on's own extension of the service is honoured - the same thing
		// mockRepository() does.
		$serviceClass = $this->app()->extendClass($class);
		if (!$serviceClass || !class_exists($serviceClass))
		{
			throw new \LogicException("Could not find service '$class' for '$shortName'");
		}

		return $this->mockFactory('service', $serviceClass, $mock);
	}

	/**
	 * Spy an instance of an object in the container.
	 *
	 * @param  mixed  $key
	 * @param  string $abstract
	 * @param  \Closure|null  $mock
	 * @return object
	 */
	protected function spy($key, $abstract, ?\Closure $mock = null)
	{
		$args = func_get_args();
		array_shift($args);

		return $this->swap($key, \Mockery::spy(...array_filter($args)));
	}
}
