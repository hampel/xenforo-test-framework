<?php

namespace Hampel\Testing\Integration\Job;

use XF\Job\AbstractJob;
use XF\Job\JobResult;

/**
 * Reports failure through its result, which JobResult::$completed also counts as complete.
 */
class FailingJob extends AbstractJob
{
	public function run($maxRunTime)
	{
		return JobResult::newFailed($this->jobId, $this->data, new \Exception('probe failure'));
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
