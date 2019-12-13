<?php namespace Hampel\Testing\Concerns;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

trait InteractsWithHttp
{
	protected function mockHttp(array $responseStack)
	{
		$handlerStack = HandlerStack::create(new MockHandler($responseStack));
		$http = $this->app()->http();

		return $this->swap([$http, 'client'], function ($c) use ($http, $handlerStack) {
			return $http->createClient(['handler' => $handlerStack]);
		});
	}
}
