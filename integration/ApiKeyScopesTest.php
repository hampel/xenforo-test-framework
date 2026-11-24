<?php

namespace Hampel\Testing\Integration;

use XF\Api\Mvc\Reply\ApiResult;

/**
 * A key that allows all scopes carries every scope the forum defines by name as well, because
 * XenForo 2.3.0 answers hasScope() from the scopes array alone and never reads the flag.
 *
 * The assertions read the array as that version does, so they hold whichever XenForo runs them.
 */
class ApiKeyScopesTest extends TestCase
{
	public function test_the_default_key_holds_every_scope_by_name()
	{
		$defined = $this->app()->db()->fetchAllColumn('SELECT api_scope_id FROM xf_api_scope');
		$this->assertContains('user:read', $defined);

		$key = $this->actingAsApiKey();

		$this->assertTrue($key->allow_all_scopes);
		$this->assertEqualsCanonicalizing($defined, array_keys($key->scopes));
		$this->assertNotEmpty($key->scopes['user:read']);
		$this->assertTrue($key->hasScope('user:read'));
	}

	public function test_a_key_asked_to_allow_all_scopes_holds_them_too()
	{
		$key = $this->actingAsApiKey(['is_super_user' => false, 'allow_all_scopes' => true]);

		$this->assertNotEmpty($key->scopes['user:read']);
	}

	public function test_scopes_that_are_given_are_left_as_given()
	{
		$key = $this->actingAsApiKey(['scopes' => ['node:read']]);

		$this->assertSame(['node:read' => true], $key->scopes);
	}

	public function test_a_key_that_does_not_allow_all_scopes_holds_none()
	{
		$key = $this->actingAsApiKey(['allow_all_scopes' => false]);

		$this->assertSame([], $key->scopes);
		$this->assertFalse($key->hasScope('user:read'));
	}

	public function test_the_default_key_passes_a_scoped_route()
	{
		$this->actingAsMember([], ['general' => ['view' => true]]);

		// the control: the fallback key holds no scope, and the route says which one it wanted
		$this->assertReplyIsError($this->dispatch('nodes', 'api'), 403, 'node:read');

		$this->actingAsApiKey();

		$this->assertInstanceOf(ApiResult::class, $this->dispatch('nodes', 'api'));
	}
}
