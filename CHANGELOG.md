CHANGELOG
=========

4.0.2 (2026-09-10)
------------------

* bugfix: a faked response reached the caller with its body already read, so
  `$response->getBody()->getContents()` returned an empty string under both `fakesHttp()` and
  `fakesHttpByUrl()`. XenForo's `XF\Http\Reader` always gives Guzzle a sink - `php://temp` when you
  pass no `$saveTo` - and both fakes read the body to the end to write it there; Guzzle's own
  `MockHandler`, behind `fakesHttp()`, has the identical code. Real Guzzle returns the rewound sink
  as the body, so production was never affected. `getContents()` is how XenForo core reads a
  response, so an add-on reading one the same way could not be tested through either fake. A
  `(string)` cast was unaffected, because it seeks to the start before reading - which is also why
  nothing here caught it

4.0.1 (2026-09-05)
------------------

* docs: the README listed `isolateAddon` among the available helpers. It was removed in 3.0.0 and
  calling it is a fatal - use the `$addonsToLoad` property in `tests/TestCase.php` instead
* docs: the README's helper lists did not mention anything added in 4.0.0. `actingAs()` and the
  visitor helpers, `makeEntity()` / `createEntity()`, `fakesEvents()`, `fakesHttpByUrl()`,
  `setConfig()`, `UsesDatabaseTransactions` and the database assertions are all listed now
* docs: the README notes that `swapFs()` needs `league/flysystem-memory` in your own `require-dev`
* the scaffold ships `tests/Feature/ExampleTest.php` in place of `tests/Feature/.gitkeep`. It keeps the
  directory in git exactly as the `.gitkeep` did, and additionally shows where feature tests go, the
  same way `tests/Unit/ExampleTest.php` does. It guards nothing: `failOnEmptyTestSuite` fires on an
  empty run rather than an empty suite, so a Feature suite that collects nothing - or that holds a
  test PHPUnit never picked up - still exits 0 while the Unit suite passes. It also removes the one
  signal the `.gitkeep` gave: `--testsuite Feature` exited 1 on an empty directory and exits 0 once
  anything is in it. The README says both, and says to read the count from that command rather than
  its exit code
* the 3.0.4 and 3.0.5 entries are included below - those releases were cut on the 3.x branch and
  their entries had not reached this one

4.0.0 (2026-09-05)
------------------

* new `makeEntity()` and `createEntity()` helpers for building entities with given values
* new `fakesHttpByUrl()` - chooses the http response by request URL rather than by call order
* new `fakesEvents()` - records code events and stops them reaching listeners, with
  `assertEventFired()`, `assertEventFiredTimes()`, `assertEventNotFired()` and `assertNoEventsFired()`
* new `actingAs()`, `actingAsMember()` and `actingAsGuest()` helpers - run a test as a given user,
  with permissions granted in memory rather than read from the database
* new `setVisitorPermissions()`, `setVisitorContentPermissions()` and `buildVisitor()` helpers
* new `setConfig()` helper - set a `config.php` value, which `setOption()` cannot do
* new `UsesDatabaseTransactions` trait - wraps each test in a transaction and rolls it back, so
  tests can exercise real entity saves without leaving anything behind
* new `assertDatabaseHas()`, `assertDatabaseMissing()` and `assertDatabaseCount()` assertions
* PHPUnit 11 and 12 are now supported
* bugfix: `fakesRegistry()` failed with a fatal error - `DataRegistry` did not match the XenForo 2.3
  method signatures
* bugfix: mail assertions failed with a `TypeError` once any mail had been sent
* bugfix: `assertJobQueued()` with a count called a method that does not exist
* bugfix: `assertExceptionLogged()`, `assertActionLogged()` and `assertChangeLogged()` with a count
  asserted against the wrong value
* bugfix: `Job\Manager::runByIds()` returned null where an array was documented
* bugfix: `DataRegistry::delete()` called a cache method Symfony's `AdapterInterface` does not
  declare, so deleting a registry key fataled whenever the registry had been constructed with a
  cache. It now calls `deleteItems()`, as XenForo itself does
