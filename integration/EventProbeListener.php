<?php

namespace Hampel\Testing\Integration;

/**
 * A stand-in code event listener, used to prove that faking events stops listeners running
 * rather than merely recording alongside them.
 */
class EventProbeListener
{
	/** @var int */
	public static $calls = 0;

	/** @var array */
	public static $lastArgs = [];

	public static function reset()
	{
		self::$calls = 0;
		self::$lastArgs = [];
	}

	public static function handle($one = null, $two = null)
	{
		self::$calls++;
		self::$lastArgs = [$one, $two];
	}
}
