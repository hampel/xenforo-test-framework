# Upgrading

What you need to do to move an add-on's test suite from one release to the next, newest first.
Most releases need nothing beyond `composer update`. `CHANGELOG.md` lists everything that changed.

`tests/TestCase.php` is copied into your add-on, so a change to it has to be merged by hand.

## 5.5.0

Nothing to do, but check any test that both fakes HTTP and expects a request to be sent for real.
A client built with `$app->http()->createClient()` after the fake is installed is now faked, where
it previously made a live request.

## 5.4.0

**A template rendered by `renderTemplate()` now sees `$xf`.** A template that called into `$xf.app`
or `$xf.visitor` previously rendered that part as nothing; it now runs it. If a render starts
failing after upgrading, pass the parameter the template needs - `admin:user_edit` needs `user`:

```php
$html = $this->renderTemplate('admin:user_edit', ['user' => \XF::visitor()]);
```

A `templater_global_data` listener in your add-on now runs on every render.

## 5.3.0

Nothing to do. If one of your test classes already defines a `runJobToCompletion()` method, rename
it: `TestCase` now has a protected method of that name.

## 5.2.0

Nothing to do. If one of your test classes overrides `dispatch()` or `callAction()`, add the new
trailing `array $server = []` parameter to your signature.

## 5.1.0

Nothing to do. If one of your test classes already defines a `callAction()` method, rename it:
`TestCase` now has a protected method of that name.

## 5.0.0

**`tests/CreatesApplication.php` is no longer needed.** The framework boots the application itself,
reading `$rootDir` and `$addonsToLoad` from your test class. Keeping your copy works. To use the
framework's boot instead:

- delete `tests/CreatesApplication.php`;
- delete the `use CreatesApplication;` line from `tests/TestCase.php`.

To keep a boot of your own, override `createApplication()` in `tests/TestCase.php` and pass the
add-on ids on:

```php
return \XF::setupApp('Hampel\Testing\App', ['xf-addons' => $this->addonsToLoad]);
```

**Add-on isolation now filters code event listeners.** With `$addonsToLoad` set, listeners belonging
to other add-ons, `app_setup` included, no longer run. If a test fails after upgrading because
something another add-on set up is missing, add that add-on to `$addonsToLoad`.

**A half-upgraded scaffold now fails.** If `tests/TestCase.php` sets `$addonsToLoad` and
`tests/CreatesApplication.php` does not pass it to `XF::setupApp()`, every test errors with:

```text
This suite sets $addonsToLoad to [...], but the application was booted without it, so no add-on
isolation is in effect
```

Delete `tests/CreatesApplication.php` and its `use` line, as above.

**`Concerns\InteractsWithExtension` has been removed.** If one of your test classes uses it
directly, remove that line.

## 4.3.1

Nothing to do.

## 4.3.0

If your own `composer.json` requires `mockery/mockery` below 1.6, raise it to `^1.6`.

If you pass `assertReplyIsError()`'s third argument by name, rename it from `message` to
`errorText`.

`renderTemplate()` now throws when a template renders nothing because it raised an error. A render
that raised an error but still produced markup is returned as before; use `assertNoTemplateErrors()`
to fail on those as well.

`dispatch()` now runs work queued with `\XF::runOnce()`, such as a cache rebuild in an entity's
`postSave()`.

## 4.2.0

Nothing to do.

## 4.1.0

Nothing to do.

## 4.0.3

A user built by `actingAs()` no longer inherits an administrator record from your forum. A test that
relied on one needs to pass a loaded user to `actingAs()`, or from 4.1.0 grant admin permissions
with `setVisitorAdminPermissions()`.

## 4.0.2

Nothing to do.

## 4.0.1

Nothing to do.

## 4.0.0

Four things need action.

### 1. PHP 8.3 is now the minimum

If your add-on pins `config.platform.php` below 8.3, Composer cannot install 4.x. Raising the pin
can also let Composer select runtime dependencies above your add-on's own PHP floor, which then ship
in your release, so check the `packages` array in `composer.lock` afterwards.

### 2. PHPUnit 12 is now allowed

`composer update` will usually select PHPUnit 12, which ignores doc-comment metadata. Convert
`@dataProvider`, `@depends`, `@covers` and `@group` to attributes - `#[DataProvider]` and the others
work on PHPUnit 10, 11 and 12 - or pin `phpunit/phpunit` yourself.

### 3. Re-copy `phpunit.xml`

```bash
cp vendor/hampel/xenforo-test-framework/phpunit.xml .
```

Diff it against yours instead if you have customised it. It declares a Feature test suite, so
`tests/Feature` must exist and be committed: copy it from the scaffold, whose `ExampleTest.php`
keeps the directory in git.

### 4. Rename any `makeEntity()` or `createEntity()` of your own

Both are new protected methods on `TestCase`. If you delete yours to use these instead,
`createEntity()` saves and `makeEntity()` does not.

## 3.0.7

If your own `composer.json` requires `mockery/mockery` below 1.6, raise it to `^1.6`.

## 3.0.6

Nothing to do.

## 3.0.5

If you created `tests/Feature` by hand and your add-on is in git, copy `tests/Feature/.gitkeep` from
the package and commit it.

## 3.0.4

If your mail assertions use Swiftmailer's `email => name` array, update them: captured mail is a
`Symfony\Component\Mime\Email`, and `getTo()` returns `Address` objects.

```php
$to = $mail->getTo();

$to[0]->getAddress() == 'foo@example.com';   // not array_key_exists('foo@example.com', $to)
$to[0]->getName()    == 'Foo';               // not $to['foo@example.com'] == 'Foo'
```

## 2.1.0

`tests/TestCase.php` and `tests/CreatesApplication.php` both changed and need merging by hand. From
5.0.0 `tests/CreatesApplication.php` is no longer needed - see 5.0.0.

A new property in `tests/TestCase.php`:

```php
protected $addonsToLoad = [];
```

And new code in `tests/CreatesApplication.php`:

```php
$options['xf-addons'] = $this->addonsToLoad ?: [];

return \XF::setupApp('Hampel\Testing\App', $options);
```
