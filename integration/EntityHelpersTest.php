<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Entity\User;

class EntityHelpersTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_make_entity_does_not_save()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'MadeNotSaved']);

		$this->assertInstanceOf(User::class, $user);
		$this->assertSame('MadeNotSaved', $user->username);
		$this->assertTrue($user->isInsert());

		$this->assertDatabaseMissing('xf_user', ['username' => 'MadeNotSaved']);
	}

	public function test_create_entity_saves()
	{
		$user = $this->createEntity('XF:User', [
			'username' => 'CreatedAndSaved',
			'email' => 'created@example.com',
		]);

		$this->assertGreaterThan(0, $user->user_id);
		$this->assertDatabaseHas('xf_user', ['username' => 'CreatedAndSaved']);
	}

	/** and the transaction wrapper takes it away again */
	public function test_the_created_row_was_rolled_back()
	{
		$this->assertDatabaseMissing('xf_user', ['username' => 'CreatedAndSaved']);
	}
}
