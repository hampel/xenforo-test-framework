<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\AssertionFailedError;
use XF\Mvc\Reply\View;

/**
 * renderReplyPage() wraps a View reply in the page container of the application it was
 * dispatched through, with the parameters XenForo adds for a request.
 *
 * The container renders the forum's real navigation and widgets, which on a shared forum raise
 * sibling add-ons' template errors, so every test here fakes the error log and asserts on the
 * page's own markers rather than on a clean error list.
 */
class RenderReplyPageTest extends TestCase
{
	use UsesDatabaseTransactions;

	protected function setUp(): void
	{
		parent::setUp();
		$this->fakesErrors();
	}

	public function test_a_public_view_is_wrapped_in_the_public_container_with_the_reply_read_into_it()
	{
		$this->actingAsMember([], ['general' => ['view' => true]]);
		$reply = $this->dispatch('help');

		$page = $this->renderReplyPage($reply);

		$this->assertStringContainsString('<html', $page);
		$this->assertStringContainsString('data-template="help_index"', $page, 'the reply\'s template name reaches the container');
		$this->assertMatchesRegularExpression('#<title>\s*Help#', $page, 'the title the template set reaches the container');
		$this->assertStringContainsString('p-nav', $page, 'the navigation is rendered');
	}

	public function test_an_admin_view_is_wrapped_in_the_admin_container()
	{
		$this->actingAsAdministrator(['option' => true]);
		$reply = $this->dispatch('tools', 'admin');

		$page = $this->renderReplyPage($reply);

		$this->assertStringContainsString('<html', $page);
		$this->assertStringContainsString('data-template="admin_section"', $page);
		$this->assertStringContainsString('admin.php', $page, 'the admin navigation is rendered');
	}

	public function test_page_parameters_reach_the_container_and_can_replace_it()
	{
		// dispatch first: creating a template queues a style-cache truncate that the dispatch's drain
		// would run, and a TRUNCATE commits the transaction the template is meant to roll back in
		$this->actingAsMember([], ['general' => ['view' => true]]);
		$reply = $this->dispatch('help');

		$this->createEntity('XF:Template', [
			'type' => 'public',
			'title' => 'zz_page_container',
			'style_id' => 0,
			'addon_id' => '',
			'template' => 'WRAP[{$content|raw}]',
		]);

		$content = $this->renderReply($reply);

		$page = $this->renderReplyPage($reply, ['template' => 'zz_page_container']);

		$this->assertStringStartsWith('WRAP[', $page);
		$this->assertStringContainsString($content, $page, 'the content is what renderReply() renders');
	}

	public function test_a_reply_under_the_cli_application_is_refused_before_anything_is_wrapped()
	{
		// no dispatch, so the application is the Cli one, whose renderPage() would wrap nothing; the
		// refusal is renderReply()'s, reached before anything is wrapped
		$reply = new View('XF:Help\Index', 'help_index');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("app class type is 'Cli'");

		$this->renderReplyPage($reply);
	}

	public function test_a_reply_that_is_not_a_view_is_refused_before_anything_is_wrapped()
	{
		$reply = $this->dispatch('no-such-route-zz');

		$this->expectException(AssertionFailedError::class);

		$this->renderReplyPage($reply);
	}
}
