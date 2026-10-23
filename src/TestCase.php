<?php

namespace Hampel\Testing;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;
use XF\Db\DeadlockException;

abstract class TestCase extends BaseTestCase
{
	use Concerns\InteractsWithBbCode;
	use Concerns\InteractsWithCommands;
	use Concerns\InteractsWithContainer;
	use Concerns\InteractsWithDatabase;
	use Concerns\InteractsWithEntityManager;
	use Concerns\InteractsWithErrors;
	use Concerns\InteractsWithEvents;
	use Concerns\InteractsWithFilesystem;
	use Concerns\InteractsWithHttp;
	use Concerns\InteractsWithJobs;
	use Concerns\InteractsWithLanguage;
	use Concerns\InteractsWithLogger;
	use Concerns\InteractsWithMail;
	use Concerns\InteractsWithNodes;
	use Concerns\InteractsWithOptions;
	use Concerns\InteractsWithPsrLog;
	use Concerns\InteractsWithRegistry;
	use Concerns\InteractsWithRequest;
	use Concerns\InteractsWithRoutes;
	use Concerns\InteractsWithSimpleCache;
	use Concerns\InteractsWithTempFiles;
	use Concerns\InteractsWithTemplates;
	use Concerns\InteractsWithTime;
	use Concerns\InteractsWithVisitor;
	use Concerns\UsesReflection;

	/**
	 * The XenForo application instance. Null until setUp() boots one.
	 *
	 * @var App|null
	 */
	protected $app;

	/**
	 * Path to your XenForo root directory, relative to the add-on directory the tests run from.
	 *
	 * '../../../..' suits an add-on id carrying a vendor - src/addons/Vendor/AddonId. Without
	 * one - src/addons/AddonId - use '../../..'. An absolute path works too. No trailing slash.
	 *
	 * @var string
	 */
	protected $rootDir = '../../../..';

	/**
	 * The add-on ids to load, and nothing else. An id matching nothing - 'None/None' - loads no
	 * add-ons at all; an empty array loads every add-on installed on the forum.
	 *
	 * Declared here so the framework can read it. Your own tests/TestCase.php overrides it, and
	 * that is still where you set it.
	 *
	 * @var string[]
	 */
	protected $addonsToLoad = [];

	/**
	 * The callbacks that should be run after the application is created.
	 *
	 * @var array
	 */
	protected $afterApplicationCreatedCallbacks = [];

	/**
	 * The callbacks that should be run before the application is destroyed.
	 *
	 * @var array
	 */
	protected $beforeApplicationDestroyedCallbacks = [];


	/**
	 * Indicates if we have made it through the base setUp function.
	 *
	 * @var bool
	 */
	protected $setUpHasRun = false;

	/**
	 * Boot the XenForo application this test runs against.
	 *
	 * Override it for a different boot, passing $addonsToLoad to XF::setupApp() as 'xf-addons'.
	 * A CreatesApplication trait in the consuming add-on overrides it too, since a trait method
	 * takes precedence over an inherited one.
	 *
	 * @return App
	 */
	public function createApplication()
	{
		require_once "{$this->rootDir}/src/XF.php";

		\XF::start($this->rootDir);

		return \XF::setupApp(App::class, ['xf-addons' => $this->addonsToLoad]);
	}

