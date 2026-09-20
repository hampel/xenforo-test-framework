<?php

namespace Hampel\Testing;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	use Concerns\InteractsWithBbCode;
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
	use Concerns\InteractsWithOptions;
	use Concerns\InteractsWithRegistry;
	use Concerns\InteractsWithRequest;
	use Concerns\InteractsWithRoutes;
	use Concerns\InteractsWithSimpleCache;
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
	 * The framework owns this as of 5.0.0. It used to ship as tests/CreatesApplication.php for
	 * you to copy, which meant every later change to the boot had to be merged by hand into a
	 * file you owned - and a copy nobody merged is indistinguishable from one nobody needed to.
	 * The v2.1.0 add-on isolation arguments are the case that proves it: add-ons are still
	 * running the 2020 version of that file today.
	 *
	 * It is an ordinary method, so override it if you genuinely need a different boot. An
	 * add-on that kept its own tests/CreatesApplication.php also keeps exactly the behaviour it
	 * had, because a trait method wins over an inherited one.
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

		$this->disableAutoJobRunner();

		$this->setUpTraits();

		foreach ($this->afterApplicationCreatedCallbacks AS $callback)
		{
			call_user_func($callback);
		}

		$this->setUpHasRun = true;
	}

	/**
	 * Refuse a suite that asked for add-on isolation and did not get it.
	 *
	 * The v2.1.0 scaffold change needed both files: $addonsToLoad in tests/TestCase.php, and
	 * tests/CreatesApplication.php passing it to XF::setupApp(). Taking one without the other
	 * has always been silent - the suite runs with every installed add-on active, which is the
	 * state $addonsToLoad was set to avoid, and the failure that follows looks like a bug in
	 * the add-on rather than a half-finished upgrade.
	 *
	 * Only the missing case is refused. A suite that deliberately boots with a different list
	 * than the property names is doing something of its own and is left alone.
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
				. 'Your tests/CreatesApplication.php predates v2.1.0 and never passes the ids on. '
				. 'As of v5.0.0 this framework boots the application for you, so the fix is to '
				. 'delete that file and the "use CreatesApplication;" line in tests/TestCase.php.'
				. "\n\n"
				. 'If you need a boot of your own, pass the ids to it:' . "\n\n"
				. '    return \XF::setupApp(\'Hampel\Testing\App\', '
				. '[\'xf-addons\' => $this->addonsToLoad]);' . "\n\n"
				. 'See UPGRADING.md.'
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
	 * Clean up the testing environment before the next test.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
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
			$this->setStaticProperty(\XF::class, 'runOnce', []);

			$this->destroyProperty(\XF::class, 'app');
		}

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
