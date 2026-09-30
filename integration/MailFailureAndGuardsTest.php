<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Mailer\Exception\TransportException;
use XF\Finder\UserFinder;
use XF\Mvc\Reply\View;

/**
 * A reply assertion given a value where its failure message goes, a mail send made to fail, and a
 * finder short name XenForo cannot turn into a class.
 */
class MailFailureAndGuardsTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function viewReply()
	{
		return new View('XF:Tools\Index', 'admin:tools', ['results' => [1, 2, 3]]);
	}

	private function newMail()
	{
		return $this->app()->mailer()->newMail()
			->setTo('probe@example.com', 'Probe')
			->setContent('Probe subject', '<p>Probe body</p>');
	}

	public function test_a_value_where_the_failure_message_goes_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('failure message, not an expected value');

		// passes silently without the guard: nothing compares the third argument
		$this->assertReplyParam($this->viewReply(), 'results', true);
	}

	public function test_the_refusal_covers_the_other_reply_assertions()
	{
		$this->expectException(\LogicException::class);

		$this->assertReplyTemplate($this->viewReply(), 'admin:tools', ['not', 'a', 'message']);
	}

	public function test_a_failure_message_is_still_accepted_and_still_reported()
	{
		$this->assertReplyParam($this->viewReply(), 'results', 'a string message is fine');

		try
		{
			$this->assertReplyParam($this->viewReply(), 'absent', 'my own message');
			$this->fail('the assertion should have failed on the absent key');
		}
		catch (ExpectationFailedException $e)
		{
			$this->assertStringContainsString('my own message', $e->getMessage());
		}
	}

	public function test_a_failing_send_returns_false_and_logs_rather_than_recording_mail()
	{
		$this->fakesErrors();
		$transport = $this->fakesMail();
		$transport->failWith(new TransportException('smtp is down'));

		$this->assertFalse($this->newMail()->send(), 'the mailer should report the send failed');
		$this->assertMailNotSent();
		// the callback is handed the logged entry, not the throwable - 'raw_exception' holds that
		$this->assertExceptionLogged(TransportException::class, function (array $entry)
		{
			return strpos($entry['message'], 'smtp is down') !== false;
		});
	}

	public function test_the_same_send_succeeds_without_the_failure()
	{
		$this->fakesErrors();
		$this->fakesMail();

		// a successful send answers Symfony's SentMessage, not true - only failure is false
		$this->assertNotFalse($this->newMail()->send());
		$this->assertMailSent();
	}

	public function test_a_failure_can_be_lifted()
	{
		$this->fakesErrors();
		$transport = $this->fakesMail();

		$transport->failWith();
		$this->assertFalse($this->newMail()->send());

		$transport->sendsSuccessfully();
		$this->assertNotFalse($this->newMail()->send());
		$this->assertMailSent();
	}

	public function test_a_finder_short_name_of_the_wrong_shape_is_refused_by_name()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("'entity id' is neither");

		$this->mockFinder('entity id');
	}

	public function test_a_well_formed_short_name_still_mocks_its_finder()
	{
		$finder = $this->mockFinder('XF:User');

		$this->assertInstanceOf(UserFinder::class, $finder);
	}
}
