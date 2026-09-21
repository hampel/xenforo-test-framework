<?php

namespace Hampel\Testing\Integration\Job;

use XF\Job\AbstractJob;

class ThrowingJob extends AbstractJob
{
	public function run($maxRunTime)
	{
		throw new \RuntimeException('probe exception');
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
