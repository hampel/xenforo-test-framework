<?php namespace Hampel\Testing\Job;

use XF\Job\JobParams;
use XF\Job\Manager as BaseManager;

class Manager extends BaseManager
{
	protected $enqueuedJobs = [];

	public function __construct(\XF\App $app, $allowManual = true, $forceManual = false)
	{
		$this->app = $app;
	}

	/**
	 * @param bool $manual
	 * @param float $maxRunTime
	 *
	 * @return null|JobResult
	 */
	public function runQueue($manual, $maxRunTime)
	{
	}

	/**
	 * @param array $ids
	 * @param $maxRunTime
	 * @return array
	 */
	public function runByIds(array $ids, $maxRunTime)
	{
	}

	/**
	 * @param string $key
	 * @param float $maxRunTime
	 *
	 * @return null|JobResult
	 */
	public function runUnique($key, $maxRunTime)
	{
	}

	public function runById($id, $maxRunTime)
	{
	}

	public function queuePending($manual)
	{
		return false;
	}

	/**
	 * @param array $job
	 * @param int $maxRunTime
	 *
	 * @return JobResult
	 */
	public function runJobEntry(array $job, $maxRunTime)
	{
	}

	public function handleShutdown()
	{
	}

	public function cancelJob(array $job)
	{
	}

	public function cancelUniqueJob($uniqueId)
	{
	}

    public function cancelAndDequeueJob(array $job): void
    {
    }

	public function getRunnable($manual)
	{
		// TODO: use this to return jobs from our queue?
	}

	public function getFirstRunnable($manual)
	{
		// TODO: use this to return jobs from our queue?
	}

	public function hasStoppedManualJobs()
	{
		// TODO: use this to return information on jobs in our queue?
	}

	public function getJob($id)
	{
		return $this->enqueuedJobs[$id];
	}

	public function getUniqueJob($key)
	{
		return
			isset($this->uniqueEnqueued[$key])
			? $this->getJob($this->uniqueEnqueued[$key])
			: null;
	}

	public function getFirstAutomaticTime()
	{
	}

	public function updateNextRunTime()
	{
	}

	public function setNextAutoRunTime($time)
	{
	}

	public function scheduleRunTimeUpdate()
	{
	}

	/**
	 * @param string|null $uniqueId
	 * @param string $jobClass
	 * @param array $params
	 * @param bool $manual
	 * @param int|null $runTime
	 * @param bool $blocking If auto, this job can be set as blocking which will change the UI for the triggerer
	 *
	 * @return int|null ID of the enqueued job (or null if an error happened)
	 */
    protected function _enqueue(JobParams $jobParams): ?int
	{
        $uniqueId = $jobParams->getUniqueId();
		if ($uniqueId)
		{
			if (strlen($uniqueId) > 50)
			{
				$uniqueId = md5($uniqueId);
                $jobParams->setUniqueId($uniqueId);
			}

			if (isset($this->uniqueEnqueued[$uniqueId]))
			{
				return $this->uniqueEnqueued[$uniqueId];
			}
		}
		else
		{
			$uniqueId = null;
		}

        $manual = $jobParams->isManual();
        if ($this->forceManual)
        {
            $manual = true;
        }
        else if (!$this->allowManual)
        {
            $manual = false;
        }
        $jobParams->setManual($manual);

        $runTime = $jobParams->getRunTime();
        if (!$runTime)
        {
            $runTime = \XF::$time;
            $jobParams->setRunTime($runTime);
        }

        $jobParams = $this->prepareJobParams($jobParams);

		$job = [
			'execute_class' => $jobParams->getJobClass(),
			'execute_data' => $jobParams->getParams(),
			'unique_key' => $jobParams->getUniqueId(),
			'manual_execute' => $jobParams->isManual(),
			'trigger_date' => $jobParams->getRunTime()
		];

		$this->enqueuedJobs[] = $job;
		end($this->enqueuedJobs);
		$id = key($this->enqueuedJobs);
		reset($this->enqueuedJobs);

		if ($uniqueId)
		{
			$this->uniqueEnqueued[$uniqueId] = $id;
		}

		if ($manual)
		{
			$this->manualEnqueuedList[$id] = $id;
		}
		else
		{
            $blocking = $jobParams->isBlocking();
			if ($blocking)
			{
				$this->autoBlockingList[$id] = $id;
			}
			$this->autoEnqueuedList[$id] = $id;
		}

		return $id;
	}

	public function getQueuedJobs()
	{
		return $this->enqueuedJobs;
	}
}
