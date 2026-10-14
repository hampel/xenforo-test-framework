<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Log\Recorder;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * recordsLog() replaces an add-on's own PSR-3 logger with one that records.
 *
 * The logger interface comes from the forum rather than from this package, so the recorder has to
 * load against whichever psr/log XenForo supplies - which the first test asserts rather than
 * assumes.
 */
class PsrLogTest extends TestCase
{
	public function test_the_recorder_implements_the_psr_log_the_forum_supplies()
	{
		$recorder = new Recorder();

		$this->assertInstanceOf(LoggerInterface::class, $recorder);

		// the interface is the forum's, not the copy in this package's own vendor directory
		$file = (new \ReflectionClass(LoggerInterface::class))->getFileName();
		$this->assertStringStartsWith($this->rootDir, $file);

		// the level helpers AbstractLogger provides all route through log()
		$recorder->info('hello {name}', ['name' => 'world']);
		$recorder->error('bad');

		$this->assertSame(
			[
				['level' => 'info', 'message' => 'hello {name}', 'context' => ['name' => 'world']],
				['level' => 'error', 'message' => 'bad', 'context' => []],
			],
			$recorder->getRecords()
		);
	}

	public function test_the_code_under_test_resolves_the_recorder_from_the_container()
	{
		$this->givenAnAddOnLogger('zzprobe.log');

		$recorder = $this->recordsLog('zzprobe.log');

		// as the add-on's own code would reach it
		$resolved = $this->app()->container('zzprobe.log');
		$this->assertSame($recorder, $resolved);

		$resolved->warning('something');
		$this->assertSame('something', $this->getLogRecords()[0]['message']);
	}

	public function test_without_the_fake_the_add_ons_own_logger_receives_it()
	{
		$real = $this->givenAnAddOnLogger('zzprobe.log');

		$this->app()->container('zzprobe.log')->warning('went to the real one');

		$this->assertSame(['went to the real one'], $real->written);
	}

	public function test_a_second_fake_replaces_the_first()
	{
		$this->givenAnAddOnLogger('zzprobe.log');

		$first = $this->recordsLog('zzprobe.log');
		$this->app()->container('zzprobe.log')->info('one');

		$second = $this->recordsLog('zzprobe.log');
		$this->app()->container('zzprobe.log')->info('two');

		$this->assertNotSame($first, $second);
		$this->assertSame(['one'], array_column($first->getRecords(), 'message'));
		$this->assertSame(['two'], array_column($second->getRecords(), 'message'));
		$this->assertSame(['two'], array_column($this->getLogRecords(), 'message'));
	}

	public function test_the_assertions_match_on_level_message_and_a_closure()
	{
		$this->givenAnAddOnLogger('zzprobe.log');
		$logger = $this->recordsLog('zzprobe.log');

		$logger->error('upload of {file} failed', ['file' => 'a.txt']);
		$logger->info('all done');

		$this->assertLogged('error');
		$this->assertLogged('error', 'upload of');
		$this->assertLogged(null, 'all done');
		$this->assertLogged('error', function (array $record)
		{
			return ($record['context']['file'] ?? null) === 'a.txt';
		});

		$this->assertNotLogged('critical');
		$this->assertNotLogged('error', 'a message nothing carries');
	}

	public function test_nothing_logged_is_asserted_and_fails_when_something_was()
	{
		$this->givenAnAddOnLogger('zzprobe.log');
		$logger = $this->recordsLog('zzprobe.log');

		$this->assertNothingLogged();

		$logger->notice('here');

		try
		{
			$this->assertNothingLogged();
			$this->fail('an entry should have failed assertNothingLogged()');
		}
		catch (ExpectationFailedException $e)
		{
			$this->assertStringContainsString('here', print_r($e->getComparisonFailure()->getActual(), true));
		}
	}

	public function test_the_failure_names_what_was_logged_instead()
	{
		$this->givenAnAddOnLogger('zzprobe.log');
		$this->recordsLog('zzprobe.log')->info('only this');

		try
		{
			$this->assertLogged('error', 'missing thing');
			$this->fail('no matching entry should have failed');
		}
		catch (ExpectationFailedException $e)
		{
			$this->assertStringContainsString("level 'error'", $e->getMessage());
			$this->assertStringContainsString("'missing thing'", $e->getMessage());
			$this->assertStringContainsString("info 'only this'", $e->getMessage());
		}
	}

	public function test_reading_records_without_a_recorder_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('recordsLog() has been called for none');

		$this->getLogRecords();
	}

	public function test_two_recorders_make_the_assertions_ambiguous_rather_than_arbitrary()
	{
		$this->givenAnAddOnLogger('zzprobe.log');
		$this->givenAnAddOnLogger('zzother.log');
		$this->recordsLog('zzprobe.log');
		$this->recordsLog('zzother.log');

		// naming one still works
		$this->assertSame([], $this->getLogRecords('zzprobe.log'));

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Name the container key');

		$this->getLogRecords();
	}

	public function test_naming_a_key_that_was_never_recorded_is_refused()
	{
		$this->givenAnAddOnLogger('zzprobe.log');
		$this->recordsLog('zzprobe.log');

		// reading the container instead would hand back the add-on's real logger, which writes
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("No log recorder is installed for 'zzother.log'");

		$this->getLogRecords('zzother.log');
	}

	public function test_a_key_that_is_not_one_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('takes the container key');

		$this->recordsLog(null);
	}

	public function test_a_callable_that_is_not_a_closure_is_refused_as_a_filter()
	{
		$this->givenAnAddOnLogger('zzprobe.log');
		$this->recordsLog('zzprobe.log')->info('header');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('not accepted');

		// 'header' is a real PHP function as well as a plausible message
		$this->assertLogged(null, [$this, 'notAFilter']);
	}

	/**
	 * Put a logger in the container under a key, as an add-on's app_setup listener would.
	 *
	 * @param string $key
	 *
	 * @return AbstractLogger
	 */
	private function givenAnAddOnLogger($key)
	{
		$real = new class extends AbstractLogger
		{
			/** @var string[] */
			public $written = [];

			public function log($level, $message, array $context = []): void
			{
				$this->written[] = (string) $message;
			}
		};

		$this->swap($key, $real);

		return $real;
	}

	/**
	 * @return bool
	 */
	public function notAFilter()
	{
		return true;
	}
}
