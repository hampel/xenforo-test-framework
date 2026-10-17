<?php

namespace Hampel\Testing\Concerns;

use XF\Entity\Node;
use XF\Entity\Post;
use XF\Entity\Thread;

/**
 * Node and thread fixtures that do not rebuild the forum's node tree.
 *
 * Saving an `XF:Node` the ordinary way is not safe on a forum shared with anything else. XenForo's
 * `TreeStructured` behaviour schedules a nested-set rebuild and enqueues a `permissionRebuild` job
 * on every insert, and the rebuild writes **every** row in `xf_node` - so inside a test's
 * transaction every node on the forum is locked until the test ends, whether or not any value
 * changes.
 *
 * These helpers turn the behaviour's `rebuildCache` option off and supply the nested-set columns
 * themselves, which takes a node insert from four writes to one and touches no existing row.
 */
trait InteractsWithNodes
{
	/**
	 * Create a node and its type data row, without rebuilding the tree.
	 *
	 * The node is created at the root, numbered clear of the forum's own tree. A node type with no
	 * extra data needs no `$dataValues` - the row is still created, since a node whose data row is
	 * missing fails wherever XenForo reads through it.
	 *
	 * @param string $title
	 * @param string $nodeTypeId - Category, Forum, LinkForum, Page, SearchForum, or an add-on's own
	 * @param array $values - further columns for the node
	 * @param array $dataValues - columns for the type data row, eg an XF:Forum
	 *
	 * @return Node
	 */
	protected function createNodeFixture($title, $nodeTypeId = 'Forum', array $values = [], array $dataValues = [])
	{
		if (!empty($values['parent_node_id']))
		{
			throw new \LogicException(
				'createNodeFixture() creates a node at the root, so it cannot take a parent: a child'
				. ' has to be numbered inside its parent, and a parent whose nested-set range is'
				. ' wider than its children makes XenForo report that a childless node has children.'
				. ' Build the parent and the child together with createNodeTreeFixture() instead.'
			);
		}

		$left = $this->nextNodeFixtureLeft();

		return $this->insertNodeFixture($title, $nodeTypeId, $values, $dataValues, $left, $left + 1, 0, []);
	}

	/**
	 * Create a tree of nodes in one call, numbered as XenForo numbers one.
	 *
	 * Each key is a node title; each value may carry `type`, `values`, `data` and `children`:
	 *
	 * ```php
	 * $nodes = $this->createNodeTreeFixture([
	 *     'Top category' => ['type' => 'Category', 'children' => [
	 *         'Inner forum' => ['type' => 'Forum'],
	 *     ]],
	 * ]);
	 * ```
	 *
	 * A tree has to be built in one call rather than a node at a time, because a child's
	 * nested-set range sits inside its parent's and the parent's range is not known until its
	 * children have been numbered.
	 *
	 * @param array $spec - title => definition, nested through `children`
	 *
	 * @return array<string, Node> - every node created, keyed by its title
	 */
	protected function createNodeTreeFixture(array $spec)
	{
		if (!$spec)
		{
			throw new \LogicException(
				'createNodeTreeFixture() was given an empty tree, so it would create nothing and'
				. ' return nothing. Name at least one node.'
			);
		}

		$flat = [];
		$left = $this->nextNodeFixtureLeft();
		$this->numberNodeFixtureSpec($spec, $flat, $left, 0, null);

		$nodes = [];

		// $flat holds each node before its own children, so a parent is always already saved
		foreach ($flat AS $entry)
		{
			$values = $entry['values'];
			$parent = $entry['parent'] === null ? null : $nodes[$entry['parent']];

			if ($parent)
			{
				$values['parent_node_id'] = $parent->node_id;
			}

			$nodes[$entry['title']] = $this->insertNodeFixture(
				$entry['title'],
				$entry['type'],
				$values,
				$entry['data'],
				$entry['lft'],
				$entry['rgt'],
				$entry['depth'],
				$parent ? $this->nodeFixtureBreadcrumb($parent) : []
			);
		}

		return $nodes;
	}

