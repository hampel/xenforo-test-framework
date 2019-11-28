<?php namespace Hampel\Testing\Concerns;

use Closure;
use \XF\Db\AbstractAdapter;

trait InteractsWithDatabase
{
	/**
	 * Mock the database class
	 *
	 * @param Closure|null $mock
	 *
	 * @return mixed
	 */
	protected function mockDatabase(Closure $mock = null)
	{
		$db = $this->mock('db', AbstractAdapter::class, $mock);
		// need to set up the entity manager again, so we get the mocked database
		$this->setUpEntityManager();
		return $db;
	}
}
