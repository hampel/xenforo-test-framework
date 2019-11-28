<?php namespace Hampel\Testing\Concerns;

use Carbon\Carbon;

trait InteractsWithTime
{
	private $time;

	private $containerTime;

	protected function setUpTime()
	{
        $this->beforeApplicationDestroyed(function () {
            $this->restoreTime();
        });
	}

	/**
	 * Allow us to set an arbitrary execution time for `\XF::$time`, with no side effects - time is reset after each
	 * individual test is run.
	 *
	 * This is especially useful when dealing with time intervals based on the script execution time. It becomes even
	 * more useful when combined with the Carbon library, since time intervals become very easy to manipulate.
	 *
	 * @param $time - timestamp to set
	 */
	protected function setTestTime($time)
	{
		$this->time = \XF::$time;
		$this->containerTime = $this->app['time.granular'];

		if ($time instanceof Carbon)
		{
			Carbon::setTestNow($time);
			\XF::$time = $time->timestamp;
			$this->swap('time', $time->timestamp);
			$this->swap('time.granular', $time->format('U.u'));
		}
		else
		{
			// developer will need to call Carbon::setTestNow themselves!

			\XF::$time = intval($time);
			$this->swap('time', intval($time));
			$this->swap('time.granular', floatval($time));
		}
	}

	private function restoreTime()
	{
		if ($this->time)
		{
			\XF::$time = $this->time;
		}

		if ($this->containerTime)
		{
			$this->swap('time', intval($this->containerTime));
			$this->swap('time.granular', $this->containerTime);
		}
	}
}
