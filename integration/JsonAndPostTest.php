<?php

namespace Hampel\Testing\Integration;

use XF\Mvc\Reply\View;

/**
 * renderJsonReply(), and dispatching a method other than GET.
 */
class JsonAndPostTest extends TestCase
{
	public function test_a_json_view_renders_its_own_document()
	{
		$this->setAppClassType('public');

		$reply = new View('XF:Search\AutoComplete', '', ['results' => [], 'q' => 'probe']);

		$document = $this->renderJsonReply($reply);

		$this->assertSame('probe', $document['q']);
		$this->assertSame([], $document['results']);
	}

	public function test_a_cli_class_type_is_refused_rather_than_rendering_nothing()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('still Cli');

		$this->renderJsonReply(new View('XF:Search\AutoComplete', '', []));
	}

	public function test_a_view_without_render_json_is_refused()
	{
		$this->setAppClassType('public');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('cannot answer JSON');

		// an ordinary template-rendering view has no renderJson()
		$this->renderJsonReply(new View('XF:NoSuchViewAnywhere', 'public:login', []));
	}

	public function test_a_post_dispatch_passes_the_csrf_check()
	{
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

		// the request carries a token with a matching cookie, so the check passes and the
		// controller runs - core's own controllers do not opt out of it
		$reply = $this->dispatch('help/terms', 'public', [], [], 'POST');

		$this->assertReplyIsView($reply);
	}

	public function test_the_same_post_without_a_token_is_refused()
	{
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

		// which is the behaviour a test of an endpoint that DOES opt out asserts the absence of
		$reply = $this->dispatchWithoutCsrfToken('help/terms', 'public');

		$this->assertReplyIsError($reply, 400);
	}

	public function test_a_get_dispatch_is_unchanged()
	{
		$member = $this->actingAsMember();
		$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

		$this->assertReplyIsRedirect($this->dispatch('help/terms'));
	}
}