* `Job\Manager::runQueue()`, `runUnique()` and `runJobEntry()` return `null` explicitly, and the
  docblocks on `_enqueue()` and `setOptions()` name the parameters those methods actually take
* `swapFs()`, `mockFs()`, `fakesRegistry()` and `buildVisitor()` throw a `LogicException` if
  XenForo hands back a filesystem, registry or repository of an unexpected type, rather than
  fatalling on an undefined method
* `app()` throws a `LogicException` when called before the application has been booted, rather
  than returning null for something else to fail on later
* `Error::logException()` accepts a non-throwable, as XenForo's own signature does, and turns
  it into an `ErrorException`. The check that does so was previously unreachable
* `setOptions()` is documented as returning `XF\Options`, which is what it has always returned;
  the docblock said `array`. `swap()` is documented as taking and returning `mixed`, which
  covers the closures and the config array it has always accepted
* `app()` is documented as returning `Hampel\Testing\App`, which is the class the framework boots
* bugfix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* bugfix: `fakesHttpByUrl()` ignored Guzzle's `sink`, so code downloading to a file - which is
  what XF\Http\Reader::getUntrusted($url, $limits, $saveTo) does - received the response and
  wrote nothing, while every request assertion still passed
* bugfix: a second `fakesHttp()` or `fakesHttpByUrl()` call in one test had no effect, because
  XenForo's cached `reader` still held the first fake's client; the failure surfaced later as
  "Mock queue is empty"
* bugfix: every user built by `buildVisitor()` / actingAs* landed on permission combination id 1 -
  the forum's real guest combination. Permissions granted to one built user therefore applied to
  all of them, and a permission the test never granted was read from the development forum rather
  than denied, so the same test could pass on one forum and fail on another. Each built user now
  gets its own combination id; pass `permission_combination_id` yourself to opt out
* bugfix: `fakesEvents()` recorded event arguments by reference, since XenForo fires extension
  points as `$app->fire('event', [&$args])` and copying an array preserves the references in it.
  An assertion therefore saw whatever the caller left in the variable after the event, not what
  was fired. Arguments are now recorded as they were at the moment of firing; objects are still
  recorded as the same instance
* bugfix: `fakesMail()` did not disable mail queueing, so mail sent with `queue()` was enqueued as a
  `MailSend` job and never reached the test transport - the assertions then reported "The expected
  mail was not sent". `enableMailQueue` is a `config.php` value, not an option, and `setOption()` cannot
  reach it. Mail sent with `send()` was unaffected, which is why this survived since 2024; `queue()` is
  what batch and job code normally calls. The package had no mail test at all - there is one now
* bugfix: `swapFs()` and `mockFs()` returned the real local filesystem adapter, rather than the fake,
  if anything had already resolved the filesystem. XenForo builds its mounts once and caches them
  under `fs`, so rewriting the config did not reach them. A test in that position read and wrote
  the real data directory - exactly the side effects the helpers exist to prevent - and nothing
  reported it
* bugfix: `mockRepository()` stored the mock under the identifier it was given, but `getRepository()`
  normalises before looking one up. A spelling XenForo accepts everywhere else - 'XF:UserRepository',
  or the full class name - therefore registered a mock nothing consulted: the real repository ran and
  the unmet expectations were never reported. Identifiers are now normalised the same way XenForo
  normalises them
* bugfix: `mockService()` built an untyped Mockery double when the short name resolved to a class that
  does not exist, so a misspelled service name produced a test which passed while asserting against
  nothing. It now throws a `LogicException`. The mock is also typed as the class XenForo would really
  have built, resolved through the class alias map and the extension chain
* `assertErrorLogged()` and `assertErrorNotLogged()` no longer require a message, matching
  `assertExceptionLogged()` - omit it to assert that any error at all was, or was not, logged
