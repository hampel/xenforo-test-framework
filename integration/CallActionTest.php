<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use XF\Mvc\Reply\Error;

/**
 * callAction() - an action called directly, with a POST, for the half of a controller dispatch()
 * cannot reach. Written against XenForo's own admin notice controller, because its save is the
 * standard shape: assertPostOnly(), a FormAction, and a runOnce cache rebuild on save.
 */
class CallActionTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_a_validation_failure_comes_back_as_an_error_reply_keyed_by_field()
	{
		// FormAction::run() throws a PrintableException here, and the dispatcher is what turns it
		// into a reply. Called directly without that, the exception escapes the test
		$reply = $this->callAction('XF:Notice', 'save', 'admin', ['title' => '', 'message' => '']);

		$this->assertReplyIsError($reply);
		$this->assertArrayHasKey('title', $this->replyErrors($reply), 'errors should keep their field keys');
	}

	public function test_a_reply_thrown_as_an_exception_comes_back_as_that_reply()
	{
		// assertPostOnly() throws XF\Mvc\Reply\Exception - the method argument exists for this
		$reply = $this->callAction('XF:Notice', 'save', 'admin', [], [], 'GET');

		$this->assertReplyIsError($reply, 405);
	}

	public function test_a_valid_post_saves_and_redirects()
	{
		$this->fakesRegistry();

		$title = 'callAction probe ' . uniqid();
		$reply = $this->callAction('XF:Notice', 'save', 'admin', [
			'title' => $title,
			'message' => 'Hello',
			'active' => 1,
			'notice_type' => 'block',
			'display_style' => 'primary',
		]);

		$this->assertReplyIsRedirect($reply);
		$this->assertDatabaseHas('xf_notice', ['title' => $title]);
	}

	public function test_a_toggle_reads_its_input_under_the_column_name()
	{
		$this->fakesRegistry();
		$title = 'callAction toggle probe ' . uniqid();
		$this->callAction('XF:Notice', 'save', 'admin', [
			'title' => $title,
			'message' => 'Hello',
			'active' => 1,
			'notice_type' => 'block',
			'display_style' => 'primary',
		]);
		$noticeId = (int) $this->app()->db()->fetchOne('SELECT notice_id FROM xf_notice WHERE title = ?', $title);

		// keyed by the column, 'active' - not a fixed name. The wrong key saves nothing and still
		// returns the plugin's success message, which is why this asserts the row
		$reply = $this->callAction('XF:Notice', 'toggle', 'admin', ['active' => [$noticeId => false]]);

		$this->assertReplyIsMessage($reply);
		$this->assertDatabaseHas('xf_notice', ['notice_id' => $noticeId, 'active' => 0]);
	}

	public function test_deferred_work_the_action_queued_has_run()
	{
		// the notice entity rebuilds its cache through \XF::runOnce(), which the dispatcher drains
		// and a bare action call does not
		$registry = $this->fakesRegistry();
		$title = 'callAction runOnce probe ' . uniqid();

		$this->callAction('XF:Notice', 'save', 'admin', [
			'title' => $title,
			'message' => 'Hello',
			'active' => 1,
			'notice_type' => 'block',
			'display_style' => 'primary',
		]);

		// asserted on the new notice, not on the key existing: the fake registry is preloaded from
		// the real one, so 'notices' is there before the save and a check for it passed with the
		// drain removed - control run, and that is how this was found
		$noticeId = (int) $this->app()->db()->fetchOne('SELECT notice_id FROM xf_notice WHERE title = ?', $title);
		$this->assertArrayHasKey($noticeId, $registry->get('notices'), 'the runOnce cache rebuild should have run');
	}

	public function test_route_params_reach_the_action()
	{
		$reply = $this->callAction('XF:Notice', 'save', 'admin', ['title' => 'x', 'message' => 'y'], [
			'notice_id' => 999999999,
		]);

		// assertNoticeExists() on an id that is not there - which it only calls if the param arrived
		$this->assertReplyIsError($reply, 404);
	}

	public function test_the_request_is_the_one_in_the_container()
	{
		$this->callAction('XF:Notice', 'save', 'admin', ['title' => 'x', 'message' => 'y']);

		$this->assertTrue($this->app()->request()->isPost());
		$this->assertSame('x', $this->app()->request()->filter('title', 'str'));
	}

	public function test_a_reroute_is_followed_and_its_target_is_guarded()
	{
		// actionIndex reroutes to view when given an id. The target goes through the dispatcher,
		// so preDispatch() runs for it - callAction() skips the guard only on the action named
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['attachment' => true]);

		$reply = $this->callAction('XF:Attachment', 'index', 'admin', [], ['attachment_id' => 999999999], 'GET');

		$this->assertReplyIsError($reply, 404);
		$this->assertSame('View', $reply->getAction());
	}

	public function test_an_unknown_action_is_refused_rather_than_returning_nothing()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("has no action 'doesNotExist'");

		$this->callAction('XF:Notice', 'doesNotExist', 'admin');
	}

	public function test_an_unknown_controller_is_refused()
	{
		$this->expectException(\LogicException::class);

		$this->callAction('XF:NoSuchControllerAnywhere', 'save', 'admin');
	}

	public function test_the_reply_is_an_ordinary_reply_the_existing_assertions_accept()
	{
		$reply = $this->callAction('XF:Notice', 'save', 'admin');

		$this->assertInstanceOf(Error::class, $reply);
		$this->assertSame('Save', $reply->getAction());
	}
}
