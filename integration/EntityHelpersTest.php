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

	/**
	 * DOCS.md tells people to build a relation chain in memory with XF's hydrateRelation(), because
	 * an unsaved entity has no database behind it to resolve a relation against. Verify the recipe
	 * rather than assume it - reaching through three entities is what a permission test needs.
	 */
	public function test_relations_can_be_hydrated_without_a_database()
	{
		// primary keys are read-only columns, so they go in with setTrusted() rather than through
		// makeEntity()'s values - see the test below
		$node = $this->makeEntity('XF:Node', ['title' => 'Test node']);
		$node->setTrusted('node_id', 1);

		$forum = $this->makeEntity('XF:Forum');
		$forum->setTrusted('node_id', 1);

		$thread = $this->makeEntity('XF:Thread', ['node_id' => 1]);
		$thread->setTrusted('thread_id', 1);

		$forum->hydrateRelation('Node', $node);
		$thread->hydrateRelation('Forum', $forum);

		$this->assertSame(1, $thread->Forum->Node->node_id);
		$this->assertSame('Test node', $thread->Forum->Node->title);

		// nothing was written to get there
		$this->assertDatabaseMissing('xf_node', ['title' => 'Test node']);
	}

	/**
	 * bulkSet() validates, and assigning an existing primary key sends the entity to the finder for a
	 * uniqueness check - which needs a database. setTrusted() writes the column directly.
	 */
	public function test_a_primary_key_can_be_set_on_an_unsaved_entity()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'KeyedNotSaved']);
		$user->setTrusted('user_id', 424242);

		$this->assertSame(424242, $user->user_id);
		$this->assertTrue($user->isInsert());
		$this->assertDatabaseMissing('xf_user', ['user_id' => 424242]);
	}

	/** and the transaction wrapper takes it away again */
	public function test_the_created_row_was_rolled_back()
	{
		$this->assertDatabaseMissing('xf_user', ['username' => 'CreatedAndSaved']);
	}
}
