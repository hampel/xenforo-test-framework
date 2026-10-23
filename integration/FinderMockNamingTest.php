<?php

namespace Hampel\Testing\Integration;

use Mockery\MockInterface;
use XF\Entity\User;
use XF\Finder\UserFinder;
use XF\Mvc\Entity\Finder;

/**
 * A finder mock applies whichever of XenForo's three spellings registered it and whichever the code
 * under test looks it up by. XenForo's getFinder() normalises its argument before it looks anything
 * up, and core itself asks for a finder by class - so a mock keyed on the raw string it was
 * registered with is bypassed in silence by any code written in core's own idiom.
 */
class FinderMockNamingTest extends TestCase
{
	public function test_a_mock_registered_by_short_name_is_found_by_finder_class()
	{
		$mock = $this->mockFinder('XF:User');

		$this->assertSame($mock, $this->app()->finder(UserFinder::class));
	}

	public function test_a_mock_registered_by_short_name_is_found_by_entity_class()
	{
		$mock = $this->mockFinder('XF:User');

		$this->assertSame($mock, $this->app()->finder(User::class));
	}

	public function test_a_mock_registered_by_finder_class_is_found_by_short_name()
	{
		$mock = $this->mockFinder(UserFinder::class);

		$this->assertInstanceOf(UserFinder::class, $mock);
		$this->assertSame($mock, $this->app()->finder('XF:User'));
	}

	public function test_a_mock_registered_by_entity_class_is_found_by_every_spelling()
	{
		$mock = $this->mockFinder(User::class);

		$this->assertInstanceOf(UserFinder::class, $mock);
		$this->assertSame($mock, $this->app()->finder('XF:User'));
		$this->assertSame($mock, $this->app()->finder(UserFinder::class));
		$this->assertSame($mock, $this->app()->finder(User::class));
	}

	public function test_a_second_registration_under_another_spelling_returns_the_first_mock()
	{
		$first = $this->mockFinder('XF:User');

		$this->assertSame($first, $this->mockFinder(UserFinder::class));
		$this->assertSame($first, $this->mockFinder(User::class));
	}

	public function test_a_mock_for_one_entity_does_not_answer_for_another()
	{
		$this->mockFinder('XF:User');

		$this->assertInstanceOf(Finder::class, $this->app()->finder('XF:Thread'));
		$this->assertNotInstanceOf(MockInterface::class, $this->app()->finder('XF:Thread'));
	}

	public function test_mock_find_takes_the_entity_class()
	{
		$user = $this->makeEntity('XF:User', ['username' => 'probe']);
		$user->setTrusted('user_id', 99);

		$this->mockFind(User::class, 99, $user);

		$this->assertSame($user, $this->app()->em()->find('XF:User', 99));
	}

	public function test_mock_find_takes_the_finder_class()
	{
		$this->mockFind(UserFinder::class, 1, null);

		$this->assertNull($this->app()->em()->find(User::class, 1));
	}
}
