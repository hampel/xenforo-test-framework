<?php namespace Hampel\Testing\Concerns;

use Closure;
use Mockery;
use XF\Error;
use Mockery\MockInterface;

trait InteractsWithErrors
{
	protected function mockErrors(Closure $mock = null)
	{
		$errors = $this->app()->error();
		if ($this->app()->error() instanceof MockInterface)
		{
			return $errors;
		}

        return $this->mock('error', Error::class, $mock);
	}

	protected function expectLogError($message)
	{
		$this->mockErrors()->shouldReceive('logError')->once()->with($message, Mockery::any());
	}

	protected function expectLogException($exception)
	{
		$this->mockErrors()->shouldReceive('logException')->once()->with($exception, Mockery::any());
	}
}
