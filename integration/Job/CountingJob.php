<?php

namespace Hampel\Testing\Integration\Job;

use XF\Job\AbstractJob;

/**
 * Keeps its position in $this->data, so it resumes correctly from a fresh instance. Counts its
 * passes and the runOnce work it queues in statics the test reads.
 */
class CountingJob extends AbstractJob
{
	/** @var int */
	public static $passes = 0;

	/** @var int */
	public static $deferredRuns = 0;

	protected $defaultData = [
		'position' => 0,
		'target' => 3,
	];

	public function run($maxRunTime)
	{
		static::$passes++;

		\XF::runOnce('countingJobProbe' . static::$passes, function ()
		{
			static::$deferredRuns++;
		});

		$this->data['position']++;

		return $this->data['position'] >= $this->data['target'] ? $this->complete() : $this->resume();
	}

	public function getStatusMessage()
	{
		return '';
	}

	public function canCancel()
	{
		return false;
	}

	public function canTriggerByChoice()
	{
		return false;
	}
}