* error and exception handlers are restored after each test. This is what makes PHPUnit 11 and
  12 support possible rather than a separate fix: `XF::start()` installs handlers and never
  removes them, which PHPUnit 11 onwards reports as risky on every test that boots XenForo.
  PHPUnit 10 does not report it, so the symptom cannot be reproduced on 3.0.3
* `phpunit.xml` now fails the suite on deprecations, notices, warnings, risky tests, PHPUnit's own
  deprecations (`failOnPhpunitDeprecation`) and a run which executes no tests (`failOnEmptyTestSuite`).
  The last one covers the only genuinely silent case: a suite that has stopped collecting tests
  exits 0 by default and reads as passing
* the `tests/Feature` directory is included, which PHPUnit requires in order to run. It ships a
  `.gitkeep` so that it survives being committed - git does not track empty directories, so a
  `tests/Feature` you create by hand is absent in every clone, including CI. Commit the `.gitkeep`
* the `fakes*` helpers and `swapFs()` now return the object they document, rather than the
  closure the container had not yet resolved
* code style is now XenForo's own, applied with `xenforo-ltd/xf-cs-fixer`
* `league/flysystem-memory` 2.0 and above are rejected - XenForo 2.3 ships Flysystem 1.x

**Breaking changes:**
* minimum PHP version is now 8.3. If your addon pins `config.platform.php` below 8.3, Composer
  cannot install this package at all - the solve fails outright. Raising that pin is not
  dev-only in effect: it also lets Composer select **runtime** dependencies above the PHP
  version your addon declares, and those ship in your release zip. After raising it, check that
  every package in `composer.lock`'s `packages` array still satisfies your addon's own PHP floor,
  and cap any that do not. If you cannot raise it, the 3.x line carries the fixes above that apply
  to v3 - see 3.0.4 and 3.0.5
* PHPUnit 12 is now allowed, and for most addons this package is the only thing that pins PHPUnit
  at all - so a composer update will select 12 where it used to select 10. PHPUnit 12 no longer
  reads metadata from doc comments, so tests using `@dataProvider`, `@depends`, `@covers` or `@group` error
  rather than run. Convert them to attributes (`#[DataProvider]` and friends, understood by 10, 11 and
  12) or pin `phpunit/phpunit` yourself. PHPUnit 11 reports these as deprecations and still exits 0,
  which is why the supplied `phpunit.xml` now sets `failOnPhpunitDeprecation`
* `makeEntity()` and `createEntity()` are new **protected** methods on `TestCase`. If your test
  classes already define a method of either name, PHP refuses to load them - a private helper cannot
  narrow a protected parent - and the run dies at class load with `Access level to ...` before any
  test executes. Rename yours. **Check which one you are matching before deleting yours to inherit
  ours:** `createEntity()` saves and `makeEntity()` does not, so a local helper that only builds maps
  to `makeEntity()`. Inheriting `createEntity()` in its place compiles, looks right, and silently
  turns every in-memory build into a database write
* `phpunit.xml` has been updated and should be re-copied into your addon - or diffed against yours if
  you have customised it, since re-copying discards your changes. The diff is small
* the files in tests/ have been restyled, so a diff against your own copies will show
  formatting changes as well as the changes described above

3.0.5 (2026-09-04)
------------------

A documentation and packaging fix. No code changes to the framework itself.

* the `tests/Feature` directory is now shipped, with a `.gitkeep` so it survives being committed.
  `phpunit.xml` declares a Feature test suite, and PHPUnit refuses to run at all when the directory
  is missing - it reports `Test directory "..." not found` and exits without running anything. Since
  git does not track empty directories, following the README exactly produced a suite that never ran
* docs: the README's install command was `cp` rather than `cp -r`, which simply fails on a directory
* docs: the README's `fakesMail()` example was v1.x Swiftmailer code, swapping a `mailer.queue`
  container key that was removed in 3.0.0, and described the test transport as implementing
  `\Swift_Transport`. It extends Symfony Mailer's `AbstractTransport`
* docs: the README's `phpunit.xml` example was the PHPUnit 9 format, with attributes that no longer
  exist in the PHPUnit 10 this package requires
