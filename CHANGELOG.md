CHANGELOG
=========

5.0.0 (unreleased)
------------------

* **the framework boots the application now, and `tests/CreatesApplication.php` has left the
  scaffold.** `Hampel\Testing\TestCase::createApplication()` reads `$rootDir` and `$addonsToLoad`
  off your test class and does the rest, so the scaffold is one file instead of two. That second
  file existed to be copied, and a copied file cannot be updated by a release: the v2.1.0 add-on
  isolation arguments went into it in 2022, and add-ons are still running the 2020 version today,
  quietly without the isolation they were configured for. **Nothing breaks if you do nothing** - a
  trait method wins over an inherited one in PHP, so an add-on keeping its own copy keeps exactly
  the boot it has. To adopt the framework's, delete that file and the `use CreatesApplication;`
  line from your `tests/TestCase.php`. If you need a boot of your own, `createApplication()` is an
  ordinary method: override it and pass `['xf-addons' => $this->addonsToLoad]` to `XF::setupApp()`
  so isolation still reaches the application
* `$rootDir` and `$addonsToLoad` are declared on `Hampel\Testing\TestCase` with the scaffold's
  own defaults, so the copies in your `tests/TestCase.php` override them rather than define them
* docs: **a template error is written to the forum's real `xf_error_log`**, and `DOCS.md` never said
  so. XenForo's error handler turns the templater's `E_USER_WARNING` into an `ErrorException`, the
  templater catches it and calls `$app->logException()`, and on a development forum that logger is
  the real one - so rendering a core template without the parameters it expects leaves rows behind
  on an install other people share. Found by trialling this release against a consumer suite, which
  was writing **71 rows on every run** from one test. `fakesErrors()` or `UsesDatabaseTransactions`
  prevents it, and `assertNoTemplateErrors()`'s section now says which and why
* `mockDatabase()` no longer needs its `fetchAll` to return an array rather than `null`. Rebuilding
  the entity manager used to re-run the listener query behind `$addonsToLoad` through your mock,
  and a `null` there failed inside the framework rather than in your test - `DOCS.md` has said so
  since 4.0.0. The filtered extension is resolved while the application boots now, so the rebuilt
  manager reads the resolved instance instead of running that query again, and a mock with no
  expectations at all works
* fix: **add-on isolation filters code event listeners now, and never did before.** Naming add-ons
  in `$addonsToLoad` filtered Composer autoloading and class extensions, and this package's
  documentation said that gave complete isolation. It did not. `XF\App::setup()` fires `app_setup`
  as its last step, and the filtered extension was installed later still, from a test-time hook -
  too late to stop a single listener. So every installed add-on's `app_setup` listener ran before
  any test did, registering container entries and occasionally throwing, out of a suite that had
  asked for none of them. Measured on a development forum carrying 13 of them: all 13 listener
  classes loaded, XenForo's own `XFMG` and `XFRM` among them. Filtering `addon.composer` could
  never have covered it either, because an add-on's classes resolve through XenForo's own autoload
  path whether or not its Composer autoloader was registered. `Hampel\Testing\App::setup()` now
  installs the extension before it calls `parent::setup()`
* new `Hampel\Testing\Extension::forAddOns()` builds an extension carrying only the listeners and
  class extensions belonging to the given add-ons. It is where the filtering logic lives now, so
  the boot and the test-time hook share one copy of it
* **a half-finished v2.1.0 scaffold upgrade now fails loudly instead of running without the
  isolation it was configured for.** That upgrade needed both files - `$addonsToLoad` in
  `tests/TestCase.php`, and `tests/CreatesApplication.php` passing it to `XF::setupApp()` - and
  taking one without the other has been silent since 2022. The suite simply ran with every
  installed add-on active, which is the state `$addonsToLoad` was set to avoid, and the collision
  that followed looked like a bug in the add-on. `TestCase` now refuses to run, naming the add-ons
  that went missing and showing the two lines that fix it. Only the missing case is refused: a
  suite that deliberately boots with a different list than the property names is left alone
* new `Hampel\Testing\App::isolatedAddOnIds()` returns the ids the application was actually told
  to keep, which is what makes that check possible
