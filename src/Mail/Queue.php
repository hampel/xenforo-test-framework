<?php namespace Hampel\Testing\Mail;

use XF\Db\AbstractAdapter;
use XF\Mail\Queue as BaseQueue;

class Queue extends BaseQueue
{
	protected $queuedEmails = [];

	public function __construct(AbstractAdapter $db)
	{
	}

	public function queue(\Swift_Mime_SimpleMessage $message)
	{
		$this->queuedEmails[] = $message;

		return true;
	}

	public function queueForRetry(\Swift_Mime_SimpleMessage $message, $queueEntry)
	{
	}

	protected function enqueueJob($triggerDate = null)
	{
	}

	public function run($maxRunTime)
	{
	}

	public function getQueuedEmails()
	{
		return $this->queuedEmails;
	}

	public function getQueue($limit = 20)
	{
		$queue = $this->queuedEmails;

		return array_slice($queue, 0, $limit);
	}

	public function hasMore(&$nextSendDate = null)
	{
		return count($this->queuedEmails) > 0;
	}
}