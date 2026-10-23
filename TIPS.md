# Writing testable code and other unit testing tips

It takes experience to recognise code that is hard to test, and to know how to restructure it.
These tips are not comprehensive, and you may disagree with some of them.

`DOCS.md` is the API reference for the helpers named here, and `README.md` covers installing and
configuring the framework.

## Know what you are testing

We aren't testing XenForo, or the libraries we pull in via Composer — assume they work. Focus on
your own code.

## Know why you are testing it

There's no point testing code that can't fail. Test that for a given set of inputs you get the
expected outputs, and that your code still behaves when something external changes.

## Don't leave side effects

Tests must give the same result every time they run, without manual intervention. So we fake or
mock anything with side effects: database writes, sending mail, writing files, calling external
APIs.

Database writes are the exception: with the `UsesDatabaseTransactions` trait the write happens and
is rolled back when the test finishes. Without it, don't write to the database.

## Keep unit and feature tests apart

`dispatch()`, `callAction()` and `renderTemplate()` let a test drive a route or render a template.
Put those tests in `tests/Feature`, not in your unit tests. The supplied `phpunit.xml` declares
both suites so you can run either on its own.

## Keep your controllers thin

Controllers coordinate: validate the request, call the logic, return a reply. Put logic in
repositories, services or sub-containers, where it can be tested directly. Use `dispatch()` for
what only a controller has — its access checks in `preDispatch()` — which covers a write as well,
since a non-GET dispatch carries a CSRF token. `callAction()` skips those checks, so reach for it
only where skipping them is the point.

The same applies to console commands and jobs.

## Assert that your console commands load

One test, `assertConsoleCommandsLoad()`, and it protects more than your own add-on. XenForo loads
every command class on the install simply to list them, so a class of yours that cannot load takes
`cmd.php` down for everybody — and nothing else in a test suite loads these classes at all, so a
broken parent or a renamed namespace goes unnoticed until someone runs a command.

## Testing a check that your extensions apply

An add-on that checks its own class extensions are in place - a validate command reporting that its
extension of `XF\Entity\User` is not applied - has a branch that only runs when the extension is
absent, and the framework cannot make it absent for one test: once any test in the run has resolved
the class, the extension stays, for the reason `DOCS.md` gives under *Unit Test Configuration*.
`requireClassNotExtended()` lets such a test run alone under `--filter` and skip in a full run,
which is honest, and is not coverage.

Make the branch testable by not depending on the resolution. Give the check an injectable list of
what it expects - a protected method returning the extensions, or a constructor argument - so one
test hands it an entry nothing registers and asserts the report, and another hands it the real list
and asserts the check is clean. The one test that goes through XenForo's real resolution stays
under `requireClassNotExtended()`, and the comparison it guards is tested either way.
`assertAddOnClassExtensionsApply()` covers the other half: that every extension the add-on
registers is the one XenForo resolves.

## Test an upgrade step by calling it

A DML-only upgrade step - one that rewrites rows rather than tables, such as renaming a
permission and carrying its grants across - runs inside the test's transaction, so it is tested
against planted state and leaves nothing behind:

```php
$this->fakesJobs();
$this->fakesRegistry();

$setup = new Setup($this->app()->addOnManager()->getById('MyVendor/MyAddon'), $this->app());
$setup->upgrade1000270Step1();

$this->assertDatabaseHas('xf_permission_entry', ['permission_id' => 'newName']);
$this->assertDatabaseMissing('xf_permission_entry', ['permission_id' => 'oldName']);
$this->assertJobQueued('XF:PermissionRebuild');
```

Three things keep it honest. Plant the old state with plain writes rather than through the entity
the step rewrites, or that entity's own behaviour runs on the way in and the step meets something
it never meets in an upgrade. Fake the jobs and the registry first where the step queues a rebuild
or writes a cache, since those reach rows the live forum also writes. And clear the entity cache
with `$this->app()->em()->clearEntityCache()` between two runs of the step in one test, or the
second reads what the first loaded. A step that changes the schema cannot be tested this way: DDL
commits the transaction, and the teardown check says so.

## Avoid static functions

A Cron task has to be static, but keep the static part short. XenForo's own Cron tasks hand
straight off to a repository or service:

```php
<?php

namespace XF\Cron;

/**
 * Cron entry for cleaning up bans.
 */
class Ban
{
	/**
	 * Deletes expired bans.
	 */
	public static function deleteExpiredBans()
	{
		\XF::app()->repository('XF:Banning')->deleteExpiredUserBans();
	}
}
```

## Mock an entity on `get()`, not `__get()`

Where you do need an entity double — because a method on it must not run — set the expectation on
`get()`. `Entity::__get()` delegates there, so an expectation on the magic method never applies and
the failure names `get()` with a trace pointing into XenForo rather than at your test. Array access
needs its own expectation: a double replaces `offsetGet()` too.

```php
$user = Mockery::mock(\XF\Entity\User::class);
$user->allows()->get('user_state')->andReturns('valid');
```

## Avoid mocking the database

Put database code in repositories, and mock the repository when testing other code. To test the
repository itself, use `UsesDatabaseTransactions` and let its real queries run, then assert with
`assertDatabaseHas()`.

