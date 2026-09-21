<?php

namespace Hampel\Testing\Integration\Job;

use XF\Job\AbstractJob;

/**
 * Keeps its position on the instance and not in the data it returns - so it completes if one
 * instance is run repeatedly, and never completes when each pass is a new instance, as in XenForo.
 */
class ForgetfulJob extends AbstractJob
{
	/** @var int */
	private $position = 0;

	public function run($maxRunTime)
	{
		$this->position++;

		return $this->position >= 3 ? $this->complete() : $this->resume();
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
