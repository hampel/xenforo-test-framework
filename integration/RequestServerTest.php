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

	public function test_the_request_method_is_not_overridden_by_a_server_value()
	{
		$this->dispatch('help/terms', 'public', [], ['REQUEST_METHOD' => 'POST']);

		$this->assertFalse($this->app()->request()->isPost());
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
