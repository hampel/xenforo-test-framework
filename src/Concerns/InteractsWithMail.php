<?php namespace Hampel\Testing\Concerns;

use Hampel\Testing\Mail\Queue;
use Hampel\Testing\Mail\Transport;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\Container;

trait InteractsWithMail
{
	/**
	 * Allow us to assert that emails were (or were not) sent or queued as a result of executing our test code,
	 * without side-effects (ie no emails actually get sent).
	 */
	protected function fakesMail()
	{
		return $this->swap('mailer.transport', function (Container $c) {
			return new Transport(
				\Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher')
			);
		});

		$this->swap('mailer.queue', function(Container $c)
		{
			return new Queue($c['db']);
		});
	}

	protected function getMailTransport()
	{
		$transport = $this->app['mailer.transport'];
		if (!($transport instanceof Transport))
		{
			throw new \Exception("Test mailer transport not set up - call fakesMail() first");
		}
		return $transport;
	}

	/**
	 * Return an array of all sent emails
	 *
	 * @return \Swift_Mime_Message[]
	 * @throws \Exception
	 */
	protected function getSentMail()
	{
		return $this->getMailTransport()->getSentEmails();
	}

    /**
     * Assert if mail was sent based on a truth-test callback.
     *
     * @param  callable|int|null  $callback
     * @return void
     *
     * @throws \Exception
     */
    protected function assertMailSent($callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertMailSentTimes($callback);
        }

        $message = "The expected mail was not sent.";

        $queuedMail = $this->getQueuedMail();

        if (count($queuedMail) > 0) {
            $message .= ' Did you mean to use assertMailQueued() instead?';
        }

	    $sentMail = $this->sentMail($callback);

        PHPUnit::assertTrue(
            count($sentMail) > 0,
            $message
        );
    }

	/**
	 * Assert that email was sent a number of times.
	 *
	 * @param int $times
	 * @return void
	 *
	 * @throws \Exception
	 */
    protected function assertMailSentTimes($times = 1)
    {
    	$sentMail = $this->getSentMail();

        PHPUnit::assertTrue(
            ($count = count($sentMail)) === $times,
            "Mail was sent {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if mail was not sent based on a truth-test callback.
     *
     * @param  callable|null  $callback
     * @return void
     *
     * @throws \Exception
     */
    protected function assertMailNotSent($callback = null)
    {
	    $sentMail = $this->sentMail($callback);

        PHPUnit::assertTrue(
            count($sentMail) === 0,
            "Unexpected mail was sent."
        );
    }

    /**
     * Assert that no mail was sent.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertNoMailSent()
    {
    	$sentMail = $this->getSentMail();

        PHPUnit::assertEmpty($sentMail, 'Mail was sent unexpectedly.');
    }

    /**
     * Get all of the emails matching a truth-test callback.
     *
     * @param  callable|null  $callback
     * @return \Swift_Mime_Message[]
     *
     * @throws \Exception
     */
    private function sentMail($callback = null)
    {
        $callback = $callback ?: function () {
            return true;
        };

        $sentEmail = $this->getSentMail();

        return array_filter($sentEmail, function ($mail) use ($callback) {
            return $callback($mail);
        });
    }

    //----------------------------------------------------------------

	protected function getMailQueue()
	{
		$queue = $this->app['mailer.queue'];
		if (!($queue instanceof Queue))
		{
			throw new \Exception("Test mailer queue not set up - call fakesMail() first");
		}
		return $queue;
	}

	/**
	 * Return an array of all queued emails
	 *
	 * @return \Swift_Mime_Message[]
	 * @throws \Exception
	 */
	protected function getQueuedMail()
	{
		return $this->getMailQueue()->getQueuedEmails();
	}

    /**
     * Assert if mail was queued based on a truth-test callback.
     *
     * @param  callable|int|null  $callback
     * @return void
     *
     * @throws \Exception
     */
    protected function assertMailQueued($callback = null)
    {
        if (is_numeric($callback)) {
            return $this->assertMailQueuedTimes($callback);
        }

        $queuedMail = $this->queuedMail($callback);

        PHPUnit::assertTrue(
            count($queuedMail) > 0,
            "The expected mail was not queued."
        );
    }

	/**
	 * Assert that email was queued a number of times.
	 *
	 * @param int $times
	 * @return void
	 *
	 * @throws \Exception
	 */
    protected function assertMailQueuedTimes($times = 1)
    {
    	$queuedMail = $this->getQueuedMail();

        PHPUnit::assertTrue(
            ($count = count($queuedMail)) === $times,
            "Mail was queued {$count} times instead of {$times} times."
        );
    }

    /**
     * Determine if mail was not queued based on a truth-test callback.
     *
     * @param  callable|null  $callback
     * @return void
     *
     * @throws \Exception
     */
    protected function assertMailNotQueued($callback = null)
    {
	    $queuedMail = $this->queuedMail($callback);

        PHPUnit::assertTrue(
            count($queuedMail) === 0,
            "Unexpected mail was queued."
        );
    }

   /**
     * Assert that no mail was queued.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function assertNoMailQueued()
    {
    	$queuedMail = $this->getQueuedMail();

        PHPUnit::assertEmpty($queuedMail, 'Mail was queued unexpectedly.');
    }

    /**
     * Get all of the queued emails matching a truth-test callback.
     *
     * @param  callable|null  $callback
     * @return \Swift_Mime_Message[]
     *
     * @throws \Exception
     */
    private function queuedMail($callback = null)
    {
        $callback = $callback ?: function () {
            return true;
        };

        $queuedEmail = $this->getQueuedMail();

        return array_filter($queuedEmail, function ($mail) use ($callback) {
            return $callback($mail);
        });
    }
}
