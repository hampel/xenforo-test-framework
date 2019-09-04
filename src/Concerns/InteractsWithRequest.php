<?php namespace Hampel\Testing\Concerns;

use Closure;
use XF\Http\Request;

trait InteractsWithRequest
{
	protected function mockRequest(Closure $mock = null)
	{
		return $this->mock('request', Request::class, $mock);
	}
}
