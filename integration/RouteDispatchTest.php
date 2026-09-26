<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\AssertionFailedError;
use XF\Mvc\Reply\Reroute;

/**
 * Dispatching a route in process. The assertions here deliberately use XenForo's own admin pages
 * and a route that cannot exist, because a public route's reply depends on the forum's options -
 * what 'index' resolves to, whether custom terms are set.
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

	public function test_an_api_route_returns_an_api_result()
	{
		$reply = $this->dispatch('me', 'api');

		$this->assertReplyIsApiResult($reply);
		$this->assertNotNull($this->replyApiResult($reply));
	}

	/**
	 * An api route whose scope the key does not carry. XF::apiKey() never returns null - it builds
	 * a fallback key that is not a super user - so this is the un-bypassed shape, and it is the
	 * api equivalent of the admin permission case above.
	 */
	public function test_an_api_route_outside_the_keys_scope_is_refused()
	{
		$this->assertReplyIsError($this->dispatch('users', 'api'), 403);
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

	public function test_input_reaches_the_request_a_controller_reads()
	{
		// dispatch() swaps its request into the container, so this is the one a controller got
		$this->dispatch('no-such-route-xyz', 'public', ['user_id' => 7, 'email' => 'a@b.test']);

		$request = $this->app()->request();

		$this->assertSame(7, $request->filter('user_id', 'uint'));
		$this->assertSame('a@b.test', $request->filter('email', 'str'));
	}

	/**
	 * Putting criteria in the route path looks like the obvious thing to try, and the router takes
	 * the whole string as the path.
	 */
	public function test_a_query_string_in_the_route_path_does_not_work()
	{
		$this->assertReplyIsError($this->dispatch('help/terms?foo=1'), 404);
	}

	public function test_an_error_can_be_matched_on_its_message_not_only_its_code()
	{
		$reply = $this->dispatch('users', 'api');

		$this->assertReplyIsError($reply, 403, 'user:read');
		$this->assertNotEmpty($this->replyErrors($reply));
	}

	/**
	 * Two guards denying with the same code is the case that makes a code-only assertion lie, so
	 * this asserts which one fired. Without a key the scope guard refuses; with a super-user key
	 * the scope passes and the visitor's own permissions refuse instead.
	 */
	public function test_c_acting_as_an_api_key_changes_which_guard_refuses()
	{
		$this->assertReplyIsError($this->dispatch('users', 'api'), 403, 'scope');

		$this->actingAsMember();
		$this->actingAsApiKey();

		$this->assertReplyIsError($this->dispatch('users', 'api'), 403, 'permission');
	}

	public function test_d_the_api_key_is_restored_for_the_next_test()
	{
		// XF::$apiKey is a static nothing else resets, so the concern restores it in teardown
		$this->assertFalse(\XF::apiKey()->is_super_user);
	}

	public function test_the_board_index_dispatches_rather_than_redirecting_to_itself()
	{
		// the index is an empty route path, and its canonical url carries no query string - so a
		// request uri of '/index.php?' does not match it and the controller redirects
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

		$reply = $this->dispatch('');

		$this->assertReplyIsView($reply);
	}

	public function test_input_reaches_an_empty_route_without_a_stray_separator()
	{
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

		$this->dispatch('', 'public', ['probe' => 'value']);

		$this->assertSame(
			'/index.php?probe=value',
			$this->app()->request()->getServer('REQUEST_URI')
		);
	}

	public function test_an_unknown_route_type_is_rejected()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("Unknown route type 'install'");

		$this->dispatch('index', 'install');
	}

	/**
	 * A redirect reply carries no http status of its own - getResponseCode() answers 200 whether it
	 * is permanent or not, because the renderer chooses 301 or 303 from the type much later. So a
	 * test asserting the code cannot tell the two apart, and the type is what to assert.
	 */
	public function test_a_redirect_is_recognised_by_its_type_not_its_code()
	{
		$reply = $this->dispatch('help/terms');

		$this->assertReplyIsRedirect($reply, null, 'permanent');
		$this->assertSame(200, $reply->getResponseCode(), 'the reply carries no redirect status');
	}

	public function test_the_wrong_redirect_type_fails()
	{
		$this->expectException(AssertionFailedError::class);

		$this->assertReplyIsRedirect($this->dispatch('help/terms'), null, 'temporary');
	}

	public function test_an_unknown_redirect_type_is_rejected()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Unknown redirect type');

		$this->assertReplyIsRedirect($this->dispatch('help/terms'), null, 'permenant');
	}

	/**
	 * Without a message of its own the failure says only what the reply was, which in a test that
	 * dispatches several routes does not say which one.
	 */
	public function test_a_message_of_the_callers_own_reaches_the_failure()
	{
		try
		{
			$this->assertReplyIsView($this->dispatch('help/terms'), 'the terms page');
		}
		catch (AssertionFailedError $e)
		{
			$this->assertStringContainsString('the terms page', $e->getMessage());
			$this->assertStringContainsString('Redirect', $e->getMessage());

			return;
		}

		$this->fail('expected the assertion to fail');
	}
}
