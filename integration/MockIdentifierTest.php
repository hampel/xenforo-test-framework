<?php

namespace Hampel\Testing\Integration;

use XF\Repository\UserRepository;
use XF\Service\User\EmailStopService;

/**
 * The two mock helpers that used to accept an identifier, do nothing useful with it, and let the
 * test pass anyway. Both failures were silent, which is the only reason they cost anyone time.
 */
class MockIdentifierTest extends TestCase
{
	/**
	 * The spelling that always worked. Here so the normalisation cannot be "fixed" by breaking it.
	 */
	public function test_the_canonical_short_name_still_resolves()
	{
		$this->mockRepository('XF:User', function ($mock)
		{
			$mock->expects()->getGuestUser()->andReturn('mocked');
		});

		$this->assertSame('mocked', $this->app()->repository('XF:User')->getGuestUser());
	}

	/**
	 * XF 2.3 named the class UserRepository, so this is a natural thing to write - and getRepository()
	 * trims the suffix before it looks the mock up, so before normalisation the mock sat under a key
	 * nothing consulted and the real repository ran.
	 */
	public function test_a_suffixed_short_name_reaches_the_same_mock()
	{
		$this->mockRepository('XF:UserRepository', function ($mock)
		{
			$mock->expects()->getGuestUser()->andReturn('mocked');
		});

		$this->assertSame('mocked', $this->app()->repository('XF:User')->getGuestUser());
	}

	/** Same again for a full class name, which getRepository() also accepts. */
	public function test_a_full_class_name_reaches_the_same_mock()
	{
		$this->mockRepository(UserRepository::class, function ($mock)
		{
			$mock->expects()->getGuestUser()->andReturn('mocked');
		});

		$this->assertSame('mocked', $this->app()->repository('XF:User')->getGuestUser());
	}

	/**
	 * mockService() must resolve through the class alias map before checking the class exists: XF 2.3
	 * renamed the service to EmailStopService, so the name the short form builds does not exist on
	 * disk even though the short form is correct.
	 */
	public function test_a_service_short_name_mocks_the_class_xf_would_build()
	{
		$this->mockService('XF:User\EmailStop', function ($mock)
		{
			$mock->expects()->stop('list')->once();
		});

		$service = $this->app()->service('XF:User\EmailStop');

		$this->assertInstanceOf(EmailStopService::class, $service);

		$service->stop('list');
	}

	/**
	 * The one that used to pass while asserting against nothing: Mockery builds an untyped double
	 * for a class name that resolves to nothing at all, so every expectation was met by a fiction.
	 */
	public function test_a_service_that_does_not_exist_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Could not find service');

		$this->mockService('XF:User\NoSuchServiceExistsHere');
	}
}