If you do mock the database, call `mockDatabase()` **before** `mockRepository()`, `mockFinder()` or
`mockEntity()` — mocks registered before it are discarded.

## Don't mock everything

If you need to mock everything, your code probably has too many dependencies — split it up. Let
core functionality without side effects run for real, and mock only what you need to control.

## Use the framework's helpers instead of mocking XenForo

- `XF\App` — use the helper functions
- options — `setOption()` or `setOptions()`. For a `config.php` value, `setConfig()`
- `XF\Error` — `fakesErrors()`
- `XF\Job\Manager` — `fakesJobs()`, or `runJobToCompletion()` to run a job. `runRunnerStep()` runs
  one step of a multi-part runner to completion, for work your add-on contributes to someone else's
  clean-up or merge service
- work deferred with `\XF::runOnce()` — `runDeferred()`, for a test that saves or deletes an entity
  directly rather than through a dispatch or a job
- `XF\Logger` — `fakesLogger()` for XenForo's moderator log. For a `Psr\Log\LoggerInterface` of
  your own, under your own container key, `recordsLog()`
- `XF\Mail\Transport` — `fakesMail()`, which also captures mail sent with `queue()`. `failWith()`
  on the transport makes sending fail, for the path your code takes when mail does not go out; pair
  it with `fakesErrors()`, since XenForo's mailer logs the failure and answers `false`. Build the
  mail **after** installing the fake — one built before it sends through the forum's real transport
  while the fake captures nothing
- `XF\SimpleCache` — `fakesSimpleCache()`, read back with `getSimpleCache()`
- `XF\DataRegistry` — `fakesRegistry()`. It intercepts writes without isolating reads, so a key
  your test never wrote still answers from the forum
- `XF\Language` or `XF\Phrase` — `expectPhrase()`
- the http client — `fakesHttpByUrl()`, or `fakesHttp()` if you want responses in call order. Both
  cover a client your add-on builds with `createClient()`; install the fake before that client is
  built
- the filesystem — `swapFs()`, or `mockFs()` to set expectations. Both only reach code using
  `$app->fs()`
- `XF\Extension` — `fakesEvents()` records the events your code fires and stops listeners running
- `XF\Entity\User` as the visitor — `actingAs()`, `actingAsMember()` or `actingAsGuest()`, with
  permissions passed in or granted by `setVisitorPermissions()` and `setVisitorAdminPermissions()`
- the templater — `renderTemplate()`, or `renderReply()` on a reply from `dispatch()`
- BB code output — `renderBbCode()`, and `assertBbCode()` where an exact comparison is reasonable
- an entity a lookup returns — `mockFind()` for `\XF::em()->find()`, which resolves through a finder
  rather than consulting a mocked entity
- a scratch directory — `createTempDirectory()`, which removes it when the test finishes

## Pair every negative assertion with a positive one

`assertDontSee()` passes against markup that was never rendered, and a test that only asserts a
permission is *denied* passes for a visitor with no permissions at all.
`assertNoUnresolvedPhrases()` is the shape to reach for when checking phrases resolved, rather than
searching for your own prefix. Assert what should be there in the same test. For permissions,
remove the grant and check the positive test fails.

The database form is the same trap: `assertDatabaseMissing()` passes against a table that never
held the row, so a test that an account's rows are removed plants them first, and keeps a second
account's rows as the control that the removal was scoped. A suite asserting `xf_user_tfa` holds
no rows for an account that never had any passes with the removal deleted.

## Plant a core row with the columns its table needs, not the ones its entity requires

An entity's structure and its table can disagree, and the entity is the looser of the two.
`XF:Passkey` declares `aaguid` nullable with a null default, and `xf_passkey.aaguid` is `NOT NULL`,
so creating one without it fails with "Column 'aaguid' cannot be null" - a message naming the
column and nothing about why the entity allowed it. `xf_oauth_token.scopes` is a `blob`, which has
no default, so a raw insert without it fails with "Field 'scopes' doesn't have a default value".
Core's own flows always set both.

So when a fixture needs a row core normally writes - a passkey, a token, a two-step entry - read the
table in `src/XF/Install/Data/MySql.php` beside the entity's structure, and set every column the
table needs. Each miss costs a run, and the entity is not where the answer is.

## Mutate a template with a method name the compiler accepts

To prove a template test can fail, the obvious mutation is a modification calling a method that
does not exist - and it is silently a no-op. XenForo whitelists the methods a template may call
by their prefix, at compile time, so `{{ $xf.visitor.noSuchMethod() }}` is rejected when the
modification is saved, with "may not be called in a template. Only functions with whitelisted
prefixes are allowed". The modification never reaches the compiled template, nothing is mutated,
and the test reports `OK`, which looks exactly like the test being sound.

Give the invented method a whitelisted prefix - `canNoSuchMethod()` - and it compiles, applies,
and fails the assertion naming the template. Then read the save's result rather than the test's:
a mutation that was refused at compile time has proved nothing either way. The prefixes are the
read-only ones, `can`, `get`, `has`, `is` and their kin, which is also why a modification calling
`canUseSomething()` on a visitor missing an extension gets far enough to raise an error at all.

