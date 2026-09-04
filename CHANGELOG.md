CHANGELOG
=========

4.0.0 (unreleased)
------------------

* new makeEntity() and createEntity() helpers for building entities with given values
* new fakesHttpByUrl() - chooses the http response by request URL rather than by call order
* new fakesEvents() - records code events and stops them reaching listeners, with
  assertEventFired(), assertEventFiredTimes(), assertEventNotFired() and assertNoEventsFired()
* new actingAs(), actingAsMember() and actingAsGuest() helpers - run a test as a given user,
  with permissions granted in memory rather than read from the database
* new setVisitorPermissions(), setVisitorContentPermissions() and buildVisitor() helpers
* new UsesDatabaseTransactions trait - wraps each test in a transaction and rolls it back, so
  tests can exercise real entity saves without leaving anything behind
* new assertDatabaseHas(), assertDatabaseMissing() and assertDatabaseCount() assertions
* PHPUnit 11 and 12 are now supported
* bugfix: fakesRegistry() failed with a fatal error - DataRegistry did not match the XenForo 2.3
  method signatures
* bugfix: mail assertions failed with a TypeError once any mail had been sent
* bugfix: assertJobQueued() with a count called a method that does not exist
* bugfix: assertExceptionLogged(), assertActionLogged() and assertChangeLogged() with a count
  asserted against the wrong value
* bugfix: Job\Manager::runByIds() returned null where an array was documented
* bugfix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* bugfix: fakesHttpByUrl() ignored Guzzle's `sink`, so code downloading to a file - which is
  what XF\Http\Reader::getUntrusted($url, $limits, $saveTo) does - received the response and
  wrote nothing, while every request assertion still passed
* bugfix: a second fakesHttp() or fakesHttpByUrl() call in one test had no effect, because
  XenForo's cached `reader` still held the first fake's client; the failure surfaced later as
  "Mock queue is empty"
* bugfix: every user built by buildVisitor() / actingAs* landed on permission combination id 1 -
  the forum's real guest combination. Permissions granted to one built user therefore applied to
  all of them, and a permission the test never granted was read from the development forum rather
  than denied, so the same test could pass on one forum and fail on another. Each built user now
  gets its own combination id; pass permission_combination_id yourself to opt out
* bugfix: fakesEvents() recorded event arguments by reference, since XenForo fires extension
  points as `$app->fire('event', [&$args])` and copying an array preserves the references in it.
  An assertion therefore saw whatever the caller left in the variable after the event, not what
  was fired. Arguments are now recorded as they were at the moment of firing; objects are still
  recorded as the same instance
* bugfix: mockRepository() stored the mock under the identifier it was given, but getRepository()
  normalises before looking one up. A spelling XenForo accepts everywhere else - 'XF:UserRepository',
  or the full class name - therefore registered a mock nothing consulted: the real repository ran and
  the unmet expectations were never reported. Identifiers are now normalised the same way XenForo
  normalises them
* bugfix: mockService() built an untyped Mockery double when the short name resolved to a class that
  does not exist, so a misspelled service name produced a test which passed while asserting against
  nothing. It now throws a LogicException. The mock is also typed as the class XenForo would really
  have built, resolved through the class alias map and the extension chain
* assertErrorLogged() and assertErrorNotLogged() no longer require a message, matching
  assertExceptionLogged() - omit it to assert that any error at all was, or was not, logged
* error and exception handlers are restored after each test. This is what makes PHPUnit 11 and
  12 support possible rather than a separate fix: XF::start() installs handlers and never
  removes them, which PHPUnit 11 onwards reports as risky on every test that boots XenForo.
  PHPUnit 10 does not report it, so the symptom cannot be reproduced on 3.0.3
* phpunit.xml now fails the suite on deprecations, notices, warnings, risky tests, PHPUnit's own
  deprecations (failOnPhpunitDeprecation) and a run which executes no tests (failOnEmptyTestSuite).
  The last one covers the only genuinely silent case: a suite that has stopped collecting tests
  exits 0 by default and reads as passing
* the tests/Feature directory is included, which PHPUnit requires in order to run. It ships a
  .gitkeep so that it survives being committed - git does not track empty directories, so a
  tests/Feature you create by hand is absent in every clone, including CI. Commit the .gitkeep
* the fakes* helpers and swapFs() now return the object they document, rather than the
  closure the container had not yet resolved
* code style is now XenForo's own, applied with xenforo-ltd/xf-cs-fixer
* league/flysystem-memory 2.0 and above are rejected - XenForo 2.3 ships Flysystem 1.x

**Breaking changes:**
* minimum PHP version is now 8.3. If your addon pins `config.platform.php` below 8.3, Composer
  cannot install this package at all - the solve fails outright. Raising that pin is not
  dev-only in effect: it also lets Composer select **runtime** dependencies above the PHP
  version your addon declares, and those ship in your release zip. After raising it, check that
  every package in composer.lock's `packages` array still satisfies your addon's own PHP floor,
  and cap any that do not
* PHPUnit 12 is now allowed, and for most addons this package is the only thing that pins PHPUnit
  at all - so a composer update will select 12 where it used to select 10. PHPUnit 12 no longer
  reads metadata from doc comments, so tests using @dataProvider, @depends, @covers or @group error
  rather than run. Convert them to attributes (#[DataProvider] and friends, understood by 10, 11 and
  12) or pin phpunit/phpunit yourself. PHPUnit 11 reports these as deprecations and still exits 0,
  which is why the supplied phpunit.xml now sets failOnPhpunitDeprecation
* phpunit.xml has been updated and should be re-copied into your addon
* the files in tests/ have been restyled, so a diff against your own copies will show
  formatting changes as well as the changes described above

3.0.3 (2024-12-30)
------------------

* bugfix: some code related to mail queueing has been removed since we no longer use it 

3.0.2 (2024-08-11)
------------------

* new expanded extension class - use global static maps to keep track of extensions and aliases between test runs

3.0.1 (2024-08-10)
------------------

* for some reason our extension class wasn't working correctly - we'll just remove it

3.0.0 (2024-08-10)
------------------

* compatibilty with XenForo v2.3
* Hampel\Testing\Job\Manager updated to match changes in XF\Job\Manager, particularly XF\Job\JobParams
* Hampel\Testing\Mail\Transport replaced with Hampel\Testing\Mail\TestTransport which implements Symfony mail transport
* Hampel\Testing\Mail\Queue removed - we now simply disable queueing which results in all mails being sent via the test
  transport

2.2.0 (2024-07-10)
------------------

* php 8.3 compatibility fix - ReflectionProperty::setValue with a single parameter is now deprecated; but as of php 8.1 
  we can simply use ReflectionClass::setStaticValue without needing to explicitly set private or protected properties 
  as accessible
* we now need to use a minimum of php 8.1
* upgrade to PHPUnit v10.x

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
