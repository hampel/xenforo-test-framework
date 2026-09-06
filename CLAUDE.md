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
autoload mapping that only the consuming add-on provides. **Do not "fix" this** by making `tests/`
autoloadable or rewriting it — that would break the copy-into-your-addon contract. (The
`autoload-dev` entry in `composer.json` is for `integration/`, below, and deliberately does not
cover `tests/`.)

### There IS an integration suite — `integration/`

It is this package's own, not the scaffold, and it is export-ignored so it never reaches a
consumer. It boots a real XenForo application, so it needs a forum:

```bash
XF_ROOT=/srv/www/myforum composer integration
```

Without `XF_ROOT`, or with one that has no `src/XF.php`, every test **skips** and the run exits 0
— so it can sit in the repository without breaking anyone who has no forum. It does not run in CI,
for the same reason PHPStan does not: XenForo's source is licensed and no public workflow can
fetch it. Read that as a licensing decision rather than a technical impossibility — a workflow
holding license credentials could provision a real forum, and the tooling for that is improving.

Two things in there are load-bearing and easy to undo by accident:

- **`$addonsToLoad = ['None/None']`** loads no add-ons at all. An empty array loads *every*
  installed add-on, and any that ship their own PHPUnit and Mockery then collide with this
  package's — Mockery registers an expectation in one instance and verifies it in another, and
  tests fail with counts of zero. An id matching nothing gives complete isolation.
- **`TestCase` hands PHPUnit back its error and exception handlers** in teardown. `XF::start()`
  installs its own and never removes them, which used to mark every XF-booting test risky and
  made `failOnRisky` unusable. Don't remove that restoration without turning `failOnRisky` off
  in both configs at the same time.

  A consequence worth knowing before changing either config: **while a test is running, XenForo's
  handler is what a deprecation meets, not PHPUnit's.** `E_USER_DEPRECATED` arrives as
  `ErrorException: [E_USER_DEPRECATED] …` and fails the test outright, so a XenForo-booting suite
  is stricter about deprecations than its `phpunit.xml` describes, and stays that way whether or
  not `failOnDeprecation` is set. The flag is correct in intent and currently redundant.

  Neither config declares a `<source>` element, and adding one does not change this. `<source>`
  governs neither whether `failOnDeprecation` fires nor which files it fires for — measured on
  PHPUnit 10.5, 11.5 and 12.5, with the deprecation raised both inside and outside the declared
  source, all six runs failing identically. Note that a run failing this way still prints
  `OK, but there were issues!` in yellow, so **read the exit status, and capture it without a
  pipe** — `vendor/bin/phpunit; echo $?`, since `phpunit | tail` reports `tail`'s status.

Every test in `integration/` reproduces a bug that shipped in 3.0.3. **Check a change to the
fakes against this suite** — the registry, mail and job bugs fixed in 4.0.0 were all invisible
to PHPStan and to a scaffold suite with no forum behind it.

Verification against a consuming add-on, when that is what you need:

```bash
cd /srv/www/<forum>/src/addons/<Vendor>/<AddonId>
./vendor/bin/phpunit                              # whole suite
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit tests/Unit/SomeTest.php      # one file
./vendor/bin/phpunit --filter test_name           # one test
```

**PHPStan is the only automated check this package has**, and it needs a XenForo install to
analyse against — almost every class here extends one of XF's, the source is licensed and not on
Packagist, and there is no public stub package. So the forum root comes from the environment:

```bash
XF_ROOT=/srv/www/myforum composer analyse
```

`phpstan.neon.dist` is committed and expands `%env.XF_ROOT%`; copy it to `phpstan.neon`
(gitignored) to hard-code your own path. It scans `src/XF`, XF's `vendor`, `XF.php` and
`utf8.php` — the last because XF `require`s it at runtime rather than autoloading it, and
`src/Error.php` calls `utf8_substr()`.

Level 1 is clean. It found four real defects the first time it ran, which on a package whose own
suite cannot execute is the whole argument for keeping it green.

### The dependency checks do not apply either — do not add them

`composer-require-checker` and a dev-free PHPStan run are the standard way to catch a package
calling a class it never declared. The checker reports 33 symbols here, and **every one of them
is undeclarable rather than undeclared**:

- `XF\*` and XenForo's global helpers (`utf8_substr`) come from XenForo itself, which is licensed
  and not on Packagist.
- `GuzzleHttp\*`, `League\Flysystem\*` and `Symfony\Component\Mailer\*` are supplied by the
  **forum's** vendor directory, not the add-on's. Declaring them would install a second copy
  alongside the forum's — see the `league/flysystem-memory` conflict in `composer.json` for what
  a duplicate of one of these actually costs.
- `Carbon\*` is genuinely optional and is in `suggest`.

