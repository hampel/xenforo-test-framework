<?php namespace Hampel\Testing\Mail;

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

    protected function doSend(SentMessage $message): void
    {
        $this->sentEmails[] = $message->getOriginalMessage();
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
