<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\DataRegistry;
use Symfony\Component\Cache\Adapter\AdapterInterface;

/**
 * Each of these reproduces a bug that shipped in 3.0.3 and was fixed in 4.0.0. Run against
 * released 3.0.3 they fail as noted; the point of keeping them is that all three needed a real
 * XenForo application to show up at all.
 */
class RegressionTest extends TestCase
{
	/** 3.0.3: the class could not be declared - XF 2.3 added : void to both methods */
	public function test_fakes_registry_round_trips()
	{
		$this->fakesRegistry();

		$registry = $this->app()->registry();
		$this->assertInstanceOf(DataRegistry::class, $registry);

		$registry->set('integrationProbe', ['a' => 1]);
		$this->assertSame(['a' => 1], $registry->get('integrationProbe'));

		$registry->delete('integrationProbe');
		$this->assertNull($registry->get('integrationProbe'));
	}

	/**
	 * delete() called $cache->delete() per key. Symfony's cache AdapterInterface - which is what
	 * XF\DataRegistry declares and type-hints - has no delete(); XF itself calls deleteItems().
	 * It went unnoticed because fakesRegistry() always passes a null cache so the branch never
	 * runs, and because some adapters (ArrayAdapter) happen to have a delete() anyway.
	 */
	public function test_delete_uses_the_cache_method_the_interface_actually_has()
	{
		$cache = \Mockery::mock(AdapterInterface::class);
		$cache->shouldReceive('deleteItems')->once()->with(\Mockery::type('array'));

		$registry = new DataRegistry($this->app()->db(), $cache);
		$registry->setFakeMode();

		$registry->delete('integrationProbe');
	}

	/** 3.0.3: TypeError - doSend() assigned a string over the array the assertions count */
	public function test_mail_assertions_survive_a_sent_message()
	{
		$this->fakesMail();
		$this->assertNoMailSent();

		$this->app()->mailer()->newMail()
			->setTo('probe@example.com')
			->setContent('Integration probe', '<p>body</p>')
			->send();

		$this->assertMailSent();
		$this->assertMailSentTimes(1);

		// the callback receives a Symfony\Component\Mime\Email - see DOCS.md
		$this->assertMailSent(function ($mail)
		{
			return $mail->getSubject() === 'Integration probe';
		});
	}

	/** 3.0.3: Call to undefined method assertJobsQueuedTimes() */
	public function test_job_assertions_including_the_numeric_shortcut()
	{
		$this->fakesJobs();
		$this->assertNoJobsQueued();

		$this->app()->jobManager()->enqueue('XF:UserRename', ['probe' => true]);

		$this->assertJobQueued('XF:UserRename');
		$this->assertJobQueuedTimes('XF:UserRename', 1);
		$this->assertJobQueued('XF:UserRename', 1);
	}
}
