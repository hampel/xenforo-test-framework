<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\DataRegistry;

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
