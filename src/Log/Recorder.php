<?php

namespace Hampel\Testing\Log;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that records instead of writing, for an add-on that logs through a
 * LoggerInterface of its own rather than through XenForo's moderator log.
 *
 * Installed over the add-on's container key by recordsLog(), which is where the assertions are.
 *
 * The message is recorded as it was passed, with its `{placeholder}` braces intact, and the
 * context beside it - so a test asserts on what the code under test asked to be logged rather
 * than on an interpolated string this class would have had to invent.
 */
class Recorder extends AbstractLogger
{
	/**
	 * @var array<int, array{level: string, message: string, context: array}>
	 */
	protected $records = [];

	/**
	 * Record one entry.
	 *
	 * The signature is deliberately wider than psr/log 3 declares and narrower than psr/log 1
	 * does, so that this class loads against either: the forum supplies psr/log, and which major
	 * it supplies is XenForo's choice rather than this package's. An untyped $message accepts
	 * psr/log 3's `string|Stringable`, and the void return satisfies 3 while being an addition
	 * 1 permits.
	 *
	 * @param mixed $level
	 * @param string|object $message
	 * @param array $context
	 *
	 * @return void
	 */
	public function log($level, $message, array $context = []): void
	{
		$this->records[] = [
			'level' => (string) $level,
			'message' => (string) $message,
			'context' => $context,
		];
	}

	/**
	 * Everything logged through this recorder, in order.
	 *
	 * @return array<int, array{level: string, message: string, context: array}>
	 */
	public function getRecords()
	{
		return $this->records;
	}
}
