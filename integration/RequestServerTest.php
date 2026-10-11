<?php

namespace Hampel\Testing\Integration;

/**
 * dispatch() and callAction() take request server values - REMOTE_ADDR, HTTP_USER_AGENT,
 * HTTP_REFERER and the rest - merged over the defaults they build, for code that reads them.
 */
class RequestServerTest extends TestCase
{
	private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	public function test_dispatch_sends_the_server_values_it_is_given()
	{
		$this->dispatch('help/terms', 'public', [], [
			'REMOTE_ADDR' => '203.0.113.7',
			'HTTP_USER_AGENT' => 'TestAgent/1.0',
			'HTTP_REFERER' => 'https://forum.example.com/threads/1/',
		]);

		$request = $this->app()->request();

		$this->assertSame('203.0.113.7', $request->getIp());
		$this->assertSame('TestAgent/1.0', $request->getUserAgent());
		$this->assertSame('https://forum.example.com/threads/1/', $request->getReferrer());
	}

	public function test_the_defaults_apply_when_nothing_is_passed()
	{
		$this->dispatch('help/terms');

		$request = $this->app()->request();

		$this->assertSame('127.0.0.1', $request->getIp());
		$this->assertEmpty($request->getUserAgent());
		$this->assertEmpty($request->getReferrer());
	}

	public function test_an_ipv6_address_round_trips()
	{
		$this->dispatch('help/terms', 'public', [], ['REMOTE_ADDR' => '2001:db8::7']);

		$this->assertSame('2001:db8::7', $this->app()->request()->getIp());
	}

	public function test_the_robot_name_comes_from_the_user_agent_sent()
	{
		$this->dispatch('help/terms', 'public', [], ['HTTP_USER_AGENT' => self::GOOGLEBOT]);

		$this->assertSame('google', $this->app()->request()->getRobotName());
	}

	public function test_a_proxied_address_is_read_only_when_asked_for()
	{
		$this->dispatch('help/terms', 'public', [], [
			'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.1',
		]);

		$request = $this->app()->request();

		$this->assertSame('198.51.100.9', $request->getIp(true));
		$this->assertSame('127.0.0.1', $request->getIp());
	}

	public function test_each_route_type_carries_the_script_the_forum_serves_it_from()
	{
		$this->actingAsAdministrator(['notice' => true]);
		$this->dispatch('notices', 'admin');

		$request = $this->app()->request();

		// the control panel has its own script; a request claiming index.php is not what an admin
		// controller would see on the forum
		$this->assertSame('/admin.php', $request->getServer('SCRIPT_NAME'));
		$this->assertStringStartsWith('/admin.php?notices', $request->getServer('REQUEST_URI'));
	}

	public function test_an_api_request_carries_the_api_route_prefix()
	{
		$this->actingAsApiKey();
		$this->dispatch('users', 'api');

		$request = $this->app()->request();

		// the api is routed through the public script behind an api/ prefix, which is what the
		// forum's own api links carry
		$this->assertSame('/index.php', $request->getServer('SCRIPT_NAME'));
		$this->assertStringContainsString('api/users', $request->getServer('REQUEST_URI'));
	}

	public function test_a_request_method_among_the_server_values_is_refused()
	{
		// the request method cannot come from here: the server array is merged over the defaults
		// while REQUEST_METHOD is set from $method, so this value would be dropped with nothing
		// said - and a test asserting on a view would pass against a method it did not ask for
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('REQUEST_METHOD in $server does nothing');

		$this->dispatch('help/terms', 'public', [], ['REQUEST_METHOD' => 'POST']);
	}

	public function test_the_refusal_says_what_to_do_instead()
	{
		try
		{
			$this->dispatch('help/terms', 'public', [], ['REQUEST_METHOD' => 'POST']);
			$this->fail('a REQUEST_METHOD server value should have been refused');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('Pass it as $method instead', $e->getMessage());
		}
	}

	public function test_dispatching_without_a_csrf_token_refuses_it_too()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('REQUEST_METHOD in $server does nothing');

		$this->dispatchWithoutCsrfToken('help/terms', 'public', [], ['REQUEST_METHOD' => 'GET']);
	}

	public function test_calling_an_action_refuses_it_too()
	{
		// callAction() takes $method and $server as well, so a guard on dispatch() alone would
		// leave this entry point carrying the same hazard
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('REQUEST_METHOD in $server does nothing');

		$this->callAction('XF:Notice', 'save', 'admin', [], [], 'POST', ['REQUEST_METHOD' => 'GET']);
	}

	public function test_call_action_sends_the_server_values_too()
	{
		// a GET, so assertPostOnly() refuses before anything is saved
		$reply = $this->callAction('XF:Notice', 'save', 'admin', [], [], 'GET', [
			'REMOTE_ADDR' => '203.0.113.8',
			'HTTP_USER_AGENT' => 'TestAgent/2.0',
		]);

		$this->assertReplyIsError($reply, 405);
		$this->assertSame('203.0.113.8', $this->app()->request()->getIp());
		$this->assertSame('TestAgent/2.0', $this->app()->request()->getUserAgent());
	}
}
