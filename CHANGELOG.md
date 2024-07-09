CHANGELOG
=========

2.2.0 (2024-07-10)
------------------

* php 8.3 compatibility fix - ReflectionProperty::setValue with a single parameter is now deprecated; but as of php 8.1 
  we can simply use ReflectionClass::setStaticValue without needing to explicitly set private or protected properties 
  as accessible
* we now need to use a minimum of php 8.1

2.1.0 (2024-03-14)
------------------

* allow swapping subcontainer keys using either a class or a string to define the app container key
* new option in TestCase - $addonsToLoad
* new implementation of addon isolation limiting composer autoload and extension/listener loading based on which addons 
  are specified in TestCase

**Breaking changes:**
* isolateAddon function has been removed and replaced by an option in `TestCase.php`
* both `TestCase.php` and `CreatesApplication.php` will need to be updated in addons based on the new versions in this 
  package

2.0.2 (2020-09-23)
------------------

* should be returning the instance we created when swapping or faking classes

2.0.1 (2020-09-15)
------------------

* Job Manager - getUniqueJob wasn't returning the job
* don't serialize job paramaters

2.0.0 (2020-08-28)
------------------

* compatibility changes for XenForo v2.2
* XF 2.2 implements Swiftmailer 6 which changes some method/interface signatures

1.2.2 (2020-08-04)
------------------

* fixed missing use clause in `Hampel\Testing\Concerns\InteractsWithFilesystem` trait

1.2.1 (2020-07-25)
------------------

* fixed typo in function name: `Hampel\Testing\Concerns\InteractsWithSimpleCache::assertSimpleCacheEqual()` => 
`assertSimpleCacheEquals()` and `assertSimpleCacheNotEqual()` => `assertSimpleCacheNotEquals()`
* close the database connection on tearDown to avoid connection limit issues (unless it's been mocked)

1.2.0 (2019-12-13)
------------------

 * Feature: added new functionality to Interacts with Container
   * mockService
 * Feature: Interacts with Http - adds:
   * fakesHttp  

1.1.0 (2019-11-26)
------------------

 * Feature: added new functionality to Interacts with Extension
   * isolateAddon
 * Feature: Interacts with Registry - adds:
   * fakesRegistry
 * Feature: Interacts with Filesystem - adds:
   * swapFs
   * mockFs
 * bugfix: after mocking the database, set up the entity manager again, so we get the mocked database
 * bugfix: should pass options array through to parent
 * bugfix: cleaned up function visibility for consistency
 * bugfix: override protected function preLoadData so we can call it directly when faking the registry

1.0.0 (2019-11-19)
------------------

 * first released version
 * The following functionality is included:
   * mockDatabase
   * mockRepository
   * mockFinder
   * mockEntity
   * mockRequest
   * fakesErrors
   * fakesJobs
   * fakesLogger
   * fakesMail
   * fakesSimpleCache
   * assertBbCode
   * expectPhrase
   * setOption & setOptions
   * setTestTime
