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
        $subjectHeader = $message->getOriginalMessage()->getHeaders()->get('Subject');
        $subject = $subjectHeader ? $subjectHeader->getBody() : '';
        $subject = preg_replace('#[^a-z0-9_ -]#', '', strtolower($subject));
        $subject = strtr($subject, ' ', '-');
        $subject = substr($subject, 0, 30);

        $this->sentEmails = $message->toString();
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
