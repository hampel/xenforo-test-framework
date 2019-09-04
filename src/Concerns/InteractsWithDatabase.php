<?php namespace Hampel\Testing\Concerns;

use \XF\Db\AbstractAdapter;

trait InteractsWithDatabase
{
	protected function mockDatabase(Closure $mock = null)
	{
		$this->mock('db', AbstractAdapter::class, $mock);
	}
}