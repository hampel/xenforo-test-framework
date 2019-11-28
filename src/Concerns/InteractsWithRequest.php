<?php namespace Hampel\Testing\Concerns;

use Closure;
use XF\Http\Request;

trait InteractsWithRequest
{
	/**
	 * Mock the request - given there are no HTTP requests created from the console, this is useful if we need to
	 * simulate certain attributes on a request.
	 *
	 * @param Closure|null $mock - mock closure to set expectations
	 *
	 * @return mixed
	 */
	protected function mockRequest(Closure $mock = null)
	{
		return $this->mock('request', Request::class, $mock);
	}
}
