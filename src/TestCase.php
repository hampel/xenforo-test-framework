<?php namespace Hampel\Testing;

use Mockery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
//    use Concerns\InteractsWithContainer,
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

//        $this->setUpTraits();

        self::$setUpHasRun = true;
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

//    /**
//     * Boot the testing helper traits.
//     *
//     * @return array
//     */
//    protected function setUpTraits()
//    {
//        $uses = array_flip(class_uses_recursive(static::class));
//
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
//
//        return $uses;
//    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
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

}
