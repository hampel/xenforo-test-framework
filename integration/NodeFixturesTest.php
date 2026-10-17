<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Entity\Forum;

/**
 * The node fixtures, and the property that justifies them: a node insert that leaves every other
 * row in xf_node alone.
 *
 * Nothing here saves a node the ordinary way. On a forum shared with anything else that would
 * write - and so lock - every node row for the length of the test, which is the hazard these
 * helpers exist to avoid. The controls recorded in the commit are what show the assertions
 * discriminate.
 */
class NodeFixturesTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_a_fixture_node_writes_no_other_node_row_and_queues_no_job()
	{
		$db = $this->app()->db();
		$jobsBefore = $db->fetchOne('SELECT COUNT(*) FROM xf_job');

		$db->logQueries(true, true);
		$offset = count($db->getQueryLog());

		$node = $this->createNodeFixture('Zz Quiet Forum');
		\XF::triggerRunOnce();

		$mine = array_slice($db->getQueryLog(), $offset);
		$db->logQueries(false);

		$nodeUpdates = array_filter(
			$mine,
			function ($query)
			{
				return stripos($query['query'], 'UPDATE') === 0
					&& stripos($query['query'], 'xf_node') !== false;
			}
		);

		$this->assertSame([], array_values($nodeUpdates), 'no existing node row should be written');
		$this->assertSame(
			$jobsBefore,
			$db->fetchOne('SELECT COUNT(*) FROM xf_job'),
			'no permissionRebuild job should be queued'
		);

		// the positive half: the node really was created, so none of the above passed on nothing
		$this->assertGreaterThan(0, $node->node_id);
	}

	public function test_a_fixture_node_is_usable_through_its_own_type()
	{
		$node = $this->createNodeFixture('Zz Usable Forum', 'Forum', [], ['min_tags' => 0]);

		$forum = $this->app()->em()->find('XF:Forum', $node->node_id);

		$this->assertInstanceOf(Forum::class, $forum);
		$this->assertSame('Zz Usable Forum', $forum->Node->title);
	}

	public function test_a_fixture_leaf_does_not_claim_to_have_children()
	{
		$node = $this->createNodeFixture('Zz Leaf');

		// XenForo answers hasChildren() from (rgt - lft) > 1, so a range wider than the node needs
		// makes a childless node claim children
		$this->assertSame(1, $node->rgt - $node->lft);
		$this->assertFalse($node->hasChildren());
	}

	public function test_a_fixture_node_is_numbered_clear_of_the_forums_own_tree()
	{
		$highest = (int) $this->app()->db()->fetchOne(
			'SELECT MAX(rgt) FROM xf_node WHERE title NOT LIKE ?',
			'Zz %'
		);

		$node = $this->createNodeFixture('Zz Clear');

		$this->assertGreaterThan($highest, $node->lft);
	}

	public function test_naming_a_parent_is_refused_and_names_the_tree_helper()
	{
		$parent = $this->createNodeFixture('Zz Would Be Parent');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('createNodeTreeFixture()');

		$this->createNodeFixture('Zz Child', 'Forum', ['parent_node_id' => $parent->node_id]);
	}

	public function test_a_tree_is_numbered_so_the_parent_encloses_its_children()
	{
		$nodes = $this->createNodeTreeFixture([
			'Zz Top' => [
				'type' => 'Category',
				'children' => [
					'Zz Inner' => ['type' => 'Forum'],
					'Zz Other' => ['type' => 'Forum'],
				],
			],
		]);

		$this->assertSame(['Zz Top', 'Zz Inner', 'Zz Other'], array_keys($nodes));

		$top = $nodes['Zz Top'];
		$inner = $nodes['Zz Inner'];

		$this->assertTrue($top->hasChildren(), 'the parent has children');
		$this->assertFalse($inner->hasChildren(), 'the child does not');
		$this->assertSame(0, $top->depth);
		$this->assertSame(1, $inner->depth);
		$this->assertSame($top->node_id, $inner->parent_node_id);

		// the range encloses both children exactly - 1 parent + 2 children is 6 values
		$this->assertSame(5, $top->rgt - $top->lft);

		$descendants = $this->app()->db()->fetchAllColumn(
			'SELECT title FROM xf_node WHERE lft > ? AND rgt < ? ORDER BY lft',
			[$top->lft, $top->rgt]
		);
		$this->assertSame(['Zz Inner', 'Zz Other'], $descendants);
	}

	public function test_a_child_carries_its_parent_in_its_breadcrumb()
	{
		$nodes = $this->createNodeTreeFixture([
			'Zz Trail Top' => ['type' => 'Category', 'children' => [
				'Zz Trail Deep' => ['type' => 'Forum', 'children' => [
					'Zz Trail Deeper' => ['type' => 'Forum'],
				]],
			]],
		]);

		$this->assertSame([], $nodes['Zz Trail Top']->breadcrumb_data, 'a root has no trail, as XenForo stores it');

		$deep = $nodes['Zz Trail Deep']->breadcrumb_data;
		$this->assertSame([$nodes['Zz Trail Top']->node_id], array_keys($deep));
		$this->assertSame('Zz Trail Top', $deep[$nodes['Zz Trail Top']->node_id]['title']);

		$deeper = array_keys($nodes['Zz Trail Deeper']->breadcrumb_data);
		$this->assertSame(
			[$nodes['Zz Trail Top']->node_id, $nodes['Zz Trail Deep']->node_id],
			$deeper,
			'the trail accumulates down the tree'
		);
	}

	public function test_the_same_title_twice_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('twice');

		$this->createNodeTreeFixture([
			'Zz Same' => ['children' => ['Zz Same' => []]],
		]);
	}

	public function test_an_empty_tree_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('empty tree');

		$this->createNodeTreeFixture([]);
	}

	public function test_a_thread_fixture_has_a_first_post()
	{
		$node = $this->createNodeFixture('Zz Thread Forum');

		$thread = $this->createThreadFixture($node, 'Zz Thread', ['message' => 'Zz body text']);

		$this->assertSame($node->node_id, $thread->node_id);
		$this->assertGreaterThan(0, $thread->first_post_id);

		$fresh = $this->app()->em()->find('XF:Thread', $thread->thread_id);
		$this->assertNotNull($fresh->FirstPost, 'a thread whose FirstPost is null is half-formed');
		$this->assertSame('Zz body text', $fresh->FirstPost->message);
	}

	public function test_a_thread_fixture_takes_the_node_id_as_well_as_the_entity()
	{
		$node = $this->createNodeFixture('Zz Id Forum');

		$thread = $this->createThreadFixture($node->node_id, 'Zz By Id');

		$this->assertSame($node->node_id, $thread->node_id);
	}

	public function test_a_thread_fixture_and_its_first_post_name_one_author()
	{
		$node = $this->createNodeFixture('Zz Author Forum');

		$thread = $this->createThreadFixture($node, 'Zz Authored', ['user_id' => 1]);
		$post = $thread->FirstPost;

		$this->assertSame(1, $thread->user_id);
		$this->assertSame(1, $post->user_id);
		$this->assertSame($thread->username, $post->username);
		$this->assertNotSame('', $thread->username);
		$this->assertSame(1, $thread->last_post_user_id);
	}

	public function test_a_guest_thread_fixture_is_attributed_to_no_member_at_all()
	{
		$db = $this->app()->db();
		$node = $this->createNodeFixture('Zz Guest Forum');

		// the write this test asserts is absent is gated on the forum counting messages and the
		// thread being visible, so both have to be true or its absence means nothing
		$forum = $this->app()->em()->find('XF:Forum', $node->node_id);
		$this->assertTrue((bool) $forum->count_messages, 'the fixture forum counts messages');
		$countBefore = $db->fetchOne('SELECT message_count FROM xf_user WHERE user_id = 1');

		$thread = $this->createThreadFixture($node, 'Zz Guest Thread', [
			'user_id' => 0,
			'username' => 'Zz Passer By',
		]);
		$post = $thread->FirstPost;

		$this->assertSame('visible', $thread->discussion_state);
		$this->assertSame(0, $thread->user_id);
		$this->assertSame('Zz Passer By', $thread->username);
		$this->assertSame(0, $post->user_id, 'the post is the guest\'s, not a member\'s');
		$this->assertSame('Zz Passer By', $post->username);
		$this->assertSame(0, $thread->last_post_user_id);

		// XenForo only adjusts a message count for a post that has a user, so a guest post writes
		// to no user row - where one misattributed to user 1 writes to that member's row, inside
		// this test's transaction
		$this->assertSame(
			$countBefore,
			$db->fetchOne('SELECT message_count FROM xf_user WHERE user_id = 1'),
			'a guest post should leave every user row alone'
		);
	}

	public function test_a_guest_thread_fixture_without_a_name_is_refused()
	{
		$node = $this->createNodeFixture('Zz Nameless Forum');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('found no xf_user row for user_id 0');

		$this->createThreadFixture($node, 'Zz Nameless', ['user_id' => 0]);
	}

	public function test_a_thread_fixture_for_a_visitor_with_no_user_row_is_refused()
	{
		$node = $this->createNodeFixture('Zz Absent Author Forum');
		$unused = (int) $this->app()->db()->fetchOne('SELECT MAX(user_id) FROM xf_user') + 1000;

		// actingAsMember() builds its visitor in memory, so the id it carries need match no row
		$this->actingAsMember(['user_id' => $unused]);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("found no xf_user row for user_id $unused");

		$this->createThreadFixture($node, 'Zz Absent Author');
	}
}
