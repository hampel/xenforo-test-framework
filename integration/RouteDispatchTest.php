<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Mvc\Reply\Reroute;

/**
 * Dispatching a route in process. The assertions here deliberately use XenForo's own admin pages
 * and a route that cannot exist, because a public route's reply depends on the forum's options -
 * what 'index' resolves to, whether custom terms are set - and would make this suite report on
 * the development forum's configuration rather than on the dispatch.
 */
class RouteDispatchTest extends TestCase
{
	// an admin dispatch can write to xf_admin_log
	use UsesDatabaseTransactions;

	public function test_a_an_admin_route_returns_the_view_its_controller_built()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		$reply = $this->dispatch('options', 'admin');

		$this->assertReplyIsView($reply);
		$this->assertReplyTemplate($reply, 'option_group_list');
		$this->assertReplyViewClass($reply, 'XF:Option\GroupList');
		$this->assertNotEmpty($this->replyParam($reply, 'groups'));
	}

	public function test_b_the_class_type_is_back_to_cli_for_the_next_test()
	{
		// dispatch() swaps app.classType, and nothing restores it - each test rebuilds the app
		// instead. If that ever stops being true, this concern needs a setUpTraits() hook.
		$this->assertSame('Cli', $this->app()->container('app.classType'));
	}

	public function test_reroutes_are_resolved_rather_than_returned()
	{
		// the public index takes two hops: XF:Index/index -> XF:Forum/index -> ForumController
		$this->assertNotInstanceOf(Reroute::class, $this->dispatch('index'));
	}

	public function test_a_route_that_does_not_exist_is_a_404()
	{
		$this->assertReplyIsError($this->dispatch('no-such-route-xyz'), 404);
	}

	/**
	 * The control for the test above, and the case the audit skill says cannot be tested at all
	 * today: an action's own access check, which only runs because the route was dispatched.
	 */
	public function test_an_admin_route_refuses_an_admin_without_the_permission()
	{
		$this->actingAsMember(['is_admin' => true]);

		$this->assertReplyIsError($this->dispatch('options', 'admin'), 403);
	}

	public function test_an_admin_route_without_an_admin_visitor_fails_loudly()
	{
		$this->actingAsMember();

		// XenForo reroutes to the login form, which is a view with a 200 - so without this guard
		// a test would assert against the login page and never know
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is_admin');

		$this->dispatch('options', 'admin');
	}

	public function test_an_unknown_route_type_is_rejected()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("Unknown route type 'install'");

		$this->dispatch('index', 'install');
	}
}
