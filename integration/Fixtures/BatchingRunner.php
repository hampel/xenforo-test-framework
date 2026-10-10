<?php

namespace Hampel\Testing\Integration\Fixtures;

use XF\MultiPartRunnerTrait;

/**
 * A multi-part runner shaped like XenForo's, for testing runRunnerStep() without deleting
 * anything: stepBatched() processes a fixed number of items per call and asks to be resumed while
 * the batch it took was full, which is what XF's own clean-up steps do when they fetch their limit.
 */
class BatchingRunner
{
	use MultiPartRunnerTrait;

	public $processed = [];

	public $plainStepCalls = 0;

	public $lateStepCalls = 0;

	/** Steps added after construction, as a listener on the runner's own event would add them */
	public $extraSteps = [];

	public $batchSize = 3;

	protected $items;

	public function __construct(array $items)
	{
		$this->items = $items;
	}

	protected function getSteps()
	{
		return array_merge(['stepPlain', 'stepBatched', 'stepStuck'], $this->extraSteps);
	}

	protected function stepPlain()
	{
		$this->plainStepCalls++;

		return null;
	}

	protected function stepBatched($lastOffset, $maxRunTime)
	{
		$start = $lastOffset ?? 0;
		$batch = array_slice($this->items, $start, $this->batchSize);

		if (!$batch)
		{
			return null;
		}

		foreach ($batch AS $item)
		{
			$this->processed[] = $item;
		}

		// a full batch means there may be more, exactly as a finder fetching its limit does
		return count($batch) == $this->batchSize ? $start + count($batch) : null;
	}

	protected function stepStuck($lastOffset, $maxRunTime)
	{
		return 7;
	}

	protected function stepLate()
	{
		$this->lateStepCalls++;

		return null;
	}
}