	/**
	 * Create a thread in a node, with its first post.
	 *
	 * The post is not optional: a thread whose `first_post_id` is 0 has a null `FirstPost`, so
	 * anything reading through it fails on a fixture that looks complete.
	 *
	 * Saving a thread writes to the forum's counters, the news feed and the content activity log,
	 * which is XenForo's own behaviour and is why a thread fixture belongs under
	 * `UsesDatabaseTransactions` like any other.
	 *
	 * @param Node|int $node - the node, or its id
	 * @param string $title
	 * @param array $values - further columns for the thread; `message` is used for the first post
	 *
	 * @return Thread
	 */
	protected function createThreadFixture($node, $title, array $values = [])
	{
		$nodeId = $node instanceof Node ? $node->node_id : (int) $node;
		$message = $values['message'] ?? 'Thread fixture body';
		unset($values['message']);

		$userId = $values['user_id'] ?? \XF::visitor()->user_id ?: 1;
		$username = $values['username'] ?? (string) $this->app()->db()->fetchOne(
			'SELECT username FROM xf_user WHERE user_id = ?',
			$userId
		);

		/** @var Thread $thread */
		$thread = $this->createEntity('XF:Thread', $values + [
			'node_id' => $nodeId,
			'title' => $title,
			'user_id' => $userId,
			'username' => $username,
			'discussion_state' => 'visible',
		]);

		$post = $this->createEntity('XF:Post', [
			'thread_id' => $thread->thread_id,
			'user_id' => $userId,
			'username' => $username,
			'message' => $message,
			'message_state' => 'visible',
			'position' => 0,
		]);

		if (!$post instanceof Post)
		{
			throw new \LogicException(
				'XF:Post resolved to ' . get_class($post) . ', which is not an XF\Entity\Post, so'
				. ' the thread fixture cannot read the post id back. An add-on has replaced the'
				. ' entity with something unrelated.'
			);
		}

		$thread->bulkSet([
			'first_post_id' => $post->post_id,
			'last_post_id' => $post->post_id,
			'last_post_user_id' => $userId,
			'last_post_username' => $username,
		]);
		$thread->save();

		return $thread;
	}

	/**
	 * Save one fixture node and its type data row with the tree rebuild suppressed.
	 *
	 * @param string $title
	 * @param string $nodeTypeId
	 * @param array $values
	 * @param array $dataValues
	 * @param int $lft
	 * @param int $rgt
	 * @param int $depth
	 * @param array $breadcrumb
	 *
	 * @return Node
	 */
	private function insertNodeFixture($title, $nodeTypeId, array $values, array $dataValues, $lft, $rgt, $depth, array $breadcrumb)
	{
		/** @var Node $node */
		$node = $this->app()->em()->create('XF:Node');

		// both the nested-set rebuild and the permissionRebuild job are behind this one option
		$node->getBehavior('XF:TreeStructured')->setOption('rebuildCache', false);

		$node->bulkSet($values + [
			'title' => $title,
			'node_type_id' => $nodeTypeId,
			'parent_node_id' => 0,
			'display_order' => 1,
		]);

		// read-only columns nothing will compute now that the rebuild is off
		$node->setTrusted('lft', $lft);
		$node->setTrusted('rgt', $rgt);
		$node->setTrusted('depth', $depth);
		$node->setTrusted('breadcrumb_data', $breadcrumb);

		$node->save();

		$data = $node->getDataRelationOrDefault();

		if ($dataValues)
		{
			$data->bulkSet($dataValues);
		}

		$data->save();

		return $node;
	}

	/**
	 * Number a tree specification depth-first, so each parent's range encloses its children's
	 * exactly - a wider range would make XenForo's `hasChildren()` true for a childless node.
	 *
	 * @param array $spec
	 * @param array $flat - filled with one entry per node, parents before their children
	 * @param int $counter
	 * @param int $depth
	 * @param string|null $parent - the parent's title, or null at the root
	 *
	 * @return void
	 */
	private function numberNodeFixtureSpec(array $spec, array &$flat, &$counter, $depth, $parent)
	{
		foreach ($spec AS $title => $definition)
		{
			if (isset($flat[$title]))
			{
				throw new \LogicException(
					"createNodeTreeFixture() was given the title '$title' twice. The tree it returns"
					. ' is keyed by title, so one of the two would be unreachable. Give them'
					. ' different titles.'
				);
			}

			$index = $title;
			$flat[$index] = [
				'title' => $title,
				'type' => $definition['type'] ?? 'Forum',
				'values' => $definition['values'] ?? [],
				'data' => $definition['data'] ?? [],
				'depth' => $depth,
				'parent' => $parent,
				'lft' => $counter++,
			];

			$this->numberNodeFixtureSpec($definition['children'] ?? [], $flat, $counter, $depth + 1, $title);

			$flat[$index]['rgt'] = $counter++;
		}
	}

	/**
	 * The breadcrumb a child of this node carries: its parent's own trail plus the parent.
	 *
	 * The field set is XenForo's, read from what it stores rather than inferred. A root node's
	 * trail is empty, which is what XenForo stores for one too.
	 *
	 * @param Node $parent
	 *
	 * @return array
	 */
	private function nodeFixtureBreadcrumb(Node $parent)
	{
		$trail = $parent->breadcrumb_data ?: [];

		$trail[$parent->node_id] = [
			'node_id' => $parent->node_id,
			'title' => $parent->title,
			'depth' => $parent->depth,
			'lft' => $parent->lft,
			'node_name' => $parent->node_name,
			'node_type_id' => $parent->node_type_id,
			'display_in_list' => (bool) $parent->display_in_list,
		];

		return $trail;
	}

	/**
	 * A left value clear of the forum's own tree, so a fixture can never be numbered into it.
	 *
	 * @return int
	 */
	private function nextNodeFixtureLeft()
	{
		return (int) $this->app()->db()->fetchOne('SELECT MAX(rgt) FROM xf_node') + 1000;
	}
}
