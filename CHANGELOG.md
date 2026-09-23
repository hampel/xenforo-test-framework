# Changelog

## 5.6.1 (unreleased)

* `dispatch()`, `callAction()` and `runJobToCompletion()` no longer write XenForo's `autoJobRun`
  registry row when they run deferred work. Under `UsesDatabaseTransactions` on MariaDB 11.8 or
  later that write could fail with `Record has changed since last read`, on some runs and not
  others, when the forum's own traffic or cron had touched the row
* docs: `renderRawReply()` on a view that streams its output returns an `XF\Http\ResponseStream`,
  read with `getContents()`
* docs: `UsesDatabaseTransactions` puts the test in a race for any row the live forum also writes

## 5.6.0 (2026-09-23)

* `callAction()` takes an optional `$files` argument, so an action reading
  `$this->request->getFile()` can be tested with a file. Build the entry with the new
  `uploadedFile()` or `uploadedFileFromPath()`, which write a real temporary file and remove it
  when the test finishes
* new `assertNoUnresolvedPhrases()` asserts that no phrase rendered as its own key, ignoring the
  template names the templater embeds for an administrator. Named so it does not collide with an
  `assertPhrasesResolved()` of your own
* new `renderRawReply()` renders a reply through the raw renderer and returns the response, with
  its body, for an action whose view builds its output in `renderRaw()`
* docs: `callAction()`'s action argument is the controller's own action - a route's `action_prefix`
  is not applied
* docs: `UsesDatabaseTransactions` cannot roll back a file, including what an entity compiles into
  the code cache when it saves
* docs: `getHttpRequests()` and `getHttpHistory()` read the requests a test sent, and the history
  is kept for the whole test rather than reset by a second `fakesHttp()`
* docs: the README's install example requires `^5.6`

## 5.5.0 (2026-09-23)

* `fakesHttp()` and `fakesHttpByUrl()` now also fake clients built with
  `$app->http()->createClient()`, which an add-on uses when it needs its own `base_uri`, headers or
  timeouts. Previously only the shared `client` and `clientUntrusted` were faked, so those requests
  were sent for real. A client created before the fake is installed still uses the real handler
* the fake reaches those clients while `fakesEvents()` is active as well
* docs: on a development install, a template with an `_output/` copy renders from that copy, and a
  template changed only in the database is replaced before it renders. Template modifications still
  need `xf-dev:import`
* docs: the README's install example requires `^5.5`

## 5.4.0 (2026-09-22)

* `renderTemplate()`, `renderReply()` and `renderMacro()` set the `xf` template parameter as XenForo
  does for a page, from `getGlobalTemplateData()`, so `$xf.options`, `$xf.visitor` and the rest are
  available to the template. It is rebuilt for every render, and an `xf` parameter the test set
  itself is left alone
* a visitor a test set with `\XF::setVisitor()` is cleared after the test, rather than carried
  into the next one
* docs: the README's install example requires `^5.4`

## 5.3.0 (2026-09-22)

* new `runJobToCompletion()` runs a job until it completes and returns its final `JobResult`. Each
  pass is a new instance built from the previous pass's data, and work queued with
  `\XF::runOnce()` runs after each pass. A failed result, a job that does not complete within
  `maxPasses`, and a job that does not exist throw a `LogicException`
* docs: `callAction()` accepts an action name containing slashes
* docs: `UsesDatabaseTransactions` rolls back a nested commit even when the code then throws
* docs: the README's install example requires `^5.3`

## 5.2.0 (2026-09-22)

* `dispatch()` and `callAction()` take an optional `$server` argument - request server values such
  as `REMOTE_ADDR`, `HTTP_USER_AGENT` and `HTTP_REFERER`, merged over the defaults. `REQUEST_METHOD`
  cannot be changed this way
* docs: on XenForo 2.3 `Request::getFromSearch()` always returns an empty string

## 5.1.0 (2026-09-21)

* new `callAction()` calls a controller action directly with a `POST` request and returns its
  reply, for testing saves, toggles and deletes. It skips `preDispatch()`, so neither the CSRF
  check nor the controller's permission check runs - use `dispatch()` to test those. A validation
  failure comes back as an `Error` reply with its errors keyed by field, a reply thrown as
  `XF\Mvc\Reply\Exception` comes back as that reply, a `Reroute` is followed, and work queued with
  `\XF::runOnce()` has run before the reply is returned
