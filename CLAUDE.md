# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/xenforo-test-framework` is a PHPUnit test framework for **XenForo add-ons**. It is a
library, not an application: add-on developers `require-dev` it, copy the `tests/` scaffold and
`phpunit.xml` into their add-on, and run PHPUnit from the add-on root inside a working XenForo
installation.

It ships two distinct things, and the distinction matters when editing:

- **`src/`** — the framework itself, namespaced `Hampel\Testing\` (PSR-4, the only autoloaded code).
- **`tests/`** — a **template that is copied into consuming add-ons**, namespaced `Tests\`. It is
  not this package's own test suite and is not autoloaded (`composer.json` has no `autoload-dev`).
  `tests/TestCase.php` and `tests/CreatesApplication.php` are the files add-on authors edit and
  own, so a change to either is a **breaking change** that must be called out in `CHANGELOG.md`
  with merge instructions — see the 2.1.0 entry for the precedent.

## There is no runnable test suite here

`./vendor/bin/phpunit` in this repository fails with `Class "Tests\TestCase" not found`, by design:
the scaffold needs a XenForo install above it (`$rootDir` points at the forum root) and a `Tests\`
autoload mapping that only the consuming add-on provides. **Do not "fix" this** by adding
`autoload-dev` or rewriting `tests/` — that would break the copy-into-your-addon contract.

Verification is therefore manual, against a real add-on in a real forum:

```bash
cd /srv/www/<forum>/src/addons/<Vendor>/<AddonId>
./vendor/bin/phpunit                              # whole suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit tests/Unit/SomeTest.php      # one file
./vendor/bin/phpunit --filter test_name           # one test
```

Static checks available in this repo: `php -l src/**/*.php`. There is no CI, PHPStan or linter
configured.

## Version compatibility is the release axis

Each major version targets one XenForo major version, and users install the version matching the
XenForo they develop against (README has the table): XF 2.1 → 1.x, XF 2.2 → 2.x, XF 2.3 → 3.x
(current, `master`). Because the framework subclasses XenForo internals (see below), a XenForo
point release can break it — which is why the fix is a new tag on the matching branch, never a
runtime version check.

## Architecture

Boot path: `tests/CreatesApplication::createApplication()` requires `{$rootDir}/src/XF.php`, calls
`\XF::start()`, then `\XF::setupApp(Hampel\Testing\App::class, $options)`. `Hampel\Testing\App`
extends `XF\App` to make the container usable from PHPUnit — it forces the CLI class type with a
`public` default, allows manual jobs, and makes `run()` throw. Its `setup()` implements **add-on
isolation**: when `$addonsToLoad` is non-empty, it filters `addon.composer` down to those ids, so
only those add-ons' listeners, extensions and Composer autoloading are active (a good check that
an add-on declares its own dependencies).

Everything else works by **swapping container keys**. `Concerns\InteractsWithContainer::swap()` is
the primitive; `mock()`/`spy()`/`mockFactory()`/`mockService()` wrap it with Mockery. `swap()` also
accepts `[$subcontainerKeyOrObject, $key]` to reach into an `XF\SubContainer\AbstractSubContainer`.

`Hampel\Testing\TestCase` composes the `Concerns\*` traits and drives the lifecycle:

- `setUp()` → `refreshApplication()` (wrapped in output-buffer save/restore, because XenForo boot
  writes to the buffer), disable the auto job runner, then `setUpTraits()`.
- **`setUpTraits()` is an explicit allow-list**, matched by trait name via
  `UsesReflection::classUsesRecursive()`. Only EntityManager, Extension, Language, Options and Time
  get a `setUp*()` call. A new concern needing per-test setup must be registered there or its hook
  silently never runs.
- `tearDown()` closes the DB connection (unless mocked), destroys `\XF::$app` by reflection
  (`UsesReflection::destroyProperty()`), closes Mockery and resets Carbon.

The `src/` classes outside `Concerns/` are the fakes and subclasses that get swapped in — `Error`,
`Logger`, `DataRegistry`, `SimpleCache`, `Job\Manager`, `Mail\TestTransport`,
`Mvc\Entity\Manager`, `Extension`. Two are worth knowing before touching:

- **`Extension`** keeps `static` extension, inverse-extension and class-alias maps that deliberately
  **persist across tests in a run**. Re-extending a class per test is what it exists to prevent;
  making these instance state will reintroduce the bug 3.0.2 fixed.
- **`Job\Manager` and `Mail\TestTransport`** track queued jobs / sent mail in memory for the
  corresponding `assert*` helpers. Mail queueing is switched off (`enableMailQueue` option) rather
  than faked, so everything goes through `TestTransport` — 3.0.0 removed the old queue fake.

Naming convention across the concerns, worth preserving: `fakes*()` installs an in-memory
implementation with assertion helpers, `mock*()` installs a Mockery double, `set*()` mutates state
that is restored in teardown.

## Documentation

- `README.md` — the tutorial: theory, installation, `build.json` cleanup, limitations, testing tips.
- `DOCS.md` — the API reference, one `###` section per public helper.

Both are user-facing and published; **a new or changed helper needs its `DOCS.md` section updated
in the same change**, and removed helpers stay listed struck-through with the removing version (see
`### ~~isolateAddon~~`).

## Style

Match the surrounding code rather than reformatting: `<?php namespace Foo;` on line one, Allman
braces, tabs in most `src/` files (some older files use spaces — follow the file), docblocks on
public helpers describing what side effect is being avoided.
