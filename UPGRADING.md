# Upgrading

What you have to do, version by version, to move an add-on's test suite from one release of this
package to the next. Newest first — read down until you reach the version you are on.

`CHANGELOG.md` is the companion to this file and answers a different question. It lists everything
that changed; this lists only the changes that need something from you. Most releases need nothing
beyond `composer update`, and say so.

The scaffold files are the reason this document exists. `tests/TestCase.php` and
`tests/CreatesApplication.php` are **copied into your add-on and owned by you**, so a change to
either cannot reach you through Composer — it has to be merged by hand.

## 5.0.0

**The one thing you can act on: the scaffold is one file now, and deleting the other is optional.**

`tests/CreatesApplication.php` has left the scaffold. `Hampel\Testing\TestCase::createApplication()`
reads `$rootDir` and `$addonsToLoad` off your test class and boots XenForo itself.

**Doing nothing is a supported choice.** A trait method wins over an inherited one in PHP, so an
add-on that keeps its own copy keeps exactly the boot it has. To adopt the framework's instead:

- delete `tests/CreatesApplication.php`;
- delete the `use CreatesApplication;` line from `tests/TestCase.php`.

That is the whole migration, and it is the last time this file will ask you to merge boot code by
hand. The reason for the change is the v2.1.0 entry near the bottom of this document: those add-on
isolation arguments went into that file in 2022, and add-ons are still running the 2020 version
today — a release cannot reach a file you copied.

If you need a boot of your own, `createApplication()` is an ordinary method. Override it in your
`tests/TestCase.php` and pass the ids on, or isolation never reaches the application:

```php
return \XF::setupApp('Hampel\Testing\App', ['xf-addons' => $this->addonsToLoad]);
```

**Add-on isolation filters code event listeners now, and on every earlier version it did not.**
Nothing to change for this. What changes is what your suite sees.

Until now, naming add-ons in `$addonsToLoad` filtered Composer autoloading and class extensions,
and left listeners alone — XenForo fires `app_setup` at the very end of `XF\App::setup()`, and the
filtered listener set was installed after that, too late to stop anything. So every installed
add-on's `app_setup` listener ran regardless. On a development forum carrying 13 of them, all 13
ran in a suite that had asked for no add-ons at all.

**So a test that passes on v4 and fails here was relying on an add-on it excluded.** Its listener
used to run anyway — registering a container entry, setting an option, extending a class — and now
it does not. Either add that add-on to `$addonsToLoad`, or stop depending on it. That is the
question isolation existed to ask, and it has not been able to ask it until now.

The reverse is worth knowing too: if an excluded add-on has been *breaking* your suite during boot,
that stops.

**If your suite is half way through the v2.1.0 scaffold upgrade, it now stops rather than running.**
That upgrade needed both files, and taking one without the other has been silent since 2022. If you
are in that state — `$addonsToLoad` set, and a `tests/CreatesApplication.php` old enough not to pass
it on — every test now errors with:

```text
This suite sets $addonsToLoad to [...], but the application was booted without it, so no add-on
isolation is in effect
```

The fix is the migration at the top of this entry. A suite in that state was never getting the
isolation it was configured for, so this is a day you were going to lose eventually, brought
forward and labelled.

**Almost certainly nothing to do:** the `Concerns\InteractsWithExtension` trait is gone. `TestCase`
composed it for you and no longer needs to, so the scaffold never referred to it and neither did
any documented example. If one of your own test classes `use`s it directly, remove that line — the
work it did now happens while the application boots.

## 4.3.1

**Nothing to do.** Documentation only — no code in `src/` changed.

One correction is worth reading even so, because it is about something you may have been relying
on: **add-on isolation does not filter code event listeners.** An add-on left out of
`$addonsToLoad` still has its `app_setup` listener run, so it can still register container entries
and still throw while the application boots. That behaviour is not new — only the documentation of
it is, and it had said the opposite since isolation was introduced. It is fixed in 5.0.0 — see
that entry above.

`spy()` is also documented for the first time, in `DOCS.md` beside `mock()`. It has shipped since
1.0.0.

## 4.3.0

**One thing needs checking, and only if you pin Mockery yourself.** The package now requires
`mockery/mockery: ^1.6`, where it used to say `^1.0`. That old constraint was never tested and
could not work: Mockery 1.0, 1.1 and 1.2 declare `php >=5.6.0`, so Composer installs them on a
modern PHP quite happily and the mock code they generate is invalid there. It fails with
`Cannot use "parent" when current class scope has no parent` before the first test runs. If your
own `composer.json` holds Mockery below 1.6, raise it; otherwise `composer update` is enough.

