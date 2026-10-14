<?php

namespace Hampel\Testing\Concerns;

use Hampel\Testing\Log\Recorder;
use PHPUnit\Framework\Assert as PHPUnit;

trait InteractsWithPsrLog
{
	/**
	 * The recorders this test installed, keyed by the container key each replaced.
	 *
	 * @var array<string, Recorder>
	 */
	private $logRecorders = [];

	/**
	 * Record what an add-on logs through a PSR-3 logger of its own, instead of writing it.
	 *
	 * fakesLogger() covers XenForo's moderator log. This is for the other kind: an add-on that
	 * puts a Psr\Log\LoggerInterface in the container under a key of its own and logs through
	 * that, where nothing the framework offered could see what was logged.
	 *
	 * Named recordsLog() rather than fakesLog(), which would sit one letter from fakesLogger()
	 * while doing something unrelated.
	 *
	 * The key is the add-on's, so it has to be given: the framework cannot know it.
	 *
	 * @param string $key - the container key holding the logger, eg 'myaddon.log'
	 *
	 * @return Recorder
	 */
	protected function recordsLog($key)
	{
		$this->requireLogKey($key);

		$recorder = new Recorder();

		$this->swap($key, $recorder);
		$this->logRecorders[$key] = $recorder;

		return $recorder;
	}

	/**
	 * Refuse a key that is not one.
	 *
	 * Its own method so the parameter can be honestly mixed: recordsLog() publishes a string, and
	 * the guard is for a caller who passes something else - most likely a property that was never
	 * assigned.
	 *
	 * @param mixed $key
	 *
	 * @return void
	 */
	private function requireLogKey($key)
	{
		if (is_string($key) && $key !== '')
		{
			return;
		}

		throw new \LogicException(
			'recordsLog() takes the container key your add-on keeps its logger under, eg'
			. " 'myaddon.log', and was given " . get_debug_type($key) . '. There is no default,'
			. ' because the key belongs to the add-on rather than to the framework.'
		);
	}

	/**
	 * The recorder installed over a container key.
	 *
	 * Refuses when there is none, rather than answering from the container - which would hand back
	 * the add-on's real logger and write whatever the test then exercised.
	 *
	 * @param string|null $key - omit it when the test installed exactly one
	 *
	 * @return Recorder
	 */
	protected function getLogRecorder($key = null)
	{
		if ($key === null)
		{
			$keys = array_keys($this->logRecorders);

			if (count($keys) !== 1)
			{
				throw new \LogicException(
					'Name the container key: recordsLog() has been called for '
					. (count($keys) ? count($keys) . ' keys (' . implode(', ', $keys) . ')' : 'none')
					. ', so there is no single recorder to assume.'
				);
			}

			$key = $keys[0];
		}

		if (!isset($this->logRecorders[$key]))
		{
			throw new \LogicException(
				"No log recorder is installed for '$key' - call recordsLog('$key') first. Reading"
				. ' the container instead would give the real logger, which writes.'
			);
		}

		return $this->logRecorders[$key];
	}

	/**
	 * Everything logged through the recorder, in order.
	 *
	 * Each entry is `level`, `message` and `context`. The message is as the code under test passed
	 * it, with its `{placeholder}` braces intact, and the values that would fill them are in
	 * `context`.
	 *
	 * @param string|null $key - omit it when the test installed exactly one
	 *
	 * @return array<int, array{level: string, message: string, context: array}>
	 */
	protected function getLogRecords($key = null)
	{
		return $this->getLogRecorder($key)->getRecords();
	}

	/**
	 * Assert that something was logged through the recorder.
	 *
	 * @param string|null $level - the PSR-3 level, eg 'error'; omit it to match any level
	 * @param string|\Closure|null $filter - a string the message must contain, or a closure given
	 *                                       the whole entry; omit it to match any message
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertLogged($level = null, $filter = null, $message = null)
	{
		$records = $this->getLogRecords();
		$matching = $this->matchingLogRecords($records, $level, $filter);

		PHPUnit::assertNotSame(
			[],
			$matching,
			$this->logFailure(
				$message,
				'nothing logged matches ' . $this->describeLogCriteria($level, $filter)
					. '. What was logged: ' . $this->describeLogRecords($records)
			)
		);
	}

	/**
	 * Assert that nothing at all was logged through the recorder.
	 *
	 * @param string|null $message - prefixed to the failure
	 *
	 * @return void
	 */
	protected function assertNothingLogged($message = null)
	{
		PHPUnit::assertSame(
			[],
			$this->getLogRecords(),
			$this->logFailure($message, 'these entries were logged')
		);
	}

	/**
	 * Assert that nothing matching was logged, which is narrower than nothing at all.
	 *
	 * @param string|null $level
	 * @param string|\Closure|null $filter
	 * @param string|null $message
	 *
	 * @return void
	 */
	protected function assertNotLogged($level = null, $filter = null, $message = null)
	{
		$records = $this->getLogRecords();

		PHPUnit::assertSame(
			[],
			$this->matchingLogRecords($records, $level, $filter),
			$this->logFailure(
				$message,
				'these entries match ' . $this->describeLogCriteria($level, $filter)
			)
		);
	}

	/**
	 * The entries matching a level and a filter.
	 *
	 * @param array $records
	 * @param mixed $level
	 * @param mixed $filter - a string or a Closure by contract; a string is always matched against
	 *                        the message and never called, because a plausible message is also a
	 *                        plausible function name
	 *
	 * @return array
	 */
	private function matchingLogRecords(array $records, $level, $filter)
	{
		if ($level !== null && !is_string($level))
		{
			throw new \LogicException(
				'A PSR-3 level is a string, eg \'error\' - got ' . get_debug_type($level) . '.'
			);
		}

		if ($filter !== null && !is_string($filter) && !($filter instanceof \Closure))
		{
			throw new \LogicException(
				'The filter is a string the message must contain, or a Closure given the entry -'
				. ' got ' . get_debug_type($filter) . '. A callable that is not a Closure is not'
				. ' accepted, because a string is always matched against the message.'
			);
		}

		return array_values(array_filter($records, function (array $record) use ($level, $filter)
		{
			if ($level !== null && $record['level'] !== $level)
			{
				return false;
			}

			if ($filter instanceof \Closure)
			{
				return (bool) $filter($record);
			}

			if (is_string($filter) && $filter !== '')
			{
				return strpos($record['message'], $filter) !== false;
			}

			return true;
		}));
	}

	/**
	 * @param string|null $level
	 * @param mixed $filter
	 *
	 * @return string
	 */
	private function describeLogCriteria($level, $filter)
	{
		$parts = [];

		if ($level !== null)
		{
			$parts[] = "level '$level'";
		}

		if ($filter instanceof \Closure)
		{
			$parts[] = 'the closure given';
		}
		else if (is_string($filter) && $filter !== '')
		{
			$parts[] = 'a message containing ' . var_export($filter, true);
		}

		return $parts ? implode(' and ', $parts) : 'any entry at all';
	}

	/**
	 * @param array $records
	 *
	 * @return string
	 */
	private function describeLogRecords(array $records)
	{
		if (!$records)
		{
			return 'nothing';
		}

		return implode('; ', array_map(function (array $record)
		{
			return $record['level'] . ' ' . var_export($record['message'], true);
		}, $records));
	}

	/**
	 * @param string|null $message
	 * @param string $describe
	 *
	 * @return string
	 */
	private function logFailure($message, $describe)
	{
		return $message === null || $message === '' ? $describe : "$message - $describe";
	}
}
