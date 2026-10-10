<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Integration\Fixtures\BatchingRunner;

/**
 * runRunnerStep() runs one step of a XenForo multi-part runner to completion.
 *
 * The step's return value is a resume offset rather than a result, and a step asks to be resumed
 * when it batches as well as when it runs out of time - so a single call can leave work undone.
 */
class RunnerStepTest extends TestCase
{
	public function test_a_batching_step_runs_to_completion()
	{
		$runner = new BatchingRunner(range(1, 10));

		$calls = $this->runRunnerStep($runner, 'stepBatched');

		$this->assertSame(range(1, 10), $runner->processed);

		// ten items, three per call: four calls, the last one short
		$this->assertSame(4, $calls);
	}

	public function test_a_single_call_leaves_work_undone()
	{
		$runner = new BatchingRunner(range(1, 10));

		// what calling the step directly does, which is why the helper loops
		$step = new \ReflectionMethod($runner, 'stepBatched');
		$step->setAccessible(true);
		$offset = $step->invoke($runner, null, 0);

		$this->assertSame([1, 2, 3], $runner->processed);
		$this->assertSame(3, $offset, 'the step asked to be resumed rather than reporting it had finished');
	}

	public function test_a_step_taking_no_arguments_runs()
	{
		$runner = new BatchingRunner([]);

		$this->assertSame(1, $this->runRunnerStep($runner, 'stepPlain'));
		$this->assertSame(1, $runner->plainStepCalls);
	}

	public function test_a_step_added_after_construction_is_selectable()
	{
		$runner = new BatchingRunner([]);
		$runner->extraSteps = ['stepLate'];

		$this->runRunnerStep($runner, 'stepLate');

		$this->assertSame(1, $runner->lateStepCalls);
	}

	public function test_a_name_the_runner_does_not_list_is_refused()
	{
		$runner = new BatchingRunner([]);
		$runner->extraSteps = ['stepLate'];

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("does not run a step named 'stepNope'");

		$this->runRunnerStep($runner, 'stepNope');
	}

	public function test_the_refusal_lists_the_steps_the_runner_does_run()
	{
		$runner = new BatchingRunner([]);

		try
		{
			$this->runRunnerStep($runner, 'stepNope');
			$this->fail('an unknown step should have been refused');
		}
		catch (\LogicException $e)
		{
			$this->assertStringContainsString('stepPlain, stepBatched, stepStuck', $e->getMessage());
		}
	}

	public function test_a_step_that_never_advances_is_refused()
	{
		$runner = new BatchingRunner([]);

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('asked to resume from the position it was already given');

		$this->runRunnerStep($runner, 'stepStuck');
	}

	public function test_a_step_that_keeps_going_is_refused_rather_than_looping()
	{
		// a batch size of zero makes every call return the next position, so it never finishes
		$runner = new BatchingRunner(range(1, 10));
		$runner->batchSize = 1;

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('had not finished after 3 calls');

		$this->runRunnerStep($runner, 'stepBatched', 3);
	}

	public function test_something_that_is_not_a_runner_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is not a multi-part runner');

		$this->runRunnerStep(new \stdClass(), 'stepAnything');
	}
}