Worth knowing why it survived so long: mocking a plain **class** on Mockery 1.0 works fine. It is
mocking an **interface** that fails, so the obvious check by hand passes.

New helpers:

- `renderMacro()` renders one macro out of a template, for markup that lives in a macro or where
  rendering the whole template would need parameters your test has no reason to build.
- `pageParam()` reads back what a rendered template set with `<xf:title>`, `<xf:description>`,
  `<xf:h1>` or `<xf:pageaction>`. None of those appear in the template's own output, because the
  markup around them belongs to the page wrapper, so reading one back is the only way to assert
  on it.
- `assertNoTemplateErrors()` asserts that nothing your test rendered raised an error.
- `assertReplyIsRedirect()` takes a type — `permanent` or `temporary`.
- Every reply assertion takes an optional message as its last argument, which is the part you want
  when a test dispatches several routes.

**Two behaviours changed.** `renderTemplate()` now throws when a render produces nothing and the
templater recorded an error — XenForo catches everything a template does wrong, logs it and
carries on, so that failure used to arrive as an empty string that `assertDontSee()` passed
against. A render that errored and still produced markup is returned unchanged, deliberately: that
case is far commoner, and failing it would turn working suites red. `assertNoTemplateErrors()` is
the opt-in if you want the stricter guarantee.

And `dispatch()` now runs the work a controller deferred through `\XF::runOnce()` — an entity
`postSave()` rebuilding a cache is the common case. That work used to be dropped silently.

One rename to be aware of only if you use named arguments: `assertReplyIsError()`'s third
parameter is now `$errorText`, because `$message` is the failure message everywhere else in the
family. Positional calls are unaffected.

## 4.2.0

**Templates render now, with no web server.** This is the half 4.1.0 left open, and it covers the
two checks a reply cannot: that a **template modification** actually applied, and that a **phrase
resolved** rather than rendering as a raw key.

```php
$html = $this->renderTemplate('public:thread_view', ['thread' => $thread]);
$this->assertSee($html, 'Reply to thread');

// or straight from a dispatch
$reply = $this->dispatch('options', 'admin');
$this->assertSee($this->renderReply($reply), 'Option groups');
```

`assertSee()`, `assertDontSee()`, `assertSeeText()`, `assertDontSeeText()`, `assertSeeInOrder()`
and `textOf()` assert against the output. The expected value is escaped by default the way XenForo
escapes template output, or apostrophes and ampersands silently never match.

`assertTemplateModificationApplied()` answers the question directly instead, by reading the
modification's apply count. That matters more than it sounds: **XenForo logs a modification that
matches nothing as status `ok`**, so the status cannot tell you one has silently stopped applying
after an upgrade. Only the count can.

**Two things to expect.** This renders the **template**, not the page — navigation, header and
footer come from XenForo's `Pub` and `Admin` app classes rather than from the template. And these
assertions read what the **forum** has, not what your working copy has: an edit to `_output/` is
invisible until `xf-dev:import`, and a template modification only exists once the add-on is
installed.

**Pair every negative assertion with a positive.** `assertDontSee()` proves nothing on its own — a
consumer mutation-tested this and found that deleting the entire rendered block was caught by the
positive half only.

## 4.1.0

**Controllers are testable.** `dispatch()` runs one of your routes the way XenForo does and hands
back the reply the controller produced, for public, admin and api routes alike.

```php
$admin = $this->actingAsMember(['is_admin' => true]);
$this->setVisitorAdminPermissions($admin, ['option' => true]);

$reply = $this->dispatch('options', 'admin');

$this->assertReplyTemplate($reply, 'option_group_list');
```

`assertReplyIsView()`, `assertReplyTemplate()`, `assertReplyViewClass()`, `assertReplyParam()`,
`replyParam()`, `assertReplyIsRedirect()`, `assertReplyIsError()`, `assertReplyIsMessage()`,
`assertReplyIsApiResult()`, `replyApiResult()` and `replyErrors()` assert against it. Parameters
the route reads are passed as the third argument — the router takes a route path whole, so
criteria cannot go in it.

**The reason this matters is not HTML assertions.** A controller you construct and invoke directly
never runs `preDispatch()`, and that is where XenForo's own `xf-make:controller` stub puts access
checks — so an action tested that way is tested with its authorisation skipped. Dispatching is the
only thing that covers it.