* docs: `isolateAddon` was still listed in the README as an available helper. It was removed in
  3.0.0 - use the `$addonsToLoad` property in `tests/TestCase.php` instead
* docs: `swapFs()` needs `league/flysystem-memory` in your own `require-dev`, which neither the
  README nor `DOCS.md` mentioned - it is a Composer suggestion, so it is not installed for you
* docs: the source and issue links pointed at Bitbucket; the package is on GitHub

3.0.4 (2026-09-04)
------------------

A maintenance release for the v3 line, for anyone who cannot take 4.0's PHP 8.3 floor. Every fix
below also ships in 4.0.0, which is where they were made; this is the subset that applies to helpers
v3 has. No new helpers, and no change to the PHP or PHPUnit requirements. On PHP 8.3 or newer,
prefer 4.x.

* bugfix: `fakesRegistry()` failed with a fatal error - `DataRegistry` did not match the XenForo 2.3 method
  signatures, so the class could not be declared. The helper has been unusable for the whole v3 line
* bugfix: mail assertions failed with a `TypeError` once any mail had been sent - the test transport
  assigned a string over the array the assertions count, which also meant only the last mail was kept
* bugfix: `fakesMail()` did not disable mail queueing, so mail sent with `queue()` was enqueued as a
  `MailSend` job and never reached the test transport - the assertions then reported "The expected mail
  was not sent". `enableMailQueue` is a `config.php` value, not an option, and `setOption()` cannot reach it.
  Mail sent with `send()` was unaffected, which is why this went unnoticed; `queue()` is what batch and job
  code normally calls
* bugfix: `assertJobQueued()` with a count called `assertJobsQueuedTimes()`, which does not exist
* bugfix: `assertExceptionLogged()`, `assertActionLogged()` and `assertChangeLogged()` with a count passed
  the count as the identifier, so they silently asserted something other than what was asked
* bugfix: `swapFs()` and `mockFs()` returned the real local filesystem adapter, rather than the fake, if
  anything had already resolved the filesystem. XenForo builds its mounts once and caches them under
  `fs`, so rewriting the config did not reach them. A test in that position read and wrote the real
  data directory - exactly the side effects the helpers exist to prevent - and nothing reported it
* bugfix: a second `fakesHttp()` call in one test had no effect, because XenForo's cached `reader` still
  held the first fake's client; the failure surfaced later as "Mock queue is empty". The same applied to
  a first fake installed after the `reader` had resolved
* bugfix: `mockRepository()` stored the mock under the identifier it was given, but `getRepository()`
  normalises before looking one up. A spelling XenForo accepts everywhere else - 'XF:UserRepository', or
  the full class name - therefore registered a mock nothing consulted: the real repository ran and the
  unmet expectations were never reported
* bugfix: `mockService()` built an untyped Mockery double when the short name resolved to a class that
  does not exist, so a misspelled service name produced a test which passed while asserting against
  nothing. It now throws a `LogicException`. The mock is also typed as the class XenForo would really have
  built, resolved through the class alias map and the extension chain
* bugfix: the `fakes*` helpers and `swapFs()` returned the closure handed to `swap()` rather than the object
  the container builds from it, so the documented return type was never what came back
* bugfix: `Job\Manager::runByIds()` returned null where an array was documented, and the docblocks
  referenced a `Hampel\Testing\Job\JobResult` class which has never existed
* bugfix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* docs: `DOCS.md` listed `assertMailQueued()`, `assertMailQueuedTimes()`, `assertMailNotQueued()` and
  `assertNoMailQueued()`. None have existed since v3.0.0 removed the queue fake, and calling one is a
  fatal. The fakesMail example also asserted against `getTo()` as a Swiftmailer `email => name` map; it
  returns Symfony Address objects, so the example could never match
* an integration test suite has been added covering every fix above. It needs a XenForo install
  (`XF_ROOT=/srv/www/myforum composer integration`), skips without one, and is export-ignored so it
  never reaches an addon

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