## If you aren't asserting, you aren't testing

Use asserts liberally, and give them messages so a failure in a large suite is easy to locate. Test
for the unexpected, not for values that can only ever be one thing.

## Don't use print or dump

`\XF::dump()` works under PHPUnit and is useful while developing. Remove it when you're done —
finished test output should only show PHPUnit's own results.

**Never `var_export()` or `print_r()` a reply or a phrase.** An error reply's errors are
`XF\Phrase` objects, and a phrase holds the language, which holds the database adapter, which holds
its own configuration — so one phrase is enough to print your database password into the test
output, and from there into any build log that captured it. `describeReply()` renders each error
phrase to its text and is the safe way to look.

## Make test functions descriptive

PHPUnit reports the name of a failing test, so `testFoo()` tells you little. Use names like
`test_foo_throws_an_exception_when_passed_null()`, and keep each test to one code path.

## Avoid logic in your unit tests

Branching in a test means it is testing more than one code path. Split it.

## Test boundary conditions

Pass unexpected input, and check what happens when a call returns `null` or `false` — one of the
most common sources of failure. There's no need to test that the wrong class passed to a
type-hinted parameter fails.

## How do I test private functions?

You don't. Test the public method that calls them. If you want to test a private method directly,
it probably belongs in its own class with a public interface.

## Subclassing a XenForo class in a test file

If you need a stub or a fake that extends a XenForo class — or one of your own add-on's classes,
which XenForo loads through the same autoloader — **declare it inside a test method, not at the top
of the test file**:

```php
public function test_the_handler_reports_what_it_did()
{
	$handler = new class extends \MyVendor\MyAddon\Handler\Something
	{
		public function fetch()
		{
			return ['one', 'two'];
		}
	};

	$this->assertCount(2, $handler->fetch());
}
```

PHPUnit loads every test file while building the suite, before XenForo has booted, so at file scope
the parent class does not exist yet. The whole run stops with `Class "XF\Entity\User" not found`
and a stack trace through `TestSuiteLoader`.

If the class needs a name, put it in a file PHPUnit does not collect — anything not ending in
`Test.php`, such as `tests/Support/` — and `require_once` it from inside the test method. PHP does
not allow a named class to be declared inside a method.

**The same applies to a data provider**, which PHPUnit also runs while building the suite. Anything
from XenForo or its vendor tree is unavailable there — a class constant such as
`OutputInterface::VERBOSITY_DEBUG` fails with
`Class "Symfony\Component\Console\Output\OutputInterface" not found`. Return the literal value from
the provider, or resolve it inside the test.

## Prefix the helpers you add to your test case

`TestCase` composes some two dozen concerns, and between them close to two hundred protected
helpers with ordinary English names - `setOption()`, `setOptions()`, `renderTemplate()`,
`createEntity()`, `mockService()`, `runJobToCompletion()`. A private helper of the same name in
your own test class is a fatal at class load, not a failing test, because PHP refuses to narrow
the visibility of an inherited method:

```text
PHP Fatal error: Access level to Tests\Feature\MyTest::setOptions() must be protected (as in
class Hampel\Testing\TestCase) or weaker
```

The whole run stops before any test executes, and the message names the framework's class, so it
reads as a framework bug. PHP method names are case-insensitive, so a private `setup()` collides
with PHPUnit's `setUp()` the same way.

Prefix your own helpers with your add-on's name, or check the name is free before using it:

```bash
grep -rn 'function setOptions(' vendor/hampel/xenforo-test-framework/src/
```

Every release adds helpers, so a name that is free today is not a name that stays free - the prefix
is the form that does.

## Be careful counting queries

A test that asserts on the number of queries a call makes is measuring something real, and two
things about the test environment make the number mean less than it looks.

**The first link built in a test costs two queries nothing else will.** XenForo's route formatter
loads the `routesPublic` and `routeFilters` registry entries the first time it is asked for a
link, so the first `buildLink()` of a test pays for them and every later one pays nothing - the
same route or a different one. Build a link before the measurement, so what you count is your own
code rather than the warm-up:

```php
$this->app()->router('public')->buildLink('index');   // warm-up, discarded

$before = $this->app()->db()->getQueryCount();
$this->service('MyVendor\MyAddOn:Thing')->doTheWork();
$this->assertSame(3, $this->app()->db()->getQueryCount() - $before);
```

**And permission loading is free for the ids the test seeded, which is the trap.**
`setVisitorContentPermissions()` puts them in the permission cache in memory, and XenForo's
`cacheContentPermsByIds()` drops every id already cached and returns before it queries. So the
node ids a test set cost no queries, while ids it did not set cost one - and a query-count
assertion over the seeded ids cannot notice code that stopped loading permissions at all, because
there was never a query there to lose. Those are usually exactly the ids under test.

So count queries to catch a loop that queries per row, and assert on the permission decision -
`hasNodePermission()` - rather than on a query count to catch a permission regression.

## Don't treat your test code as unimportant

A bug in a test can hide a bug in your code. Review test code as carefully as production code, and
check it in to source control.