	/**
	 * Setup the test environment.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		if (! $this->app)
		{
			$this->refreshApplication();
		}

		$this->requireAddOnIsolationApplied($this->addonsToLoad, $this->app()->isolatedAddOnIds());
		$this->requireExtensionsCanApply($this->app()->extension());

		$this->disableAutoJobRunner();

		$this->setUpTraits();

		foreach ($this->afterApplicationCreatedCallbacks AS $callback)
		{
			call_user_func($callback);
		}

		$this->setUpHasRun = true;
	}

	/**
	 * Refuse a suite that asked for add-on isolation and did not get it - $addonsToLoad set, with
	 * a CreatesApplication that does not pass it to XF::setupApp().
	 *
	 * Only the missing case is refused; a boot given a different list is allowed.
	 *
	 * @param string[] $wanted - the ids the test class asks for
	 * @param string[] $applied - the ids the application was actually given
	 *
	 * @return void
	 */
	protected function requireAddOnIsolationApplied(array $wanted, array $applied)
	{
		if (!$wanted || $applied)
		{
			return;
		}

		throw new \LogicException(
			'This suite sets $addonsToLoad to [' . implode(', ', $wanted) . '], but the '
				. 'application was booted without it, so no add-on isolation is in effect: every '
				. 'add-on installed on the forum is active, including any shipping their own '
				. 'PHPUnit and Mockery for yours to collide with.' . "\n\n"
				. 'Your tests/CreatesApplication.php does not pass the ids on. This framework boots '
				. 'the application itself, so delete that file and the "use CreatesApplication;" '
				. 'line in tests/TestCase.php.'
				. "\n\n"
				. 'If you need a boot of your own, pass the ids to it:' . "\n\n"
				. '    return \XF::setupApp(\'Hampel\Testing\App\', '
				. '[\'xf-addons\' => $this->addonsToLoad]);' . "\n\n"
				. 'See UPGRADING.md.'
		);
	}

	/**
	 * Refuse a suite whose add-on list would extend a class this run has already resolved.
	 *
	 * The mirror of the documented case where `$addonsToLoad` cannot remove an extension. A test
	 * class naming more add-ons than its siblings gets their class extensions only when it happens
	 * to run first: the resolution is cached for the whole process, and the proxy XenForo aliased
	 * to reach it is a `class_alias()` PHP cannot declare twice.
	 *
	 * Refused rather than documented alone, because what a test sees instead is XenForo's own class
	 * with the extension's methods missing - so the failure arrives inside somebody else's code,
	 * naming neither the add-on nor the list, and it moves with the order the classes ran in.
	 *
	 * Only the widening direction. Narrowing is the documented case and a test can want it, which
	 * is what `requireClassNotExtended()` is for.
	 *
	 * @param \XF\Extension $extension - the application's, which is this package's subclass unless
	 *                                   listeners are switched off in config.php
	 *
	 * @return void
	 */
	protected function requireExtensionsCanApply($extension)
	{
		if (!($extension instanceof Extension))
		{
			// enableListeners is off, so nothing here recorded what was applied to what
			return;
		}

		$cannot = $extension->extensionsThatCannotApply();

		if (!$cannot)
		{
			return;
		}

		$lines = [];

		foreach ($cannot AS $class => $extensions)
		{
			$lines[] = '    ' . $class . ' is not extended by ' . implode(', ', $extensions);
		}

		throw new \LogicException(
			'A class extension the add-ons this suite loads register cannot be applied, because the '
				. 'class was already resolved in this run under a list that did not have it:'
				. "\n\n" . implode("\n", $lines) . "\n\n"
				. 'XenForo caches that resolution for the whole process and aliases a proxy class to '
				. 'reach the extension, which PHP cannot declare twice - so the first resolution in '
				. 'a run is the one every later test gets. This test class would exercise XenForo\'s '
				. 'own class with the extension\'s methods missing, and it would pass or fail '
				. 'depending on which test class ran first.'
				. "\n\n"
				. 'Declare one $addonsToLoad for the whole suite, in tests/TestCase.php, naming every '
				. 'add-on whose class extensions or content types your tests reach - rather than a '
				. 'different list per test class. See DOCS.md, "Unit Test Configuration".'
		);
	}

	/**
	 * Return our application instance
	 *
	 * @return App
	 */
	public function app()
	{
		if ($this->app === null)
		{
			throw new \LogicException(
				'The XenForo application has not been booted. app() is only available once setUp() '
				. 'has run - call it from a test, not from a data provider or a constructor.'
			);
		}

		return $this->app;
	}