* docs: the README describes running each test class on its own to find a test extending plain
  `PHPUnit\Framework\TestCase` that depends on an earlier test having booted XenForo

## 5.0.0 (2026-09-21)

* the framework now boots the application: `Hampel\Testing\TestCase::createApplication()` reads
  `$rootDir` and `$addonsToLoad` from your test class, and `tests/CreatesApplication.php` is no
  longer part of the scaffold. An existing copy keeps working. To use the framework's boot, delete
  that file and the `use CreatesApplication;` line in `tests/TestCase.php`. To keep a boot of your
  own, override `createApplication()` and pass `['xf-addons' => $this->addonsToLoad]` to
  `XF::setupApp()`
* `$rootDir` and `$addonsToLoad` are declared on `Hampel\Testing\TestCase`, with the scaffold's
  defaults
* fix: add-on isolation now filters code event listeners. Previously every installed add-on's
  `app_setup` listener ran regardless of `$addonsToLoad`
* fix: with `$addonsToLoad` set, a class extension registered on a pre-2.3 class name -
  `XF\Service\User\Login` rather than `LoginService` - was not applied, and XenForo's own class ran
  instead. Also fixed in 4.3.2 and 3.0.8
* `TestCase` throws a `LogicException` when `$addonsToLoad` is set but the application was booted
  without it, as happens with a `tests/CreatesApplication.php` older than 2.1.0
* new `Hampel\Testing\App::isolatedAddOnIds()` returns the add-on ids the application was booted
  with
* new `Hampel\Testing\Extension::forAddOns()` builds an extension carrying only the given add-ons'
  listeners and class extensions
* the `Concerns\InteractsWithExtension` trait has been removed; the application installs the
  filtered extension while it boots
* `mockDatabase()` no longer requires the mock's `fetchAll` to return an array
* docs: a template error is written to the real `xf_error_log`; `fakesErrors()` or
  `UsesDatabaseTransactions` prevents it
* docs: a test extending plain `PHPUnit\Framework\TestCase` cannot load add-on classes unless an
  earlier test has booted XenForo

**Breaking changes:**
* with `$addonsToLoad` set, listeners belonging to add-ons not named there no longer run. A test
  that relied on one - a container entry it registered, an option it set, a class it extended -
  needs that add-on added to `$addonsToLoad`
* a suite that sets `$addonsToLoad` with a `tests/CreatesApplication.php` older than 2.1.0 now fails
  with a `LogicException`. Delete that file and its `use` line
* `Concerns\InteractsWithExtension` has been removed. Remove any `use` of it from your own test
  classes
* where the application is booted with a different add-on list than `$addonsToLoad`, the list the
  application was given decides which listeners and class extensions are active

## 4.3.2 (2026-09-21)

* fix: with `$addonsToLoad` set, a class extension registered on a pre-2.3 class name -
  `XF\Service\User\Login` rather than `LoginService` - was not applied, and XenForo's own class ran
  instead

## 4.3.1 (2026-09-20)

Documentation only.

* docs: `spy()` is documented
* docs: the README states that add-on isolation does not filter code event listeners
* docs: upgrade notes have moved from the README to a new `UPGRADING.md`
* docs: the notes on `failOnRisky` and `failOnDeprecation` have moved into the README's installation
  section
* docs: the README's note on `app.classType` is corrected
* docs: the README's "Compatibility" heading is spelled correctly
* the 3.0.7 entry has been added to this file

## 4.3.0 (2026-09-20)

* fix: `dispatch()` now runs work queued with `\XF::runOnce()` during the dispatch, and rethrows any
  exception it raises
* fix: work left in `\XF::$runOnce` by one test is discarded in teardown rather than running in the
  next
* fix: `renderTemplate()` throws when a template renders nothing because it failed - a PHP error, a
  missing macro or included template, or an exception, including one XenForo renders as markup when
  `$config['debug']` is set
* a render that raised an error but still produced markup is returned as normal. New
  `assertNoTemplateErrors()` asserts that no template the test rendered raised an error
* new `renderMacro()` renders a single macro from a template, and throws for a macro that does not
  exist
* new `pageParam()` reads a value a rendered template set with `<xf:title>`, `<xf:description>`,
  `<xf:h1>` or `<xf:pageaction>`
* `assertReplyIsRedirect()` takes a `type`, `permanent` or `temporary`. A redirect reply's
  `getResponseCode()` is always `200`, so assert the type rather than the code
