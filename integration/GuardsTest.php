<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Db\AbstractAdapter;

/**
 * The guards that only fire when something is set up wrongly. Nothing else in the suite reaches
 * them, so without these they can be simplified into uselessness and every test still passes.
 */
class GuardsTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * UsesDatabaseTransactions needs a real connection to roll back. Its guard used to test the
	 * adapter type as well as the mock, which was redundant - the mock is the case that matters.
	 */
	public function test_the_transaction_trait_refuses_a_mocked_database()
	{
		$this->mockDatabase();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('cannot be combined with mockDatabase()');

		$this->setUpDatabaseTransactions();
	}

	/** and it is satisfied by a real one, so the guard is not simply always throwing */
	public function test_the_transaction_trait_accepts_a_real_database()
	{
		$this->assertInstanceOf(AbstractAdapter::class, $this->app()->db());

		// setUp() already ran it for this class; reaching here at all means it did not throw
		$this->assertTrue(true);
	}
}
