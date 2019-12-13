<?php namespace Hampel\Testing;

use Mockery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	use Concerns\InteractsWithBbCode,
		Concerns\InteractsWithContainer,
		Concerns\InteractsWithDatabase,
		Concerns\InteractsWithEntityManager,
		Concerns\InteractsWithErrors,
		Concerns\InteractsWithExtension,
		Concerns\InteractsWithFilesystem,
		Concerns\InteractsWithHttp,
		Concerns\InteractsWithJobs,
		Concerns\InteractsWithLanguage,
		Concerns\InteractsWithLogger,
		Concerns\InteractsWithMail,
		Concerns\InteractsWithOptions,
		Concerns\InteractsWithRegistry,
		Concerns\InteractsWithRequest,
		Concerns\InteractsWithSimpleCache,
		Concerns\InteractsWithTime,
		Concerns\UsesReflection;

	/**
     * The XenForo application instance.
     *
     * @var \XF\App
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
     * @return \XF\App
     */
    abstract public function createApplication();

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (! $this->app) {
            $this->refreshApplication();
        }

        $this->disableAutoJobRunner();

    	$this->setUpTraits();

        foreach ($this->afterApplicationCreatedCallbacks as $callback) {
            call_user_func($callback);
        }

        $this->setUpHasRun = true;
    }

	/**
	 * Return our application instance
	 *
	 * @return \XF\App
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

        if (isset($uses[Concerns\InteractsWithEntityManager::class])) {
            $this->setUpEntityManager();
        }

		if (isset($uses[Concerns\InteractsWithExtension::class])) {
			$this->setUpExtension();
		}

		if (isset($uses[Concerns\InteractsWithLanguage::class])) {
			$this->setUpLanguage();
		}

        if (isset($uses[Concerns\InteractsWithOptions::class])) {
            $this->setUpOptions();
        }

        if (isset($uses[Concerns\InteractsWithTime::class])) {
            $this->setUpTime();
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
        if ($this->app) {
            foreach ($this->beforeApplicationDestroyedCallbacks as $callback) {
                call_user_func($callback);
            }

            $this->destroyProperty(\XF::class, 'app');
        }

        $this->setUpHasRun = false;

        if (class_exists('Mockery')) {
            if ($container = Mockery::getContainer()) {
                $this->addToAssertionCount($container->mockery_getExpectationCount());
            }

            Mockery::close();
        }

        if (class_exists(Carbon::class)) {
            Carbon::setTestNow();
        }

        if (class_exists(CarbonImmutable::class)) {
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

        if ($this->setUpHasRun) {
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
	    $result = array();

	    for ($i = 0; $i < $length; $i++)
	    {
	        $result[] = ($i + 1)  . ')' . str_replace("{$cwd}/", '', substr($trace[$i], strpos($trace[$i], ' '))); // replace '#someNum' with '$i)', set the right ordering
	    }

	    return "\t" . implode("\n\t", $result);
	}
}