* every reply assertion takes an optional failure `message` as its last argument
* `assertReplyIsError()`'s third parameter is renamed `$errorText`. A call passing it by name must
  change
* `mockery/mockery` is now `^1.6`
* docs: the `fakesJobs()` example is corrected, and `assertJobQueued()` documents that it matches
  the class string exactly as queued - `'XF:FileCleanUp'` and `\XF\Job\FileCleanUp::class` do not
  match each other
* docs: a public route needs the visitor to hold `general.view`
* docs: code that checks for `XF\Pub\App` does not run under the framework, which boots the base
  `XF\App`
* docs: a class extending a XenForo class cannot be declared at test file scope

## 4.2.0 (2026-09-19)

* new `renderTemplate()` renders a template to HTML, and `renderReply()` renders the template a
  dispatched reply named, with `assertSee()`, `assertDontSee()`, `assertSeeText()`,
  `assertDontSeeText()`, `assertSeeInOrder()` and `textOf()` to assert on it. Expected values are
  escaped by default, matching `XF::escapeString()`
* `renderTemplate()` requires the `type:title` form, and throws for a template that does not exist
* new `assertTemplateModificationApplied()` asserts that a template modification has applied, using
  its apply count
* rendering covers the template, not the page wrapper around it

## 4.1.0 (2026-09-18)

* new `dispatch()` runs a route through XenForo's dispatcher and returns the reply, with
  `assertReplyIsView()`, `assertReplyTemplate()`, `assertReplyViewClass()`, `assertReplyParam()`,
  `replyParam()`, `assertReplyIsRedirect()`, `assertReplyIsError()`, `assertReplyIsMessage()`,
  `assertReplyIsApiResult()`, `replyApiResult()` and `replyErrors()` to assert on it. Route
  parameters are passed as the third argument. Public, admin and api routes are supported, reroutes
  are followed, and the controller's `preDispatch()` checks run
* new `setVisitorAdminPermissions()` grants admin permissions to a built user
* new `actingAsApiKey()` runs api dispatches as a given api key, and restores `\XF::$apiKey`
  afterwards
* `dispatch()` returns the reply without rendering it
* `dispatch()` sends a `GET`; an action requiring `POST` returns a 405
* docs: `DOCS.md` no longer says that code calling `save()` cannot be tested - use
  `UsesDatabaseTransactions`

## 4.0.3 (2026-09-17)

* fix: a user built by `buildVisitor()`, `actingAsMember()` or `actingAsGuest()` could inherit an
  administrator record from the forum the tests run against. `hasAdminPermission()` is now always
  false for a built user; pass a user you loaded yourself to `actingAs()` for a real administrator

## 4.0.2 (2026-09-10)

* fix: under `fakesHttp()` and `fakesHttpByUrl()`, `$response->getBody()->getContents()` returned an
  empty string

## 4.0.1 (2026-09-05)

* docs: `isolateAddon` has been removed from the README's helper list - it was removed in 2.1.0; use
  `$addonsToLoad`
* docs: the README's helper lists include the helpers added in 4.0.0
* docs: the README notes that `swapFs()` needs `league/flysystem-memory`
* the scaffold ships `tests/Feature/ExampleTest.php` in place of `tests/Feature/.gitkeep`
* the 3.0.4 and 3.0.5 entries have been added to this file

## 4.0.0 (2026-09-05)

* new `makeEntity()` and `createEntity()` helpers for building entities with given values
* new `fakesHttpByUrl()` - chooses the http response by request URL rather than by call order
* new `fakesEvents()` - records code events and stops them reaching listeners, with
  `assertEventFired()`, `assertEventFiredTimes()`, `assertEventNotFired()` and
  `assertNoEventsFired()`
* new `actingAs()`, `actingAsMember()` and `actingAsGuest()` helpers - run a test as a given user,
  with permissions granted in memory rather than read from the database
* new `setVisitorPermissions()`, `setVisitorContentPermissions()` and `buildVisitor()` helpers
* new `setConfig()` helper - sets a `config.php` value
* new `UsesDatabaseTransactions` trait - wraps each test in a transaction and rolls it back
* new `assertDatabaseHas()`, `assertDatabaseMissing()` and `assertDatabaseCount()` assertions
* PHPUnit 11 and 12 are now supported
* fix: `fakesRegistry()` failed with a fatal error on XenForo 2.3
* fix: mail assertions failed with a `TypeError` once any mail had been sent
* fix: `assertJobQueued()` with a count called a method that does not exist
* fix: `assertExceptionLogged()`, `assertActionLogged()` and `assertChangeLogged()` with a count
  asserted against the wrong value