* the `Concerns\InteractsWithExtension` trait is **removed**. Its `setUpExtension()` hook installed
  the filtered extension after the application had booted, which is the defect above; now that
  `App::setup()` installs it beforehand, the hook rebuilt an identical extension from two database
  queries per test and changed nothing. The only case it still covered was the half-upgraded
  scaffold, which no longer gets that far

**Breaking changes:**
* **a suite that names add-ons in `$addonsToLoad` now gets the isolation it asked for**, and that
  is a behaviour change even though it is a fix. A test that was passing because an excluded
  add-on's `app_setup` listener registered a container entry, set an option or extended a class
  will now find that entry absent. If a test breaks on this upgrade, the add-on it was quietly
  relying on belongs in `$addonsToLoad` - which is the question isolation existed to ask
* `tests/CreatesApplication.php` is no longer part of the scaffold, so a fresh install copies one
  file rather than two. An existing copy keeps working untouched, and deleting it is opt-in
* `Concerns\InteractsWithExtension` no longer exists. Nothing needs to change unless your own test
  class `use`d it directly, which the scaffold has never done - `TestCase` composed it for you and
  now does not. One edge, for a suite that boots the application with a different add-on list than
  `$addonsToLoad` names: the list the **application** was given now decides which listeners and
  class extensions are active, where the property used to win at test time
* **a suite in the half-upgraded state stops running at all**, where it used to run with no
  isolation. If your `tests/TestCase.php` sets `$addonsToLoad` and your
  `tests/CreatesApplication.php` predates v2.1.0, every test now errors with an explanation until
  you re-copy that file. This is loud on purpose: the silent version of it has cost people days,
  and a suite in that state was never testing what it claimed to

4.3.1 (2026-09-20)
------------------

A documentation release - nothing in `src/` changed, so it cannot affect how a test runs. Two of the
entries below correct things the previous documentation stated as fact.

* docs: **`spy()` has never been documented.** It has shipped in every release since 1.0.0 and
  appeared in neither `README.md` nor `DOCS.md`, so the only way to find it was to read
  `src/Concerns/InteractsWithContainer.php`. `DOCS.md` now carries a `spy` section beside `mock`,
  the `README` places it between `swap()` and `mock()`, and `integration/SpyTest.php` pins the
  documented behaviour - including that a spy answers `null` for every method it was not told
  about, which is the trade against `mock()` and the part an example has to show
* docs: **add-on isolation does not filter code event listeners, and the documentation said it
  did.** `XF\App::setup()` fires `app_setup` as its last step, while the filtered `extension`
  container key is installed later still - so Composer autoloading and class extensions are
  filtered and listeners are not, and every installed add-on's `app_setup` listener runs whatever
  `$addonsToLoad` says. Measured on a development forum carrying 13 of them: all 13 listener
  classes loaded, and the container kept the entries they registered. `README.md` gains a
  "What isolation does not cover" note, because a consumer meeting this sees a failure inside an
  add-on they never listed, which reads as anything but a framework limitation. Fixing it needs a
  change to the boot sequence and is deferred to the next major version
* docs: upgrade notes for every version back to 2.1 move out of `README.md` and into a new
  `UPGRADING.md`, which ships in the package. They had been duplicated between the README and the
  resource post, and a second copy of an upgrade instruction is how someone ends up following a
  stale one. Only v4.0 and v2.1 require action from you; the file says so at the top
* docs: the `failOnRisky` and `failOnDeprecation` explanation moves into the installation section,
  beside the `phpunit.xml` it describes. It had lived only under "Upgrading", where a first-time
  installer would never meet it
* docs: `README.md`'s section 12 no longer presents `app.classType` as a testability workaround -
  it is XenForo's own idiom, used four times in `XF\App.php` itself
* docs: `README.md`'s "Compatibilty" heading is spelled correctly
* changelog: the 3.0.7 entry, brought across from the `3.x` branch

4.3.0 (2026-09-20)
------------------

* fix: `dispatch()` never ran the work a controller deferred. XenForo drains its run-once queue in
  `XF\Mvc\Dispatcher::dispatchLoop()`, and `dispatch()` resolves reroutes in its own loop instead -
  deliberately, because `dispatchLoop()` catches every controller exception and rolls the
  transaction back with it. So anything queued through `\XF::runOnce()` was simply dropped, and an
  entity `postSave()` rebuilding a cache is the common case. The queue now runs after the dispatch,
  rethrowing, so a failure there fails the test rather than being logged and swallowed