Also new: `setVisitorAdminPermissions()`, which grants admin permissions to a built visitor by
giving it the administrator record `actingAs()` withholds; and `actingAsApiKey()`, which runs api
dispatches as a given key and restores `\XF::$apiKey` afterwards — a static nothing else resets,
so a key set by hand leaks into every later test in the run.

**Three edges worth knowing before you write one.** An admin route needs a visitor with `is_admin`
set, and `dispatch()` throws if there is not one, because XenForo's admin controllers reroute to
the login form and that arrives as an ordinary view with a 200. A public route needs the visitor
to hold `general.view`, which a built visitor does not — every public controller asserts it, so a
public dispatch refuses with a 403 until your test grants it. And a `POST` route dispatches only
as far as the refusal, because XenForo asserts a CSRF token for anything that is not a `GET` and a
test has no cookie to build one from.

## 4.0.3

A bugfix, and it only bites if your tests set `is_admin`. A user built by `actingAs()` lazy-loaded
its `Admin` relation by user id, and `actingAsMember()` defaults to user id 1 — the owner on most
forums. So `hasAdminPermission()` returned **true** for a built user granted nothing at all.
Fixed, and `setVisitorAdminPermissions()` in 4.1.0 is the legitimate way to grant.

## 4.0.2

A bugfix worth knowing about if you test an API client. A response faked with `fakesHttp()` or
`fakesHttpByUrl()` reached the caller with its body already read, so
`$response->getBody()->getContents()` returned an empty string. Real Guzzle rewinds the sink it
returns as the body, so production was never affected — only the fakes were.

Two things follow. **Read response bodies with `getContents()` in your tests, the way XenForo core
does**, and not with a `(string)` cast: the cast seeks to the start first, so it passes whether or
not the stream was left at the end and would have hidden this from you. And this is also fixed in
3.0.6, if you are on the v3 line.

## 4.0.1

Documentation only, plus one scaffold change: `tests/Feature/` now ships an `ExampleTest.php`
rather than a `.gitkeep`. If you already have a `tests/Feature` with a `.gitkeep` in it, nothing
is broken and you need do nothing.

## 4.0.0

The largest release this package has had. A dozen bugs fixed, several helpers that had never
worked at all, and a set of new ones. Four things need action on your side.

### 1. PHP 8.3 is now the minimum

If your add-on pins `config.platform.php` below 8.3 in `composer.json`, Composer cannot install
4.x at all — the solve fails outright rather than falling back.

Raising that pin is dev-only in intent but not in effect: it also lets Composer select **runtime**
dependencies above the PHP version your add-on declares, and those go into your release zip. After
raising it, check that every package in the `packages` array of `composer.lock` still satisfies
your add-on's own PHP floor, and cap any that do not.

### 2. PHPUnit 12 is now allowed, and will probably be what you get

For most add-ons this package is the only thing that pins PHPUnit at all, so a `composer update`
that used to select 10 will now select 12. PHPUnit 12 no longer reads metadata from doc comments,
so tests using `@dataProvider`, `@depends`, `@covers` or `@group` stop working — they error rather
than run. Convert them to attributes (`#[DataProvider]` and friends, which work on 10, 11 and 12)
or pin `phpunit/phpunit` yourself.

Worth doing before you upgrade rather than after, because of how the versions differ:

- PHPUnit 10 — runs, no complaint
- PHPUnit 11 — runs, reports `PHPUnit Deprecations: n`, and **exits 0**
- PHPUnit 12 — errors, and the test does not run

The `failOnPhpunitDeprecation` flag in the supplied `phpunit.xml` turns that middle row into a
failure you can act on while it is still cheap.

### 3. Re-copy `phpunit.xml`

Or diff it against yours if you have customised it, since copying over the top discards your
changes:

```bash
cp vendor/hampel/xenforo-test-framework/phpunit.xml .
```

It now fails the suite on deprecations, notices, warnings, risky tests, PHPUnit's own
deprecations, and a run that executes no tests at all. The README explains what each flag is for;
the one most likely to turn a currently-green suite red is `failOnPhpunitDeprecation`, which is
what it is for — see the PHPUnit 12 note above.

It also declares a Feature test suite, so `tests/Feature` has to exist. If yours does not, take it
from the scaffold rather than creating it by hand — it ships `tests/Feature/ExampleTest.php`,
which keeps the directory in git as well as showing where feature tests go. **Git does not track
empty directories**, so a `tests/Feature` you `mkdir` yourself works on your machine and is absent
in every clone, including CI. A missing directory does at least fail loudly: PHPUnit prints
`Test directory ".../tests/Feature" not found` and exits **2** without running anything, on 10, 11
and 12 alike.

