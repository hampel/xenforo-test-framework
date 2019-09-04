<?php namespace Hampel\Testing\Concerns;

use Closure;
use \XF\Db\AbstractAdapter;

trait InteractsWithDatabase
{
	protected function mockDatabase(Closure $mock = null)
	{
		return $this->mock('db', AbstractAdapter::class, $mock);
	}
}