* fix: `\XF::$runOnce` is a static nothing reset, so a closure left in the queue by one test ran
  during the next one, bound to the application the first test had. It is discarded in teardown
* fix: **a template that failed while rendering came back as an empty string.** XenForo's templater
  catches everything a template does wrong - a PHP error, a macro or an included template that does
  not exist, an exception part way through - logs it, and carries on. Where that left nothing, the
  failure arrived as output rather than as a failure: an `assertDontSee()` passed on it while
  `renderTemplate()`'s own guard found the template present and said nothing. It now throws, naming
  what went wrong. A template that *throws* is also caught when `$config['debug'] = true`, where
  XenForo renders the exception as markup
* a render that errored and **still produced markup is returned unchanged**, deliberately: it is
  much the commoner case - 103 of 400 core templates rendered with no parameters raised an error on
  2.3.12, and 101 of those still produced markup - and failing them would turn working suites red
  on a patch release. New `assertNoTemplateErrors()` is the opt-in for the stricter guarantee
* new: `renderMacro()` renders one macro out of a template, with the arguments a caller would pass
  it - for markup that lives in a macro, or where rendering the whole template would need
  parameters the test has no reason to build. A macro that does not exist renders as an empty
  string, so that is refused too
* new: `pageParam()` reads back what a rendered template set with `<xf:title>`, `<xf:description>`,
  `<xf:h1>` or `<xf:pageaction>`. None of them appear in the template's own output - the markup
  around them belongs to the page wrapper - so reading one back is the only way to assert on it
* `assertReplyIsRedirect()` takes a `type`, `permanent` or `temporary`. **A redirect reply carries
  no http status of its own**: `getResponseCode()` answers `200` either way, because the code is
  chosen later by the renderer, which maps permanent to a `301` and temporary to a `303`. So a test
  asserting the code could not tell the two apart, and the failure message no longer prints that
  `200` beside a redirect as though it meant something
* every reply assertion takes an optional `message` as its last argument, added to the failure. In
  a test that dispatches several routes the description says what the reply was but not which route
  produced it, which is the part you need
* **`assertReplyIsError()`'s third parameter is now named `$errorText`**, since `$message` is the
  failure message everywhere else in the family. Positional calls are unaffected; a call passing it
  by name has to change
* `mockery/mockery` is now `^1.6`. The old `^1.0` was never tested and could not work: Mockery
  1.0, 1.1 and 1.2 declare `php >=5.6.0`, so Composer installs them onto a supported PHP and the
  generated mock code is then invalid - `Cannot use "parent" when current class scope has no
  parent`, a fatal before any test runs
* docs: **the `fakesJobs()` example could not run.** It showed the truth-test callback receiving
  a job object and calling `$job->getData()`, where what the fake passes is the array it recorded
  - the parameters are `$job['execute_data']`, and no job is ever constructed. The same example
  also asserted a job name it had not queued. Both fixed, and `assertJobQueued()` now documents
  what it matches on: the class string exactly as the caller named it, so `'XF:FileCleanUp'` and
  `\XF\Job\FileCleanUp::class` are both valid and do not match each other
* docs: a public route needs the visitor to hold `general.view`, which a built visitor does not, so
  a public `dispatch()` refuses with a `403` until the test grants it
* docs: this framework boots the base `XF\App`, so your own code asking which application is
  running takes the other branch - a `templater_global_data` listener opening with
  `if ($app instanceof \XF\Pub\App)` never runs its body here
* docs: a class extending a XenForo class cannot be declared at test file scope. PHPUnit loads
  every test file while building the suite, before XenForo has booted, so the parent does not exist
  yet and the whole run stops with `Class "..." not found`

4.2.0 (2026-09-19)
------------------