### 4. Rename a `makeEntity` or `createEntity` of your own

If your test classes define a method called either, rename it. Both are new **protected** methods
on `TestCase`, and PHP will not let a subclass narrow a protected method to private — the class
fails to load with `Access level to ... must be protected` before any test runs.

That failure is loud and easy. The dangerous fix is the obvious one: **check which of the two your
helper matches before deleting it to inherit ours.** `createEntity()` saves and `makeEntity()`
does not, so a local helper that only builds maps to `makeEntity()`. Inheriting `createEntity()`
in its place compiles, reads correctly, and silently turns every in-memory build into a database
write.

Nothing else needs changing. `TestCase.php` and `CreatesApplication.php` were restyled to
XenForo's own coding standard, so a diff against your copies will show formatting changes
alongside nothing else — there are no functional changes to either.

### What you get for it

- `actingAs()`, `actingAsMember()` and `actingAsGuest()` run the code under test as a given
  visitor, with `setVisitorPermissions()` and `setVisitorContentPermissions()` granting
  permissions in memory rather than reading them from the database.
- `UsesDatabaseTransactions` wraps each test in a transaction and rolls it back, so you can
  finally test code that calls `save()`. `assertDatabaseHas()`, `assertDatabaseMissing()` and
  `assertDatabaseCount()` assert against rows that are really there.
- `makeEntity()` and `createEntity()` build an entity with the values you give it.
- `fakesEvents()` records the code events your add-on fires and stops them reaching listeners.
- `fakesHttpByUrl()` picks the HTTP response by request URL rather than by the order the calls
  happen in.
- `setConfig()` sets a `config.php` value, which `setOption()` cannot reach.

And the fixes worth knowing about, because you may have written them off as your own bugs:
`fakesRegistry()` was a fatal error for the whole v3 line; the mail assertions threw a `TypeError`
as soon as any mail was sent; `fakesMail()` never actually disabled the mail queue, so anything
using `queue()` rather than `send()` was invisible; `mockRepository()` and `mockService()` both
silently did nothing when given identifiers XenForo accepts everywhere else; and `swapFs()` handed
back the real filesystem if anything had already touched it, so tests wrote to your real data
directory. The full list is in `CHANGELOG.md`.

## 3.0.7

`mockery/mockery` is now `^1.6`, backported from 4.3.0 — see that entry for why the old `^1.0`
could not work. `composer update` is enough unless you pin Mockery yourself.

## 3.0.6

The faked-response-body fix, backported from 4.0.2. See that entry.

## 3.0.5

No changes are needed to your own files — `composer update` is enough. Note however that the
package now ships a `tests/Feature/.gitkeep` file. If you created `tests/Feature` by hand and your
add-on is in git, copy that file across and commit it: git does not track empty directories, so an
empty `tests/Feature` is missing from every clone, and PHPUnit refuses to run at all when it is
not there.

## 3.0.4

A large bugfix release — several helpers had never worked. Nothing you need to change, but if you
had given up on `fakesRegistry()`, the mail assertions or the count form of `assertJobQueued()`,
they work now. See `CHANGELOG.md` for the full list.

One thing to be aware of: mail assertions were previously broken outright, so this may be the
first time yours actually run. Captured mail is a `Symfony\Component\Mime\Email`, and `getTo()`
returns `Address` objects rather than the `email => name` array Swiftmailer used before XenForo
2.2:

```php
$to = $mail->getTo();

$to[0]->getAddress() == 'foo@example.com';   // not array_key_exists('foo@example.com', $to)
$to[0]->getName()    == 'Foo';               // not $to['foo@example.com'] == 'Foo'
```

An assertion written in the old shape does not fail as a type error — it simply never matches, and
reports "The expected mail was not sent." as though nothing had been sent at all.

## 2.1.0

`tests/TestCase.php` and `tests/CreatesApplication.php` both changed, and both are yours, so this
one has to be merged by hand. Adding one without the other does nothing.

A new property in `tests/TestCase.php`:

```php
protected $addonsToLoad = [];
```

And new code in `tests/CreatesApplication.php`:

```php
$options['xf-addons'] = $this->addonsToLoad ?: [];

return \XF::setupApp('Hampel\Testing\App', $options);
```
