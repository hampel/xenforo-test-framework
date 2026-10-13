<?php

namespace Hampel\Testing\Integration;

use PHPUnit\Framework\ExpectationFailedException;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

/**
 * followRedirect(), which dispatches the route a redirect points at.
 *
 * The url forms are the interesting part: the control panel has its own script, the api is routed
 * through the public one behind a prefix, and the friendly-urls option changes the public and api
 * forms but not the admin one - so the type and the route path have to be read out of the url
 * rather than carried over from the dispatch that produced the redirect.
 */
class FollowRedirectTest extends TestCase
{
	public function test_a_canonical_redirect_is_followed_to_the_page()
	{
		$this->actingAsMember();
		$this->setVisitorPermissions(\XF::visitor(), ['general' => ['view' => true]]);

		// a member route without the username is answered with its canonical form
		$reply = $this->dispatch('members/1');
		$this->assertReplyIsRedirect($reply, null, Redirect::PERMANENT);

		$followed = $this->followRedirect($reply);

		$this->assertReplyIsView($followed);
	}

	public function test_the_route_type_is_read_from_the_url_rather_than_carried_over()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		// built rather than dispatched for, because what is under test is the url shape: the
		// control panel's own script is how an admin redirect is told from a public one
		$followed = $this->followRedirect(new Redirect('/admin.php?tools/'));

		$this->assertReplyTemplate($followed, 'admin_section');
	}

	public function test_an_api_url_is_recognised_behind_the_public_script()
	{
		$this->actingAsApiKey(['is_super_user' => true]);

		$followed = $this->followRedirect(new Redirect('/index.php?api/index'));

		$this->assertReplyIsApiResult($followed);
	}

	public function test_the_friendly_form_of_each_type_names_the_same_route()
	{
		$this->actingAsApiKey(['is_super_user' => true]);

		// no script in the path, so the route is the path and the whole query is input
		$followed = $this->followRedirect(new Redirect('/api/index'));

		$this->assertReplyIsApiResult($followed);
	}

	public function test_the_input_in_the_url_reaches_the_dispatch()
	{
		$this->actingAsMember();
		$this->setVisitorPermissions(\XF::visitor(), ['general' => ['view' => true]]);

		$this->followRedirect(new Redirect('/index.php?help/terms/&zzprobe=a+b&page=2'));

		// dispatch() swaps its request into the container, so this is the request the controller saw
		$request = $this->app()->request();

		$this->assertSame('a b', $request->filter('zzprobe', 'str'));
		$this->assertSame(2, $request->filter('page', 'uint'));
	}

	public function test_the_friendly_form_treats_the_whole_query_as_input()
	{
		$this->actingAsMember();
		$this->setVisitorPermissions(\XF::visitor(), ['general' => ['view' => true]]);

		// no script, so there is no route in the query to split off - two parameters, because one
		// would survive a parse that wrongly stopped at the first '&'
		$this->followRedirect(new Redirect('/help/terms/?zzprobe=a+b&page=2'));

		$request = $this->app()->request();

		$this->assertSame('a b', $request->filter('zzprobe', 'str'));
		$this->assertSame(2, $request->filter('page', 'uint'));
	}

	public function test_the_board_index_is_followed_although_its_url_has_no_query()
	{
		$this->actingAsMember();
		$this->setVisitorPermissions(\XF::visitor(), ['general' => ['view' => true]]);

		$followed = $this->followRedirect(new Redirect('/index.php'));

		$this->assertInstanceOf(View::class, $followed);
	}

	public function test_a_fragment_is_no_part_of_the_route()
	{
		$admin = $this->actingAsMember(['is_admin' => true]);
		$this->setVisitorAdminPermissions($admin, ['option' => true]);

		// what an admin save really redirects to, fragment and all, so that a parse done by string
		// manipulation rather than by parse_url() would carry the fragment into the route path
		$followed = $this->followRedirect(new Redirect('/admin.php?tools/#__1602'));

		$this->assertReplyTemplate($followed, 'admin_section');
	}

	public function test_a_reply_that_is_not_a_redirect_is_refused()
	{
		$this->actingAsMember();
		$this->setVisitorPermissions(\XF::visitor(), ['general' => ['view' => true]]);

		$view = $this->dispatch('');
		$this->assertReplyIsView($view);

		$this->expectException(ExpectationFailedException::class);
		$this->expectExceptionMessage('only a redirect reply has a url to follow');

		$this->followRedirect($view);
	}

	public function test_a_redirect_off_the_forum_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('which is not this forum');

		$this->followRedirect(new Redirect('https://example.com/somewhere'));
	}
}