* fix: `Job\Manager::runByIds()` returned null where an array was documented
* fix: `DataRegistry::delete()` failed when the registry had a cache
* fix: `fakesHttpByUrl()` ignored Guzzle's `sink` option
* fix: a second `fakesHttp()` or `fakesHttpByUrl()` call in one test had no effect
* fix: every user built by `buildVisitor()` shared the forum's guest permission combination. Each
  now gets its own; pass `permission_combination_id` yourself to choose one
* fix: `fakesEvents()` recorded event arguments by reference; they are now recorded as they were
  when the event fired
* fix: `fakesMail()` did not capture mail sent with `queue()`
* fix: `swapFs()` and `mockFs()` returned the real filesystem adapter if the filesystem had already
  been resolved
* fix: `mockRepository()` did not apply when the repository was named in a different spelling from
  the one it was mocked under
* fix: `mockService()` for a class that does not exist returned an untyped double; it now throws a
  `LogicException`, and the mock is typed as the class XenForo would build
* fix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* `Job\Manager::runQueue()`, `runUnique()` and `runJobEntry()` return `null` explicitly
* `swapFs()`, `mockFs()`, `fakesRegistry()` and `buildVisitor()` throw a `LogicException` when
  XenForo returns an object of an unexpected type
* `app()` throws a `LogicException` when called before the application has booted
* `Error::logException()` accepts a non-throwable, as XenForo's own signature does
* docblocks corrected for `_enqueue()`, `setOptions()`, `swap()` and `app()`
* `assertErrorLogged()` and `assertErrorNotLogged()` no longer require a message
* error and exception handlers are restored after each test
* `phpunit.xml` fails the suite on deprecations, notices, warnings, risky tests, PHPUnit
  deprecations (`failOnPhpunitDeprecation`) and a run that executes no tests
  (`failOnEmptyTestSuite`)
* the `tests/Feature` directory is included, with a `.gitkeep`; commit it with your tests
* the `fakes*` helpers and `swapFs()` return the object they document
* code style is now XenForo's own, applied with `xenforo-ltd/xf-cs-fixer`
* `league/flysystem-memory` 2.0 and above are rejected

**Breaking changes:**
* minimum PHP version is now 8.3. If your addon pins `config.platform.php` below 8.3, Composer
  cannot install this package. Raising the pin can also let Composer select runtime dependencies
  above your addon's own PHP floor, so check `composer.lock`'s `packages` afterwards. The 3.x line
  remains available for PHP 8.1 and 8.2
* PHPUnit 12 is now allowed. PHPUnit 12 ignores doc-comment metadata, so tests using
  `@dataProvider`, `@depends`, `@covers` or `@group` must convert them to attributes, or pin
  `phpunit/phpunit` yourself
* `makeEntity()` and `createEntity()` are new protected methods on `TestCase`. If your test classes
  define a method of either name, rename yours. `createEntity()` saves and `makeEntity()` does not
* `phpunit.xml` has changed and should be re-copied into your addon, or diffed against yours
* the files in `tests/` have been restyled, so a diff against your copies shows formatting changes
  too

## 3.0.8 (2026-09-21)

Backported from 4.3.2.

* fix: with `$addonsToLoad` set, a class extension registered on a pre-2.3 class name -
  `XF\Service\User\Login` rather than `LoginService` - was not applied, and XenForo's own class ran
  instead

## 3.0.7 (2026-09-20)

Backported from 4.3.0.

* `mockery/mockery` is now `^1.6`

## 3.0.6 (2026-09-10)

Backported from 4.0.2.

* fix: under `fakesHttp()`, `$response->getBody()->getContents()` returned an empty string

## 3.0.5 (2026-09-04)

Documentation and packaging only.

* the `tests/Feature` directory is now shipped, with a `.gitkeep`. Without it, PHPUnit stops with
  `Test directory "..." not found` and runs nothing
