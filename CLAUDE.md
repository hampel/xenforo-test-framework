# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/xenforo-test-framework` is a PHPUnit test framework for **XenForo add-ons**. It is a
library, not an application: add-on developers `require-dev` it, copy the `tests/` scaffold and
`phpunit.xml` into their add-on, and run PHPUnit from the add-on root inside a working XenForo
installation.

It ships two distinct things:

- **`src/`** — the framework itself, namespaced `Hampel\Testing\` (PSR-4, the only autoloaded code).
- **`tests/`** — a **template that is copied into consuming add-ons**, namespaced `Tests\`. It is
  not this package's own test suite and is not autoloaded. `tests/TestCase.php` is the one file
  add-on authors edit and own, so a change to it is a **breaking change** that must be called out
  in `CHANGELOG.md` and `UPGRADING.md` with merge instructions. **Keep that file to declarations** —
  a release cannot update a copied file, which is why the boot lives in
  `Hampel\Testing\TestCase::createApplication()` rather than in the scaffold.

## There is no runnable test suite here

`./vendor/bin/phpunit` in this repository fails with `Class "Tests\TestCase" not found`, by design:
the scaffold needs a XenForo install above it (`$rootDir` points at the forum root) and a `Tests\`
autoload mapping that only the consuming add-on provides. **Do not "fix" this** by making `tests/`
autoloadable or rewriting it — that would break the copy-into-your-addon contract. (The
`autoload-dev` entry in `composer.json` is for `integration/`, below, and does not cover `tests/`.)

### There IS an integration suite — `integration/`

It is this package's own, not the scaffold, and it is export-ignored so it never reaches a
consumer. It boots a real XenForo application, so it needs a forum:

```bash
XF_ROOT=/srv/www/myforum composer integration
```

Without `XF_ROOT`, or with one that has no `src/XF.php`, every test **skips** and the run exits 0.
It does not run in CI, because XenForo's source is licensed and a public workflow cannot fetch it.

Two things in there are load-bearing and easy to undo by accident:

- **`$addonsToLoad = ['None/None']`** loads no add-ons at all. An empty array loads *every*
  installed add-on, and any that ship their own PHPUnit and Mockery then collide with this
  package's, failing tests with expectation counts of zero.

  **Listeners are filtered by `App::setup()`, and the ordering is load-bearing.** It installs the
  filtered extension *before* calling `parent::setup()`, because `XF\App::setup()` fires
  `app_setup` as its last step. Do not move that call after `parent::setup()`, and do not rely on
  filtering `addon.composer` instead — add-on classes also load through XenForo's own autoloader.
  `integration/AddOnIsolationTest.php` fails if this regresses.
- **`TestCase` hands PHPUnit back its error and exception handlers** in teardown. `XF::start()`
  installs its own and never removes them, which would mark every XF-booting test risky. Don't
  remove that restoration without turning `failOnRisky` off in both configs at the same time.

  While a test is running, XenForo's handler is what a deprecation meets, not PHPUnit's:
  `E_USER_DEPRECATED` arrives as an `ErrorException` and fails the test outright, whether or not
  `failOnDeprecation` is set. Adding a `<source>` element does not change this.

  **Read the exit status, and capture it without a pipe** — `vendor/bin/phpunit; echo $?`. A
  failing run can still print `OK, but there were issues!`, and `phpunit | tail` reports `tail`'s
  status.

### One command for the three checks

```bash
XF_ROOT=/srv/www/myforum composer check
```

Style, then PHPStan, then the integration suite; Composer stops at the first failure.
`check:lowest` stays out of it, since it installs a second dependency tree.

### Before a release, run the suite at the declared floor

```bash
XF_ROOT=/srv/www/myforum composer check:lowest
```

It copies the working tree to a temporary directory, resolves every dependency at the bottom of its
constraint, and runs the integration suite there, leaving this tree's `vendor/` alone.

The CI `lowest` job only proves the declared constraints *resolve*, since CI has no forum. A
constraint can resolve and still fail at runtime, so run this before tagging.

**Check a change to the fakes against the integration suite.** Their defects are invisible to
PHPStan.

Verification against a consuming add-on, when that is what you need:

```bash
cd /srv/www/<forum>/src/addons/<Vendor>/<AddonId>
./vendor/bin/phpunit                              # whole suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit tests/Unit/SomeTest.php      # one file
./vendor/bin/phpunit --filter test_name           # one test
```

### PHPStan

PHPStan needs a XenForo install to analyse against — almost every class here extends one of XF's,
and the source is licensed and not on Packagist. So the forum root comes from the environment:

```bash
XF_ROOT=/srv/www/myforum composer analyse
```

`phpstan.neon.dist` is committed and expands `%env.XF_ROOT%`; copy it to `phpstan.neon`
(gitignored) to hard-code your own path. It scans `src/XF`, XF's `vendor`, `XF.php` and
`utf8.php` — the last because XF `require`s it at runtime rather than autoloading it. The committed
level is 4; keep it clean.

### Do not add the dependency checks

`composer-require-checker` and a dev-free PHPStan run can never be green here, and are deliberately
absent. Every symbol they report is undeclarable rather than undeclared:

- `XF\*` and XenForo's global helpers come from XenForo itself, which is not on Packagist.
- `GuzzleHttp\*`, `League\Flysystem\*` and `Symfony\Component\Mailer\*` are supplied by the
  **forum's** vendor directory. Declaring them would install a second copy alongside the forum's.
- `Carbon\*` is optional and is in `suggest`.

A whitelist would have to grow with every XenForo class the code starts using. The extensions the
checker would catch are already guaranteed by XenForo's own requirements. PHPStan, reading
XenForo's source, covers the same ground.

## Version compatibility is the release axis

Each major line targets one XenForo version, and `master` is always the current line — 5.x today.
3.x, 4.x and 5.x all target XF 2.3; 3.x is for PHP 8.1 and 8.2. The README's compatibility table is
the published statement of this.

Maintenance happens on a branch per line — `1.x`, `3.x`, `4.x` — and nothing merges back to
`master`. A XenForo point release can break the framework, since it subclasses XenForo internals;
the fix is a new tag on the matching branch, never a runtime version check.

## Architecture

Boot path: `Hampel\Testing\TestCase::createApplication()` requires `{$rootDir}/src/XF.php`, calls
`\XF::start()`, then
`\XF::setupApp(Hampel\Testing\App::class, ['xf-addons' => $this->addonsToLoad])`.
It is an ordinary method; a consumer's own `CreatesApplication` trait still overrides it, because a
trait method beats an inherited one. `integration/LegacyCreatesApplicationTest.php` pins that.

`Hampel\Testing\App` extends `XF\App` to make the container usable from PHPUnit — it forces the CLI
class type with a `public` default, allows manual jobs, and makes `run()` throw. Its `setup()`
implements **add-on isolation**: when `$addonsToLoad` is non-empty, it filters `addon.composer` down
to those ids and installs the filtered `extension` before `parent::setup()`.
`TestCase::setUp()` throws if `$addonsToLoad` is set but the application was booted without it.

Everything else works by **swapping container keys**. `Concerns\InteractsWithContainer::swap()` is
the primitive; `mock()`/`spy()`/`mockFactory()`/`mockService()` wrap it with Mockery. `swap()` also
accepts `[$subcontainerKeyOrObject, $key]` to reach into an `XF\SubContainer\AbstractSubContainer`.

### A swap does not reach anything already built from the key — decache the consumer

`XF\Container::set()` clears the cache for **its own key only**. A resolved entry constructed from
that key keeps the value it was handed, so it never sees the swap: the fake is installed, nothing
consults it, and the test fails as though the code under test were wrong. Assume any new fake has
this problem until a test shows otherwise.

| swap | held by value in | fixed by |
|---|---|---|
| `client`, `clientUntrusted` | `reader`, `metadataFetcher` | `decache('reader')`, `decache('metadataFetcher')` |
| `mailer.transport`, `config['enableMailQueue']` | `mailer` | `decache('mailer')` |
| `config['fsAdapters'][…]` | `fs` | `decache('fs')` |

- **A second fake of the same thing in one test** has the same problem. Every fake wants a
  `test_a_second_fake_replaces_the_first`.
- **For the filesystem it is worse than a silent pass**: an un-decached `swapFs()` returns the real
  adapter, and the test writes to the forum's `data/` directory.
  `integration/FilesystemResolveOrderTest` covers it and cleans up after itself.

To find the consumer: grep XenForo's `App.php` for the key you are swapping and see which other
container closure reads it.

`Hampel\Testing\TestCase` composes the `Concerns\*` traits and drives the lifecycle:

- `setUp()` → `refreshApplication()` (wrapped in output-buffer save/restore, because XenForo boot
  writes to the buffer), the add-on isolation check, disable the auto job runner, then
  `setUpTraits()`.
- **`setUpTraits()` is an explicit allow-list**, matched by trait name via
  `UsesReflection::classUsesRecursive()`. Only EntityManager, Language, Options, Routes, Time and
  Visitor get a `setUp*()` call, plus `UsesDatabaseTransactions`, which is opt-in per test class and
  not composed into `TestCase`. A new concern needing per-test setup must be registered there or its
  hook never runs. Anything that must happen before `app_setup` fires belongs in `App::setup()`, not
  in a hook.
- `tearDown()` closes the DB connection (unless mocked), destroys `\XF::$app` by reflection
  (`UsesReflection::destroyProperty()`), closes Mockery and resets Carbon.

The `src/` classes outside `Concerns/` are the fakes and subclasses that get swapped in — `Error`,
`Logger`, `DataRegistry`, `SimpleCache`, `Job\Manager`, `Mail\TestTransport`,
`Mvc\Entity\Manager`, `Extension`. Two are worth knowing before touching:

- **`Extension`** keeps `static` extension, inverse-extension and class-alias maps that deliberately
  **persist across tests in a run**, so a class is not re-extended for every test. Do not make them
  instance state. `Extension::forAddOns()` must key class extensions by
  `\XF::getClassForAlias($from_class)`, as XenForo 2.3's own cache builder does.
- **`Job\Manager` and `Mail\TestTransport`** track queued jobs / sent mail in memory for the
  corresponding `assert*` helpers. Mail queueing is switched off rather than faked, so everything
  goes through `TestTransport`. `enableMailQueue` is a **config.php value, not an option**.

Naming convention across the concerns: `fakes*()` installs an in-memory implementation with
assertion helpers, `mock*()` installs a Mockery double, `set*()` mutates state that is restored in
teardown.

### An assertion that passes because the thing is absent

This package's recurring defect: a check that passes because what it tests is *missing*. Almost
everything here installs a fake, and a fake that fails to install produces a green test.

| the check | what made it pass |
|---|---|
| a test asserting on a mocked service | `mockService()` built an untyped double for a class that does not exist |
| a whole suite | a suite that collects no tests exits 0 — `failOnEmptyTestSuite` catches it |
| `hasPermission()` on a built user | every built user landed on the real guest permission combination |
| `hasAdminPermission()` on a built user | it inherited the test forum's own administrator record |
| `assertReplyIsError($reply, 403)` | two different guards both deny with 403 |
| `assertDontSee($html, …)` | a template that cannot be found, or that fails, renders as an empty string |
| `assertDontSee($html, …)` | a rendered template had no `$xf`, so a block reading `$xf.options` did not render |

Rules that follow:

- **A helper that can return "nothing" must refuse instead of returning it** — as `renderTemplate()`
  does for a missing template and `mockService()` for a missing class.
- **Check every route to "nothing".** When something can return nothing, read what the upstream
  class does in its `catch`.
- **Every negative assertion needs a positive beside it**, in tests here and in DOCS examples.
- **When adding a fake, write `test_a_second_fake_replaces_the_first` and a test that fails
  without the fake.** PHPStan cannot catch this class of defect.

**`renderTemplate()` writing template errors to the real `xf_error_log` is intended** — the test
writer opts out with `fakesErrors()`, which keeps the errors available to the test. Do not suppress
the logging by default.

## Documentation

- `README.md` — the tutorial: theory, installation, `build.json` cleanup, limitations, testing tips.
- `DOCS.md` — the API reference, one `###` section per public helper.
- `UPGRADING.md` — what a consumer must do, per version.

All are published. **A new or changed helper needs its `DOCS.md` section updated in the same
change**, and removed helpers stay listed struck-through with the removing version (see
`### ~~isolateAddon~~`). **Run every example as written before committing it.**

Published documents describe the package and how to use it. They do not record how a behaviour was
found, what earlier versions did, measurements, or the reasoning behind a decision — that belongs
in commit messages.

## CHANGELOG

Entries state what changed and, where something is required, what to do. No mechanism, provenance
or reasoning.

**Mark up identifiers with backticks** - `` `fakesMail()` ``, `` `TypeError` ``,
`` `enableMailQueue` ``. Releases are announced as BBCode posts on xenforo.com, converted
mechanically from this file, which can only mark up what the source marks up.

## Style

**`xenforo-ltd/xf-cs-fixer` decides style** — XenForo's own PHP-CS-Fixer configuration (PER-CS plus
their house rules). Run it before committing:

```bash
composer format          # apply
composer format:check    # report, changing nothing
```

That means tabs, Allman braces, and `<?php` on its own line with `namespace` below it. Do not
hand-tune formatting or add rule overrides.