	/**
	 * Refresh the application instance.
	 *
	 * @return void
	 */
	protected function refreshApplication()
	{
		$outputBuffer = ob_get_contents(); // save the current contents of the output buffer
		ob_end_clean(); // pre-emptively clean the output buffer
		$this->app = $this->createApplication();
		\ob_start(); // restart our output buffer
		echo $outputBuffer; // output our previously stored buffer contents
	}

	/**
	 * Turn off the auto job runner
	 */
	protected function disableAutoJobRunner()
	{
		$this->app['job.runTime'] = false;
	}

	/**
	 * Boot the testing helper traits.
	 *
	 * @return array
	 */
	protected function setUpTraits()
	{
		$uses = array_flip($this->classUsesRecursive(static::class));

		if (isset($uses[Concerns\InteractsWithEntityManager::class]))
		{
			$this->setUpEntityManager();
		}

		if (isset($uses[Concerns\InteractsWithLanguage::class]))
		{
			$this->setUpLanguage();
		}

		if (isset($uses[Concerns\InteractsWithOptions::class]))
		{
			$this->setUpOptions();
		}

		if (isset($uses[Concerns\InteractsWithRoutes::class]))
		{
			$this->setUpRoutes();
		}

		if (isset($uses[Concerns\InteractsWithTime::class]))
		{
			$this->setUpTime();
		}

		if (isset($uses[Concerns\InteractsWithVisitor::class]))
		{
			$this->setUpVisitor();
		}

		// opt-in per test class - this trait is deliberately not composed in above, because it
		// needs a real database connection and changes how the test behaves
		if (isset($uses[Concerns\UsesDatabaseTransactions::class])
			&& method_exists($this, 'setUpDatabaseTransactions')
		)
		{
			$this->setUpDatabaseTransactions();
		}

		return $uses;
	}

	/**
	 * A deadlock, or a row that changed under the test, reaches PHPUnit as a database error with a
	 * trace into the code under test and nothing naming the cause - which on a development forum
	 * shared between suites is usually another suite writing the same rows at the same time, and
	 * reads as a regression until the other process is found. The error is rethrown with that said
	 * and the original as its cause, so the class and the trace are still reported beneath it.
	 */
	protected function onNotSuccessfulTest(\Throwable $t): never
	{
		if ($this->isLostToAnotherTransaction($t))
		{
			throw new \RuntimeException(
				'Another transaction on this database held a row this test needed. On a development'
				. " forum shared between suites that is usually another add-on's suite running at the"
				. ' same time: re-run on an idle forum before reading it as a defect. See'
				. ' UsesDatabaseTransactions in DOCS.md. ' . get_class($t) . ': ' . $t->getMessage(),
				(int) $t->getCode(),
				$t
			);
		}

		parent::onNotSuccessfulTest($t);
	}

	/**
	 * @param \Throwable $t
	 *
	 * @return bool a deadlock (1213), a lock wait that timed out (1205), or a row that changed since
	 *              the transaction read it (1020, snapshot isolation)
	 */
	private function isLostToAnotherTransaction(\Throwable $t): bool
	{
		if ($t instanceof DeadlockException)
		{
			return true;
		}

		return $t instanceof \XF\Db\Exception
			&& (bool) preg_match('/\\[(1020|1205|1213)\\]/', $t->getMessage());
	}