So neither check can ever be green. A whitelist file is the usual remedy for that, and it is the
wrong one here, for a reason specific to this package: **the list would have to grow every time a
new XenForo class is used**, which is most of what changing this code consists of. Raising PHPStan
by two levels added `XF\Repository\UserRepository` and `League\Flysystem\Filesystem` in a single
commit; both are supplied by the forum, both belong on the whitelist, and both would have turned
the job red first. A check answered by extending its own exclusion list trains the reflex that a
new symbol needs silencing rather than checking, which is the opposite of what it is for.

The extension half does not rescue it either. `composer-require-checker` is the only thing that
catches an undeclared `ext-*`, and that is a real failure mode for most packages — but this one
runs only inside a XenForo installation, and XenForo's own requirements already guarantee
`ext-json`, `ext-mbstring`, `ext-pcre`, `ext-intl` and a dozen more.

So there is no `dependencies` workflow. The equivalent coverage comes from PHPStan reading
XenForo's source directly, which is also the only check here that can resolve those 33 symbols
rather than merely tolerating them.

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

### A swap does not reach anything already built from the key — decache the consumer

This is the single most productive bug in the package: **four** shipped instances of it, all found
by consumers rather than by any check here, and every one of them silent. Assume any new fake has it
until you have written the test that proves otherwise.

`XF\Container::set()` clears the cache for **its own key only**. A resolved entry that was
constructed from that key keeps the *value* it was handed, not the container, so it never sees the
swap. The fake is genuinely installed, nothing consults it, and the test reports a plain assertion
failure that reads like a bug in the code under test:

| swap | held by value in | fixed by |
|---|---|---|
| `client`, `clientUntrusted` | `reader`, `metadataFetcher` | `decache('reader')`, `decache('metadataFetcher')` |
| `mailer.transport`, `config['enableMailQueue']` | `mailer` | `decache('mailer')` |
| `config['fsAdapters'][…]` | `fs` | `decache('fs')` |

Two consequences worth stating separately, because each cost someone a session:

- **A second fake of the same thing in one test is the same bug**, seen from the other end — the
  first fake resolved the consumer, so the second one lands nowhere. Every fake wants a
  `test_a_second_fake_replaces_the_first`.
- **The filesystem case is not merely a silent pass.** `swapFs()` returned the real `LocalFsAdapter`,
  so the test then wrote to the forum's actual `data/` directory. A fake that quietly stops being a
  fake is worse than no fake. `integration/FilesystemResolveOrderTest` demonstrates it and cleans up
  after itself, because running it without the fix really does write that file.

To find the consumer: grep XenForo's `App.php` for the key you are swapping and see which other
container closure reads it.

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
  corresponding `assert*` helpers. Mail queueing is switched off rather than faked, so everything
  goes through `TestTransport` — 3.0.0 removed the old queue fake. `enableMailQueue` is a
  **config.php value, not an option**; `fakesMail()` set an option of that name from 2024 until
  4.0.0, which did nothing at all, and `queue()` kept enqueuing `MailSend` jobs the transport never
  saw.

Naming convention across the concerns, worth preserving: `fakes*()` installs an in-memory
implementation with assertion helpers, `mock*()` installs a Mockery double, `set*()` mutates state
that is restored in teardown.

## Documentation

- `README.md` — the tutorial: theory, installation, `build.json` cleanup, limitations, testing tips.
- `DOCS.md` — the API reference, one `###` section per public helper.

Both are user-facing and published; **a new or changed helper needs its `DOCS.md` section updated
in the same change**, and removed helpers stay listed struck-through with the removing version (see
`### ~~isolateAddon~~`).

## CHANGELOG

**Mark up identifiers in `CHANGELOG.md` with backticks** - `` `fakesMail()` ``, `` `TypeError` ``,
`` `enableMailQueue` ``. It renders better on GitHub and Packagist, and releases are announced as
BBCode resource-update posts on xenforo.com, converted mechanically from this file - a converter can
only convert what the source marks up, so bare identifiers stay bare all the way to the post.

The difference is not marginal. The 3.0.4 entry was written without backticks and yields four code
spans; 4.0.0's yields eighty.

## Style

Style is not a judgement call here — **`xenforo-ltd/xf-cs-fixer` decides it**, which is XenForo's
own PHP-CS-Fixer configuration (PER-CS plus their house rules). Run it before committing:

```bash
composer format          # apply
composer format:check    # report, changing nothing
```

That means tabs, Allman braces, and `<?php` on its own line with `namespace` below it. The whole
tree was converted in 4.0.0, including the `tests/` scaffold — the code previously used the
one-line `<?php namespace Foo;` form, which is what XenForo itself used before 2.3.

Do not hand-tune formatting or add rule overrides to preserve an older idiom: the point of using
their config is to stop making these decisions independently, and every override is one more thing
to re-check when XenForo updates it.
