<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * A POST dispatch through preDispatch(), the CSRF check included, and the assertion that compares
 * a reply parameter rather than taking it as a failure message.
 */
class PostDispatchTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function actingAsNoticeAdmin()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['notice' => true]);

		return $admin;
	}

	private function noticeInput()
	{
		return [
			'title' => 'post dispatch probe ' . uniqid(),
			'message' => 'probe',
			'notice_type' => 'block',
			'display_style' => 'primary',
		];
	}

	public function test_a_post_dispatch_reaches_the_action_behind_the_csrf_check()
	{
		$this->fakesRegistry();
		$this->actingAsNoticeAdmin();

		$input = $this->noticeInput();
		$reply = $this->dispatch('notices/save', 'admin', $input, [], 'POST');

		$this->assertReplyIsRedirect($reply);
		$this->assertDatabaseHas('xf_notice', ['title' => $input['title']]);
	}

	public function test_a_dispatch_with_no_token_is_refused_by_the_check()
	{
		$this->fakesRegistry();
		$this->actingAsNoticeAdmin();

		$input = $this->noticeInput();
		$reply = $this->dispatchWithoutCsrfToken('notices/save', 'admin', $input);

		$this->assertReplyIsError($reply, 400);
		$this->assertDatabaseMissing('xf_notice', ['title' => $input['title']]);
	}

	public function test_a_token_the_test_supplied_is_not_replaced()
	{
		$this->fakesRegistry();
		$this->actingAsNoticeAdmin();

		// an invalid token has to stay invalid, or a suite cannot test what a bad one does
		$input = $this->noticeInput() + ['_xfToken' => '1,not-a-real-token'];
		$reply = $this->dispatch('notices/save', 'admin', $input, [], 'POST');

		$this->assertReplyIsError($reply, 400);
	}

	public function test_a_get_dispatch_still_sends_no_token()
	{
		$this->actingAsNoticeAdmin();

		$reply = $this->dispatch('notices', 'admin');

		$this->assertReplyIsView($reply);
		// getCookie() answers its fallback, false, when the cookie is absent
		$this->assertFalse($this->app()->request()->getCookie('csrf'), 'a GET needs no csrf cookie');
	}

	public function test_a_reply_parameter_can_be_compared_rather_than_asserted_present()
	{
		$this->actingAsNoticeAdmin();

		$reply = $this->dispatch('notices', 'admin');

		// totalNotices is an int, so a strict comparison is meaningful
		$total = $this->replyParam($reply, 'totalNotices');

		$this->assertIsInt($total);
		$this->assertReplyParamSame($reply, 'totalNotices', $total);
	}

	public function test_comparing_the_wrong_value_fails()
	{
		$this->actingAsNoticeAdmin();

		$reply = $this->dispatch('notices', 'admin');

		$this->expectException(ExpectationFailedException::class);
		$this->assertReplyParamSame($reply, 'totalNotices', 'definitely not the total');
	}

	public function test_the_refusal_message_names_the_comparison_helper()
	{
		$this->actingAsNoticeAdmin();

		$reply = $this->dispatch('notices', 'admin');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('assertReplyParamSame');

		$this->assertReplyParam($reply, 'totalNotices', true);
	}
}