	/**
	 * Clean up the testing environment before the next test.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		// first, and outside the check for an application, so a test that failed during boot still
		// has anything it created removed
		$this->removeTempDirectories();

		if ($this->app)
		{
			foreach ($this->beforeApplicationDestroyedCallbacks AS $callback)
			{
				call_user_func($callback);
			}

			// close the database connection to avoid connection limit issues (unless it's been mocked)
			$db = $this->app->db();
			if (!($db instanceof MockInterface))
			{
				$this->app()->db()->closeConnection();
			}

			// \XF::$runOnce is a static nothing else clears, and a closure left in it is bound to
			// the app this teardown is about to destroy. Discarded rather than triggered: running it
			// here would execute deferred work after a transaction has already rolled back.
			$this->writeStaticProperty(\XF::class, 'runOnce', []);

			// \XF::$visitor is a static too, and a visitor left in it belongs to the app being
			// destroyed. The next test's \XF::visitor() builds a fresh guest when it is empty.
			\XF::setVisitor(null);

			$this->destroyProperty(\XF::class, 'app');
		}

		Extension::forgetClassExtensionsAddedByTest();
		$this->restoreStaticProperties();
		$this->restoreErrorHandlers();

		$this->setUpHasRun = false;

		if (class_exists('Mockery'))
		{
			$this->addToAssertionCount(\Mockery::getContainer()->mockery_getExpectationCount());

			\Mockery::close();
		}

		if (class_exists(Carbon::class))
		{
			Carbon::setTestNow();
		}

		if (class_exists(CarbonImmutable::class))
		{
			CarbonImmutable::setTestNow();
		}

		$this->afterApplicationCreatedCallbacks = [];
		$this->beforeApplicationDestroyedCallbacks = [];
	}

	/**
	 * Register a callback to be run after the application is created.
	 *
	 * @param  callable  $callback
	 * @return void
	 */
	public function afterApplicationCreated(callable $callback)
	{
		$this->afterApplicationCreatedCallbacks[] = $callback;

		if ($this->setUpHasRun)
		{
			call_user_func($callback);
		}
	}

	/**
	 * Register a callback to be run before the application is destroyed.
	 *
	 * @param  callable  $callback
	 * @return void
	 */
	protected function beforeApplicationDestroyed(callable $callback)
	{
		$this->beforeApplicationDestroyedCallbacks[] = $callback;
	}

	/**
	 * Hand PHPUnit back its error and exception handlers.
	 *
	 * XF::start() installs its own and never removes them, so without this every test that
	 * boots XenForo is reported as risky - "test code or tested code did not remove its own
	 * error handlers" - and failOnRisky cannot be used at all.
	 *
	 * The handlers stay in place for the duration of the test, so code under test still sees
	 * XenForo's error handling as it would in production. Only the teardown differs.
	 *
	 * @return void
	 */
	protected function restoreErrorHandlers()
	{
		// bounded rather than while(true): a test that booted the app more than once will have
		// stacked more than one, and anything unexpected should not hang the suite
		for ($i = 0; $i < 10; $i++)
		{
			if (!$this->isXenForoHandler($this->currentErrorHandler()))
			{
				break;
			}

			restore_error_handler();
		}

		for ($i = 0; $i < 10; $i++)
		{
			if (!$this->isXenForoHandler($this->currentExceptionHandler()))
			{
				break;
			}

			restore_exception_handler();
		}
	}

	/**
	 * @return callable|null
	 */
	private function currentErrorHandler()
	{
		// setting a handler returns the one it replaced, and restoring puts it straight back -
		// there is no read-only way to ask PHP what is installed
		$handler = set_error_handler(null);
		restore_error_handler();

		return $handler;
	}

	/**
	 * @return callable|null
	 */
	private function currentExceptionHandler()
	{
		$handler = set_exception_handler(null);
		restore_exception_handler();

		return $handler;
	}

	/**
	 * @param mixed $handler
	 *
	 * @return bool
	 */
	private function isXenForoHandler($handler)
	{
		// \XF is in the global namespace, so \XF::class is the string 'XF'
		return is_array($handler)
			&& count($handler) === 2
			&& $handler[0] === \XF::class;
	}

	public static function trace()
	{
		$cwd = getcwd();

		$e = new \Exception();
		$trace = explode("\n", $e->getTraceAsString());
		// reverse array to make steps line up chronologically
		$trace = array_reverse($trace);
		array_shift($trace); // remove {main}
		array_pop($trace); // remove call to this method
		$length = count($trace);
		$result = [];

		for ($i = 0; $i < $length; $i++)
		{
			$result[] = ($i + 1) . ')' . str_replace("{$cwd}/", '', substr($trace[$i], strpos($trace[$i], ' '))); // replace '#someNum' with '$i)', set the right ordering
		}

		return "\t" . implode("\n\t", $result);
	}
}
