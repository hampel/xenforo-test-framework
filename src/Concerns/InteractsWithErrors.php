<?php namespace Hampel\Testing\Concerns;

use Mockery;
use XF\Error;

trait InteractsWithErrors
{
	protected function setUpErrors()
	{
        $this->mock('error', Error::class, function () {

        });
	}

	protected function expectLogError($message)
	{
		$this->app()->error()->shouldReceive('logError')->once()->with($message, Mockery::any());
	}
}