* new: `renderTemplate()` renders a template to HTML with no web server, and `renderReply()` renders
  the one a dispatched reply named, with `assertSee()`, `assertDontSee()`, `assertSeeText()`,
  `assertDontSeeText()`, `assertSeeInOrder()` and `textOf()` to assert on the output. This covers
  the two checks a reply cannot: that a **template modification** applied, and that a **phrase
  resolved** rather than rendering as a raw key. The expected value is escaped by default, matching
  `XF::escapeString()`
* a template name XenForo cannot find - a missing title, the wrong type, or a type that does not
  exist - renders as an **empty string with no error**, so an `assertDontSee()` against one would
  pass while testing nothing. `renderTemplate()` throws instead, and it requires the `type:title`
  form rather than guessing the type
* new: `assertTemplateModificationApplied()` asserts a template modification is matching something,
  by reading its apply count. XenForo logs a modification that matches nothing as status `ok`, so
  the status cannot tell you one has silently stopped applying - only the count can
* rendering covers the template, not the whole page: navigation, header and footer come from
  XenForo's own app classes rather than from the template

4.1.0 (2026-09-18)
------------------

* new: `dispatch()` runs one of your routes the way XenForo does and returns the reply its
  controller produced, with `assertReplyIsView()`, `assertReplyTemplate()`,
  `assertReplyViewClass()`, `assertReplyParam()`, `replyParam()`, `assertReplyIsRedirect()`,
  `assertReplyIsError()`, `assertReplyIsMessage()`, `assertReplyIsApiResult()` and
  `replyApiResult()`, `replyErrors()` and an optional message argument to `assertReplyIsError()` to
  assert against it. Parameters the route reads are passed as the third argument - the router takes
  a route path whole, so criteria cannot go in it. Public, admin and api
  routes are all reachable and reroutes are resolved for you. **This is the only way to cover an
  action's own access checks**: a controller invoked directly never runs `preDispatch()`, which is
  where XenForo's own `xf-make:controller` stub puts them, so an action tested that way is tested
  with its authorisation skipped
* new: `setVisitorAdminPermissions()` grants admin permissions to a built user, by giving it the
  administrator record `buildVisitor()` withholds. XenForo reads admin permissions from the user's
  `Admin` relation rather than from the permission combination `setVisitorPermissions()` writes, so
  they need their own helper
* new: `actingAsApiKey()` runs api dispatches as a given api key, and restores `\XF::$apiKey`
  afterwards - a static nothing else resets, so a key set by hand leaks into every later test
* `dispatch()` does not render the page, so asserting on HTML - that a template modification
  applied, or that a phrase resolved rather than showing a raw key - is not supported yet
* a `POST` route dispatches but only as far as the refusal: XenForo asserts a CSRF token in
  `preDispatch()` for anything that is not a `GET`, so an action opening with `assertPostOnly()`
  returns a 405. Sending a `POST` is not supported yet
* docs: `DOCS.md` still said you cannot unit test code which calls `save()` on an entity, and
  offered `mockEntity($name, false)` as the way round it. `UsesDatabaseTransactions` answered that
  in 4.0.0 and the README was updated at the time; this file was missed

4.0.3 (2026-09-17)
------------------

* bugfix: a user built by `buildVisitor()` - and so by `actingAsMember()` and `actingAsGuest()` -
  inherited an administrator record from the forum the tests run against. XenForo's guest user
  pre-hydrates `Option`, `Profile` and `Privacy` but not `Admin`, so that relation lazy-loaded by
  `user_id`, and `actingAsMember()` defaults to `user_id` 1. A built user with `is_admin` set
  therefore reported whatever admin permissions your own forum grants at that id, so a route or
  service guarded by `assertAdminPermission()` passed without the test granting anything, and the
  same test could fail on someone else's forum. `hasAdminPermission()` is now always false for a
  built user; pass a user you loaded yourself to `actingAs()` if you need a real administrator

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

3.0.7 (2026-09-20)
------------------

Backported from 4.3.0.

* `mockery/mockery` is now `^1.6`. The old `^1.0` was never tested and could not work: Mockery
  1.0, 1.1 and 1.2 declare `php >=5.6.0`, so Composer installs them onto a supported PHP and the
  mock code they generate is then invalid there. Mocking a plain class still succeeds, which is
  what made the constraint look sound - it is mocking an **interface** that fails, with
  `Cannot use "parent" when current class scope has no parent`, before the first test runs

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
