<?php namespace Hampel\Testing;

use Mockery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Hampel\Testing\Concerns\InteractsWithEntityManager;

abstract class TestCase extends BaseTestCase
{
    use Concerns\InteractsWithContainer,
	    Concerns\InteractsWithEntityManager;//,
//        Concerns\MakesHttpRequests,
//        Concerns\InteractsWithAuthentication,
//        Concerns\InteractsWithConsole,
//        Concerns\InteractsWithDatabase,
//        Concerns\InteractsWithExceptionHandling,
//        Concerns\InteractsWithSession,
//        Concerns\MocksApplicationServices;

    /**
     * The XenForo application instance.
     *
     * @var \XF\App
     */
    protected static $app;

    /**
     * The callbacks that should be run after the application is created.
     *
     * @var array
     */
    protected static $afterApplicationCreatedCallbacks = [];

    /**
     * The callbacks that should be run before the application is destroyed.
     *
     * @var array
     */
    protected static $beforeApplicationDestroyedCallbacks = [];


    /**
     * Indicates if we have made it through the base setUp function.
     *
     * @var bool
     */
    protected static $setUpHasRun = false;

    /**
     * Creates the application.
     *
     * Needs to be implemented by subclasses.
     *
     * @return \XF\App
     */
    abstract public static function createApplication();

    /**
     * Setup the test environment.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        if (! self::$app) {
            self::refreshApplication();
        }

        self::setUpTraits();

        foreach (self::afterApplicationCreatedCallbacks as $callback) {
            call_user_func($callback);
        }

        self::$setUpHasRun = true;
    }

    public function app()
    {
    	return self::$app;
    }

    /**
     * Refresh the application instance.
     *
     * @return void
     */
    protected static function refreshApplication()
    {
        self::$app = static::createApplication();
    }

    /**
     * Boot the testing helper traits.
     *
     * @return array
     */
    protected static function setUpTraits()
    {
        $uses = array_flip(self::class_uses_recursive(static::class));

        if (isset($uses[InteractsWithEntityManager::class])) {
            self::setUpManager();
        }

//        if (isset($uses[RefreshDatabase::class])) {
//            $this->refreshDatabase();
//        }
//
//        if (isset($uses[DatabaseMigrations::class])) {
//            $this->runDatabaseMigrations();
//        }
//
//        if (isset($uses[DatabaseTransactions::class])) {
//            $this->beginDatabaseTransaction();
//        }
//
//        if (isset($uses[WithoutMiddleware::class])) {
//            $this->disableMiddlewareForAllTests();
//        }
//
//        if (isset($uses[WithoutEvents::class])) {
//            $this->disableEventsForAllTests();
//        }
//
//        if (isset($uses[WithFaker::class])) {
//            $this->setUpFaker();
//        }

        return $uses;
    }

    protected static function class_uses_recursive($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = [];

        foreach (array_reverse(class_parents($class)) + [$class => $class] as $class) {
            $results += self::trait_uses_recursive($class);
        }

        return array_unique($results);
    }

    /**
     * Returns all traits used by a trait and its traits.
     *
     * @param  string  $trait
     * @return array
     */
    protected static function trait_uses_recursive($trait)
    {
        $traits = class_uses($trait);

        foreach ($traits as $trait) {
            $traits += self::trait_uses_recursive($trait);
        }

        return $traits;
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
    	// reset options
    	$this->app()->container('em')->getRepository('XF:Option')->rebuildOptionCache();

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
    }

	public static function tearDownAfterClass(): void
	{
        if (self::$app) {
            foreach (self::$beforeApplicationDestroyedCallbacks as $callback) {
                call_user_func($callback);
            }
        }
	}
}
