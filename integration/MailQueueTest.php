<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Mail\TestTransport;
use Symfony\Component\Mime\Address;

/**
 * fakesMail() against queue() rather than send(). This is the gap that let the mail bugs ship: the
 * package had no mail test at all, and send() happens to work while queue() - which is what batch
 * and job code actually calls - did not.
 */
class MailQueueTest extends TestCase
{
	private function newMail()
	{
		return $this->app()->mailer()->newMail()
			->setTo('recipient@example.com', 'Recipient')
			->setContent('Test subject', '<p>Test body</p>');
	}

	public function test_send_reaches_the_test_transport()
	{
		$this->fakesMail();

		$this->newMail()->send();

		$this->assertMailSent();
		$this->assertMailSentTimes(1);
	}

	/**
	 * queue() used to enqueue a MailSend job the transport never saw, so this failed with "The
	 * expected mail was not sent." - enableMailQueue is a config value and fakesMail() was setting
	 * an option of that name, which XenForo has never had.
	 */
	public function test_queue_reaches_the_test_transport_too()
	{
		$this->fakesMail();
		$this->fakesJobs();

		$this->newMail()->queue();

		$this->assertMailSent();
		$this->assertNoJobsQueued();
	}

	/**
	 * The transport and the queue flag are both constructor arguments of XF\Mail\Mailer, and `mailer`
	 * is its own cached container entry - so resolving it before fakesMail() used to leave the fake
	 * installed somewhere nothing looked.
	 */
	public function test_a_mailer_resolved_before_the_fake_is_rebuilt()
	{
		$this->app()->mailer();

		$this->fakesMail();

		$this->newMail()->queue();

		$this->assertMailSent();
	}

	/** Same reasoning, for a second fake in one test. */
	public function test_a_second_fake_replaces_the_first()
	{
		$first = $this->fakesMail();
		$this->newMail()->queue();
		$this->assertMailSentTimes(1);

		$second = $this->fakesMail();
		$this->assertNotSame($first, $second);

		$this->newMail()->queue();

		$this->assertMailSentTimes(1);
		$this->assertCount(1, $second->getSentEmails());
	}

	public function test_the_fake_is_returned_and_is_the_one_in_the_container()
	{
		$transport = $this->fakesMail();

		$this->assertInstanceOf(TestTransport::class, $transport);
		$this->assertSame($transport, $this->app()->container()['mailer.transport']);
	}

	/**
	 * Captured mail is a Symfony\Component\Mime\Email, so getTo() gives Address objects rather than
	 * the old Swiftmailer `email => name` map. Assertions written against the map fail as "mail was
	 * not sent" rather than as a type error, so it is worth pinning the shape down.
	 */
	public function test_captured_mail_exposes_symfony_addresses()
	{
		$this->fakesMail();

		$this->newMail()->queue();

		$sent = $this->getSentMail();
		$this->assertCount(1, $sent);

		$to = $sent[0]->getTo();
		$this->assertInstanceOf(Address::class, $to[0]);
		$this->assertSame('recipient@example.com', $to[0]->getAddress());
		$this->assertSame('Recipient', $to[0]->getName());
		$this->assertSame('Test subject', $sent[0]->getSubject());
	}

	public function test_the_truth_test_callback_receives_the_message()
	{
		$this->fakesMail();

		$this->newMail()->queue();

		$this->assertMailSent(function ($mail)
		{
			return $mail->getTo()[0]->getAddress() == 'recipient@example.com';
		});

		$this->assertMailNotSent(function ($mail)
		{
			return $mail->getTo()[0]->getAddress() == 'someone.else@example.com';
		});
	}
}
