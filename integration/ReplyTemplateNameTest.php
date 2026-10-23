<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\AssertionFailedError;
use XF\Mvc\Reply\View;

/**
 * assertReplyTemplate() takes the template bare, as the controller named it, or typed, as the
 * template helpers take it - and a typed name is checked against the application the reply was
 * dispatched through.
 */
class ReplyTemplateNameTest extends TestCase
{
	// an admin dispatch can write to xf_admin_log
	use UsesDatabaseTransactions;

	private function adminOptionsReply()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		return $this->dispatch('options', 'admin');
	}

	public function test_a_typed_name_matches_the_bare_name_a_controller_gave()
	{
		$reply = $this->adminOptionsReply();

		$this->assertReplyTemplate($reply, 'option_group_list');
		$this->assertReplyTemplate($reply, 'admin:option_group_list');
	}

	public function test_a_typed_name_of_the_wrong_type_fails()
	{
		$reply = $this->adminOptionsReply();

		$this->expectException(AssertionFailedError::class);

		$this->assertReplyTemplate($reply, 'public:option_group_list');
	}

	public function test_a_typed_name_the_reply_itself_carries_is_compared_as_given()
	{
		$reply = new View('XF:Tools\Index', 'public:delete_confirm');
		$this->swap('app.classType', 'Admin');

		$this->assertReplyTemplate($reply, 'public:delete_confirm');

		try
		{
			// the admin dispatch would type a bare name admin:, and the reply named a public one
			$this->assertReplyTemplate($reply, 'delete_confirm');
			$this->fail('a bare name typed from the dispatch is not the public template the reply named');
		}
		catch (AssertionFailedError $e)
		{
			$this->assertStringContainsString('public:delete_confirm', $e->getMessage());
		}
	}

	public function test_outside_a_dispatch_the_names_are_compared_as_given()
	{
		$reply = new View('XF:Tools\Index', 'tools');

		$this->assertReplyTemplate($reply, 'tools');

		$this->expectException(AssertionFailedError::class);

		$this->assertReplyTemplate($reply, 'admin:tools');
	}
}
