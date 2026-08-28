<?php

namespace Hampel\Testing;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;
use XF\App;
use XF\Db\AbstractAdapter;

abstract class TestCase extends BaseTestCase
{
	use Concerns\InteractsWithBbCode;
	use Concerns\InteractsWithContainer;
	use Concerns\InteractsWithDatabase;
	use Concerns\InteractsWithEntityManager;
	use Concerns\InteractsWithErrors;
	use Concerns\InteractsWithEvents;
	use Concerns\InteractsWithExtension;
	use Concerns\InteractsWithFilesystem;
	use Concerns\InteractsWithHttp;
	use Concerns\InteractsWithJobs;
	use Concerns\InteractsWithLanguage;
	use Concerns\InteractsWithLogger;
	use Concerns\InteractsWithMail;
	use Concerns\InteractsWithOptions;
	use Concerns\InteractsWithRegistry;
	use Concerns\InteractsWithRequest;
	use Concerns\InteractsWithSimpleCache;
	use Concerns\InteractsWithTime;
	use Concerns\InteractsWithVisitor;
	use Concerns\UsesReflection;

	/**
	 * The XenForo application instance.
	 *
	 * @var App
	 */
	protected $app;

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
	 * Creates the application.
	 *
	 * Needs to be implemented by subclasses.
	 *
	 * @return App
	 */
	abstract public function createApplication();

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

		$this->disableAutoJobRunner();

		$this->setUpTraits();

		foreach ($this->afterApplicationCreatedCallbacks AS $callback)
		{
			call_user_func($callback);
		}

		$this->setUpHasRun = true;
	}

	/**
	 * Return our application instance
	 *
	 * @return App
	 */
	public function app()
	{
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

		if (isset($uses[Concerns\InteractsWithExtension::class]))
		{
			$this->setUpExtension();
		}

		if (isset($uses[Concerns\InteractsWithLanguage::class]))
		{
			$this->setUpLanguage();
		}

		if (isset($uses[Concerns\InteractsWithOptions::class]))
		{
			$this->setUpOptions();
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
			if ($db instanceof AbstractAdapter && !($db instanceof MockInterface))
			{
				$this->app()->db()->closeConnection();
			}

			$this->destroyProperty(\XF::class, 'app');
		}

		$this->restoreErrorHandlers();

		$this->setUpHasRun = false;

		if (class_exists('Mockery'))
		{
			if ($container = \Mockery::getContainer())
			{
				$this->addToAssertionCount($container->mockery_getExpectationCount());
			}

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
		return is_array($handler)
			&& count($handler) === 2
			&& ($handler[0] === 'XF' || $handler[0] === \XF::class);
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