* docs: the README's install command uses `cp -r`
* docs: the README's `fakesMail()` example is updated for Symfony Mailer
* docs: the README's `phpunit.xml` example is updated for PHPUnit 10
* docs: `isolateAddon` has been removed from the README's helper list - use `$addonsToLoad`
* docs: `swapFs()` needs `league/flysystem-memory` in your own `require-dev`
* docs: the source and issue links point at GitHub

## 3.0.4 (2026-09-04)

A maintenance release of the v3 line for PHP 8.1 and 8.2. The same fixes are in 4.0.0.

* fix: `fakesRegistry()` failed with a fatal error on XenForo 2.3
* fix: mail assertions failed with a `TypeError` once any mail had been sent, and only the last mail
  was kept
* fix: `fakesMail()` did not capture mail sent with `queue()`
* fix: `assertJobQueued()` with a count called a method that does not exist
* fix: `assertExceptionLogged()`, `assertActionLogged()` and `assertChangeLogged()` with a count
  asserted against the wrong value
* fix: `swapFs()` and `mockFs()` returned the real filesystem adapter if the filesystem had already
  been resolved
* fix: a second `fakesHttp()` call in one test had no effect
* fix: `mockRepository()` did not apply when the repository was named in a different spelling from
  the one it was mocked under
* fix: `mockService()` for a class that does not exist returned an untyped double; it now throws a
  `LogicException`
* fix: the `fakes*` helpers and `swapFs()` returned a closure rather than the object they document
* fix: `Job\Manager::runByIds()` returned null where an array was documented
* fix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* docs: `DOCS.md` no longer lists the `assertMailQueued*()` assertions removed in 3.0.0, and the
  `fakesMail()` example is corrected

## 3.0.3 (2024-12-30)

* bugfix: some code related to mail queueing has been removed since we no longer use it 

## 3.0.2 (2024-08-11)

* new expanded extension class - use global static maps to keep track of extensions and aliases between test runs

## 3.0.1 (2024-08-10)

* for some reason our extension class wasn't working correctly - we'll just remove it

## 3.0.0 (2024-08-10)

* compatibilty with XenForo v2.3
* Hampel\Testing\Job\Manager updated to match changes in XF\Job\Manager, particularly XF\Job\JobParams
* Hampel\Testing\Mail\Transport replaced with Hampel\Testing\Mail\TestTransport which implements Symfony mail transport
* Hampel\Testing\Mail\Queue removed - we now simply disable queueing which results in all mails being sent via the test
  transport

## 2.2.0 (2024-07-10)

* php 8.3 compatibility fix - ReflectionProperty::setValue with a single parameter is now deprecated; but as of php 8.1 
  we can simply use ReflectionClass::setStaticValue without needing to explicitly set private or protected properties 
  as accessible
* we now need to use a minimum of php 8.1
* upgrade to PHPUnit v10.x

## 2.1.0 (2024-03-14)

* allow swapping subcontainer keys using either a class or a string to define the app container key
* new option in TestCase - $addonsToLoad
* new implementation of addon isolation limiting composer autoload and extension/listener loading based on which addons 
  are specified in TestCase

**Breaking changes:**
* isolateAddon function has been removed and replaced by an option in `TestCase.php`
* both `TestCase.php` and `CreatesApplication.php` will need to be updated in addons based on the new versions in this 
  package

## 2.0.2 (2020-09-23)

* should be returning the instance we created when swapping or faking classes

## 2.0.1 (2020-09-15)

* Job Manager - getUniqueJob wasn't returning the job
* don't serialize job paramaters

## 2.0.0 (2020-08-28)

* compatibility changes for XenForo v2.2
* XF 2.2 implements Swiftmailer 6 which changes some method/interface signatures

## 1.2.2 (2020-08-04)

* fixed missing use clause in `Hampel\Testing\Concerns\InteractsWithFilesystem` trait

## 1.2.1 (2020-07-25)

* fixed typo in function name: `Hampel\Testing\Concerns\InteractsWithSimpleCache::assertSimpleCacheEqual()` => 
`assertSimpleCacheEquals()` and `assertSimpleCacheNotEqual()` => `assertSimpleCacheNotEquals()`
* close the database connection on tearDown to avoid connection limit issues (unless it's been mocked)

## 1.2.0 (2019-12-13)

 * Feature: added new functionality to Interacts with Container
   * mockService
 * Feature: Interacts with Http - adds:
   * fakesHttp  

## 1.1.0 (2019-11-26)

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

## 1.0.0 (2019-11-19)

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
