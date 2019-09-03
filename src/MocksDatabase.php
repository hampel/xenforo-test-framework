<?php namespace Hampel\Testing;

use XF\Container;
use \XF\Db\AbstractAdapter;

trait MocksDatabase
{
	protected function setUpDatabase()
	{
		$this->mock('db', AbstractAdapter::class, function ($mock) {

		});
	}
}
