CHANGELOG
=========

3.0.6 (2026-09-10)
------------------

Backported from 4.0.2.

* bugfix: a response faked with `fakesHttp()` reached the caller with its body already read, so
  `$response->getBody()->getContents()` returned an empty string. XenForo's `XF\Http\Reader` always
  gives Guzzle a sink - `php://temp` when you pass no `$saveTo` - and Guzzle's `MockHandler`, behind
  `fakesHttp()`, reads the body to the end to write it there and does not rewind it. Real Guzzle
  returns the rewound sink as the body, so production was never affected. `getContents()` is how
  XenForo core reads a response, so an add-on reading one the same way could not be tested through
  the fake. A `(string)` cast was unaffected, because it seeks to the start before reading

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
  README nor DOCS.md mentioned - it is a Composer suggestion, so it is not installed for you
* docs: the source and issue links pointed at Bitbucket; the package is on GitHub

3.0.4 (2026-09-04)
------------------

A maintenance release for the v3 line. Backported from the unreleased 4.0.0 branch, limited to
helpers that exist in v3 - no new helpers, and no change to the PHP or PHPUnit requirements. If you can
run PHP 8.3, prefer 4.x when it is released.

* bugfix: fakesRegistry() failed with a fatal error - DataRegistry did not match the XenForo 2.3 method
  signatures, so the class could not be declared. The helper has been unusable for the whole v3 line
* bugfix: mail assertions failed with a TypeError once any mail had been sent - the test transport
  assigned a string over the array the assertions count, which also meant only the last mail was kept
* bugfix: fakesMail() did not disable mail queueing, so mail sent with queue() was enqueued as a
  MailSend job and never reached the test transport - the assertions then reported "The expected mail
  was not sent". enableMailQueue is a config.php value, not an option, and setOption() cannot reach it.
  Mail sent with send() was unaffected, which is why this went unnoticed; queue() is what batch and job
  code normally calls
* bugfix: assertJobQueued() with a count called assertJobsQueuedTimes(), which does not exist
* bugfix: assertExceptionLogged(), assertActionLogged() and assertChangeLogged() with a count passed
  the count as the identifier, so they silently asserted something other than what was asked
* bugfix: swapFs() and mockFs() returned the real local filesystem adapter, rather than the fake, if
  anything had already resolved the filesystem. XenForo builds its mounts once and caches them under
  `fs`, so rewriting the config did not reach them. A test in that position read and wrote the real
  data directory - exactly the side effects the helpers exist to prevent - and nothing reported it
* bugfix: a second fakesHttp() call in one test had no effect, because XenForo's cached `reader` still
  held the first fake's client; the failure surfaced later as "Mock queue is empty". The same applied to
  a first fake installed after the reader had resolved
* bugfix: mockRepository() stored the mock under the identifier it was given, but getRepository()
  normalises before looking one up. A spelling XenForo accepts everywhere else - 'XF:UserRepository', or
  the full class name - therefore registered a mock nothing consulted: the real repository ran and the
  unmet expectations were never reported
* bugfix: mockService() built an untyped Mockery double when the short name resolved to a class that
  does not exist, so a misspelled service name produced a test which passed while asserting against
  nothing. It now throws a LogicException. The mock is also typed as the class XenForo would really have
  built, resolved through the class alias map and the extension chain
* bugfix: the fakes* helpers and swapFs() returned the closure handed to swap() rather than the object
  the container builds from it, so the documented return type was never what came back
* bugfix: Job\Manager::runByIds() returned null where an array was documented, and the docblocks
  referenced a Hampel\Testing\Job\JobResult class which has never existed
* bugfix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* docs: DOCS.md listed assertMailQueued(), assertMailQueuedTimes(), assertMailNotQueued() and
  assertNoMailQueued(). None have existed since v3.0.0 removed the queue fake, and calling one is a
  fatal. The fakesMail example also asserted against getTo() as a Swiftmailer `email => name` map; it
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
