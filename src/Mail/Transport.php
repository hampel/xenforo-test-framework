<?php namespace Hampel\Testing\Mail;

class Transport implements \Swift_Transport
{
	/** The event dispatcher from the plugin API */
	private $_eventDispatcher;

    /**
     * All of the emails that have been sent.
     *
     * @var array
     */
    protected $sentEmails = [];


	/**
	 * Create a new MailTransport with the $log.
	 *
	 * @param \Swift_Events_EventDispatcher $eventDispatcher
	 */
	public function __construct(\Swift_Events_EventDispatcher $eventDispatcher)
	{
		$this->_eventDispatcher = $eventDispatcher;
	}

	/**
	 * Not used.
	 */
	public function isStarted()
	{
		return false;
	}
	
	/**
	 * Not used.
	 */
	public function start()
	{
	}
	
	/**
	 * Not used.
	 */
	public function stop()
	{
	}
	
	public function send(\Swift_Mime_Message $message, &$failedRecipients = null)
	{
		$failedRecipients = (array) $failedRecipients;

		if ($evt = $this->_eventDispatcher->createSendEvent($this, $message))
		{
			$this->_eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
			if ($evt->bubbleCancelled())
			{
				return 0;
			}
		}

		$count = (
			count((array) $message->getTo())
			+ count((array) $message->getCc())
			+ count((array) $message->getBcc())
		);

		$toHeader = $message->getHeaders()->get('To');
		if (!$toHeader)
		{
			throw new \Swift_TransportException('Cannot send message without a recipient');
		}

		$this->sentEmails[] = $message;

		return $count;
	}

	/**
	 * Register a plugin.
	 *
	 * @param \Swift_Events_EventListener $plugin
	 */
	public function registerPlugin(\Swift_Events_EventListener $plugin)
	{
		$this->_eventDispatcher->bindEventListener($plugin);
	}

	public function getSentEmails()
	{
		return $this->sentEmails;
	}
}
