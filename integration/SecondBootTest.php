<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\App;

/**
 * XenForo 2.3.0's src/utf8.php has no function_exists() guards, so the second \XF::start() in a
 * process is a fatal error there, and every test boots. On such a forum the second boot replays
 * start() without that require. The forum here guards the file, so the replay is driven directly
 * and compared with what start() left.
 */
class SecondBootTest extends TestCase
{
	/**
	 * What \XF::start() leaves that a later boot depends on. The class aliases are not here: an
	 * alias cannot be undone in a process, so a replay that skipped that step could not be told
	 * apart.
	 *
	 * @return array<string, mixed>
	 */
	private function xenforoState(): array
	{
		// set_error_handler() pushes and restore_error_handler() pops, so the pair reads the
		// current handler without changing it; the same for the exception handler
		$errorHandler = set_error_handler(fn () => false);
		restore_error_handler();

		$exceptionHandler = set_exception_handler(fn () => null);
		restore_exception_handler();

		return [
			'root' => \XF::getRootDirectory(),
			'source' => \XF::getSourceDirectory(),
			'autoloader' => spl_object_id(\XF::$autoLoader),
			'error handler' => $errorHandler,
			'exception handler' => $exceptionHandler,
		];
	}

	public function test_a_replayed_start_leaves_xenforo_as_start_left_it()
	{
		// setUp() booted through \XF::start(); this is what it left
		$before = $this->xenforoState();
		$this->assertSame(['XF', 'handlePhpError'], $before['error handler']);
		$this->assertSame(['XF', 'handleException'], $before['exception handler']);

		// the first application goes the way tearDown() sends it, handlers handed back to PHPUnit,
		// and the two values start() sets are cleared, so that the replay has to set them
		$this->app()->db()->closeConnection();
		$this->destroyProperty(\XF::class, 'app');
		$this->restoreErrorHandlers();
		\XF::$time = 0;
		$hasReservedMemory = property_exists(\XF::class, 'reservedMemory');
		if ($hasReservedMemory)
		{
			(new \ReflectionClass(\XF::class))->setStaticPropertyValue('reservedMemory', '');
		}

		$this->assertNotSame($before['error handler'], $this->xenforoState()['error handler'], 'the handlers are handed back, so the replay can be seen to install them');

		// the second boot is the framework's own createApplication() with the replay in place of
		// \XF::start(); the output buffer goes around it as refreshApplication() puts it, because
		// standardizeEnvironment() closes it
		$outputBuffer = ob_get_contents();
		ob_end_clean();
		$this->replayXenForoStart();
		$app = \XF::setupApp(App::class, ['xf-addons' => $this->addonsToLoad]);
		ob_start();
		echo $outputBuffer;

		$this->app = $app;

		$this->assertInstanceOf(App::class, $app);
		$this->assertSame($app, \XF::app());
		$this->assertSame(1, $app->db()->fetchOne('SELECT 1'), 'the second application works');
		$this->assertGreaterThan(0, \XF::$time, 'the request time is set');
		if ($hasReservedMemory)
		{
			$this->assertSame(32768, strlen($this->getStaticProperty(\XF::class, 'reservedMemory')));
		}
		$this->assertSame($before, $this->xenforoState());
	}

	public function test_the_replay_is_for_a_utf8_without_guards_only()
	{
		$detector = new \ReflectionMethod(\Hampel\Testing\TestCase::class, 'utf8FunctionsAreGuardedIn');

		$dir = $this->createTempDirectory();

		file_put_contents("$dir/guarded.php", "<?php\nif (!function_exists('utf8_isASCII'))\n{\n\tfunction utf8_isASCII(\$str) {}\n}\n");
		file_put_contents("$dir/unguarded.php", "<?php\nfunction utf8_isASCII(\$str) {}\n");

		$this->assertTrue($detector->invoke(null, "$dir/guarded.php"));
		$this->assertFalse($detector->invoke(null, "$dir/unguarded.php"), 'a file declaring its functions bare is the one the replay is for');
		$this->assertTrue($detector->invoke(null, "$dir/missing.php"), 'a file that cannot be read is left to \XF::start() to report');
	}
}
