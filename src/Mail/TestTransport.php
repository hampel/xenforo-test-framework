<?php

namespace Hampel\Testing\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class TestTransport extends AbstractTransport
{
	/**
	 * All of the emails that have been sent.
	 *
	 * @var array
	 */
	protected $sentEmails = [];

	/**
	 * What doSend() should throw instead of recording the message, if anything.
	 *
	 * @var \Throwable|null
	 */
	protected $failure = null;

	protected function doSend(SentMessage $message): void
	{
		if ($this->failure !== null)
		{
			throw $this->failure;
		}

		$this->sentEmails[] = $message->getOriginalMessage();
	}

	/**
	 * Make every send from here on fail, for testing what the code under test does about it.
	 *
	 * XenForo's mailer catches whatever the transport throws, logs it and returns false - so the
	 * code under test sees a false return, and the exception reaches the error log. Install
	 * `fakesErrors()` alongside this, or that row is written to the forum's real error log.
	 *
	 * A failing send records nothing, so the mail assertions see no mail.
	 *
	 * @param \Throwable|null $failure - null makes sends succeed again
	 *
	 * @return $this
	 */
	public function failWith(?\Throwable $failure = null)
	{
		$this->failure = $failure ?: new TransportException('Mail sending failed - installed by failWith()');

		return $this;
	}

	/**
	 * Undo failWith().
	 *
	 * @return $this
	 */
	public function sendsSuccessfully()
	{
		$this->failure = null;

		return $this;
	}

	public function __toString(): string
	{
		return 'test://';
	}

	public function getSentEmails()
	{
		return $this->sentEmails;
	}
}
