# Upgrading

What you need to do to move an add-on's test suite from one release to the next, newest first.
Most releases need nothing beyond `composer update`. `CHANGELOG.md` lists everything that changed.

`tests/TestCase.php` is copied into your add-on, so a change to it has to be merged by hand.

## 5.26.0

Nothing to do. If one of your test classes already defines a `getLoggedMessages()` or an
`assertNothingLoggedContains()` method, rename it: `TestCase` now has a protected method of each
name, and a test class cannot redeclare one with a narrower visibility or an incompatible
signature without a fatal at class load.

```bash
grep -rnE 'function (getLoggedMessages|assertNothingLoggedContains)\(' tests/
```

An array passed to `buildVisitor()` or `actingAsMember()` for a column XenForo stores as a string -
`secondary_group_ids` - is now encoded rather than reaching the entity as an array. The stored
string form is unchanged, so a suite passing either shape needs nothing.

## 5.25.0

**`assertNoUnresolvedPhrases()` now catches a phrase key that ends exactly at the prefix**, so a
suite that has one goes red where it used to pass. That is exposure rather than a regression: every
key XenForo structures for an add-on puts the add-on's own id last - `option.<optionId>`,
`option_group.<groupId>`, `permission.<permissionId>` - so if your id is your prefix, those keys
were never being checked.

**No grep finds the affected suites**, because the condition is in rendered output rather than in a
call site. Run the suite; the failure names the key.

**One new false positive comes with it, on the admin option page.** With development mode on,
XenForo displays each option's id as ordinary text, so a bare prefix over that page now reports
every option whose id is the prefix, whether or not its phrase resolved. Tell the two apart by
whether the phrase's text is on the page as well:

```php
// the phrase resolved - its text is there - so a report of its key is the option id
$this->assertSee($html, 'Hide signatures');
```

The remedy is to pass the keys rather than the prefix:

```php
$this->assertNoUnresolvedPhrases($html, [
	'option.myaddon_hide_signatures',
	'option_explain.myaddon_hide_signatures',
]);
```

The ids are displayed only in development mode, so a bare prefix over that page passes on an
install with development off and fails on one with it on - worth changing even where it is green
today.

**The key the failure names has changed shape.** A dotted segment in front of the prefix is now
part of it, so a failure reads `option_explain.myaddon_thing` where it used to read
`myaddon_thing`. A test asserting on the failure message itself wants the fuller string.

**If one of your test classes already defines a `followRedirect()` or `globalTemplateData()`
method, rename it**: `TestCase` now has protected methods of those names.

**A suite whose test classes declare different `$addonsToLoad` lists can go red**, where one class
names an add-on that extends a class another class has already resolved. That test class was
getting the extension only when it happened to run first; the rest of the time it exercised
XenForo's own class with the extension's methods missing, and failed somewhere inside XenForo with
a `Call to undefined method` - or passed, if nothing it asserted needed the extension. `setUp()`
now refuses instead, naming the class and the extension:

```text
    XF\Entity\User is not extended by XFRM\XF\Entity\User
```

**Declare one list for the whole suite**, in `tests/TestCase.php`, naming every add-on whose class
extensions or content types any of your tests reach:

```php
protected $addonsToLoad = ['MyVendor/MyAddOn', 'XFRM'];
```

A grep lists the declarations, and for most suites there should be exactly one:

```bash
grep -rn 'protected \$addonsToLoad' tests/
```

Running one such class on its own still passes, so the refusal is about the suite rather than
about that class. Only widening is refused - a list that cannot *remove* an extension already
resolved is unchanged, and `requireClassNotExtended()` is still what a test asserting on that uses.

**A command name carrying whitespace or braces is now refused**, so a suite running such a command
goes red. The command still runs on the command line, which is why nothing caught it: Symfony
validates a command name against colons and nothing else, so an argument placeholder written into
`setName()` is accepted. What it breaks is `cmd.php list`, which prints the name as written, and
reaching the command at all once a second one shares its prefix. Declare the arguments separately:

```php
$this->setName('myaddon:thing')
    ->addArgument('subject', InputArgument::REQUIRED);
```

A grep finds the names written as a literal:

```bash
grep -rnE "setName\(['\"][^'\"]*[{}[:space:]]" .
```

**It cannot find a name composed in `configure()`** - `setName('myaddon:' . $this->suffix())` - and
those are the ones most likely to be wrong, since no one place shows the finished string. Running
the suite is what finds them.

## 5.24.0

**Rendered templates now have a style, so a render can produce more markup than it did.** `$xf.style`
used to be null, and a core template calling a method on it rendered nothing for that block - so a
test asserting that block's markup is *absent* was passing because the block never rendered, and
that assertion now fails. It is the assertion that was wrong, not the render. A test that scoped
`assertNoTemplateErrors()` to avoid the `isVariationsEnabled` error can drop the scope.

**`assertNoUnresolvedPhrases()` searches displayed text rather than the whole markup**, so it no
longer reports a phrase key that appears only in an attribute - a field name, an id, a `for` or a
CSS class. That can only turn a failing assertion into a passing one. The one thing it stops
catching is a phrase that failed to resolve inside an attribute, such as a submit button's `value`;
assert on that one directly.

`assertExceptionLogged()` and `assertExceptionNotLogged()` renamed their second parameter from
`$callback` to `$filter`. **A positional call needs nothing** - the value, its forms and the
behaviour are all unchanged - so for nearly every suite this release is a `composer update`.

Only a call that passes the argument **by name** is affected, and it cannot fail quietly: PHP
refuses it with `Error: Unknown named parameter $callback`. To find them ahead of the failure:

```bash
grep -rnE 'assertException(Not)?Logged\([^)]*callback:' tests/
```

Change `callback:` to `filter:` in anything it lists.

## 5.23.0

Five refusals, each of which can only turn red a test that was already not checking what it looked
like it checked.

**A `REQUEST_METHOD` value in `$server` now throws, in `dispatch()`,
`dispatchWithoutCsrfToken()` and `callAction()`.** It never had any effect there - the method comes
from the `$method` argument - so a test passing one was sending a different method from the one it
named. Move the value to `$method`. A `GET` test that was passing `'POST'` this way becomes a real
`POST`, which reaches the CSRF check and the action behind it; use `dispatchWithoutCsrfToken()`
where sending no token is the point.

**Only those three helpers are affected, and a search for the key finds a pattern that must be
left alone.** Building an `XF\Http\Request` yourself and swapping it into the container is the
other way to give a controller or a service a request, and there `REQUEST_METHOD` in the server
array is exactly how the method gets set - `Request::getRequestMethod()` reads it. Such a test has no
`$method` argument to move the value to, and removing the key would leave it with no method at all:

```php
// untouched by this release - the key is load-bearing here
$this->swap('request', new Request($this->app()->inputFilterer(), [], [], [], [
    'REQUEST_METHOD' => 'POST',
]));
```

So `git grep -n REQUEST_METHOD tests/` answers the wrong question: on the suites checked here every
hit was that pattern and none was a case this refusal governs. What tells them apart is whether the
value is going to one of the three helpers. Run the suite - the refusal names the helper it came
from.

**`assertNoUnresolvedPhrases()` now reads the page parameters too.** A suite can go red here, and
the key it reports is a phrase that genuinely came out unresolved in a `<xf:title>`,
`<xf:description>`, `<xf:h1>` or `<xf:pageaction>` - somewhere the old call could not see. The
failure names the parameter it came from, since searching the HTML for that key will not find it.

**An empty value is refused by the output assertions.** `assertSee($html, '')` used to pass against
anything and `assertDontSee($html, '')` used to fail against anything, so a test reaching this was
asserting nothing either way. The usual cause is a value built from something that came back empty
itself, which is the thing worth looking at.

**Every assertion in `InteractsWithTemplates` now takes a failure message last**, which the five
output assertions did not take at all - so a third argument passed to `assertSeeText()` or
`assertDontSeeText()` was discarded in silence, and one passed to `assertSee()` became its
`$escape` flag, where a non-empty string is merely truthy and therefore the default. Both are now
refused rather than accepted:

* a string where `assertSee()`, `assertDontSee()` or `assertSeeInOrder()` take `$escape`;
* a bool where `assertSeeText()` or `assertDontSeeText()` take their message. Those two strip the
  markup before searching, so there is nothing for an escaping flag to do and they do not have one.

A test doing either was passing, and goes from passing to a `LogicException` naming the helper and
which argument is which. **A grep will not find these for you** - an expected value containing a
comma looks exactly like a third argument, and on the suites checked here every hit of that search
was one. Run the suite: both refusals name the assertion and the argument.

A message is prefixed to what the assertion found rather than replacing it, so nothing that a
failure used to say is lost by supplying one. `assertNoUnresolvedPhrases()` already took a message
and used to substitute it for the list of unresolved keys; it now prefixes it like the rest, so a
test of yours that supplies one will start seeing the keys as well.

## 5.22.0

Nothing to do, unless a test of yours installs a second `fakesHttp()` or `fakesHttpByUrl()` and the
code under test holds a client built under the first one - an add-on's sub-container is the usual
case. That second fake did nothing, so such a test was either failing with
`OutOfBoundsException: Mock queue is empty` or passing against responses the first fake supplied. It
now gets the responses it queued.

Where a test worked around this by queueing every response in one call, that still works. Each
`fakesHttp()` replaces the queue rather than adding to it, so remove the extra responses if you
split the calls up again.

## 5.19.0

If your development forum has the *Use full friendly URLs* option **on**, dispatches that were
answered with a redirect to their own canonical url now return the page. A test written around that
redirect - the board index is the clearest case - should assert the page instead.

Otherwise nothing to do, unless a test of yours asserts the url of a redirect from an `admin` or
`api` dispatch. Those urls were built by the public router, so they carried `index.php` and a route that
router does not know; they are now `admin.php` or `api.php` urls, as the forum itself produces. A
test asserting the old value was asserting the defect.

## 5.15.0

**If a test of yours asserts that a `POST` dispatch is refused, it will now see the controller's own
reply instead.** `dispatch()` sends a valid CSRF token for any non-GET, so the check passes. Use
`dispatchWithoutCsrfToken()` where the refusal is the point.

Such a test does not fail - it changes meaning, because a refusal it was getting from the CSRF check
now has to come from your own code. Every one is in this list:

```bash
git grep -nE "dispatch\(.*'POST'" -- tests/
```

Read each: if the assertion is a refusal, decide which layer you meant to be refusing.

**Then check your own testing notes.** A `TESTING.md`, a `CLAUDE.md` or a comment explaining why a
write path had to be tested through `callAction()` is now wrong, and prose does not fail a test the
way a stale assertion does.

And worth a grep even though nothing breaks: `assertReplyParam($reply, $key, 'some value')` has
always taken that third argument as a failure message, so it asserts only that the parameter exists.
If you have one of those, `assertReplyParamSame($reply, $key, $expected)` is what it meant.

A grep only finds the ones written as a literal. Where the value is a variable or a method call there
is nothing to match on, and the refusal added in 5.14.0 is what finds those - on the next run of the
test, with the helper named in the message.

## 5.14.0

Two changes can turn a passing test red, and in both cases the test was not asserting what it looked
like it was:

* a reply assertion given a value as its last argument now throws. `assertReplyParam($reply, $key,
  $value)` never compared that value - use `assertSame($expected, $this->replyParam($reply, $key))`.
* `mockFinder()` given a name that is not `Vendor:Name` shaped, or a finder class, now throws with
  that name in the message. Placeholder strings left in older suites are the usual cause.

## 5.13.1

Nothing to do, unless your add-on declares `league/flysystem-memory` for `swapFs()` and
`composer update` has started refusing to resolve it. The README's installation section has the two
lines your own `composer.json` needs.

## 5.13.0

**Re-copy `phpunit.xml`.** It no longer sets `failOnPhpunitDeprecation`: PHPUnit 10.5 has no such
attribute, so a suite running on it fails configuration validation, and `failOnWarning` turns that
warning into a failed run with every test passing.

If your suite is locked to a PHPUnit between 10.0 and 10.4, `composer update` will move it within
10.5 - the shipped `phpunit.xml` has always needed 10.5.

Otherwise nothing to do, unless a `callAction()` call of yours names your own extension of a controller - it is
now refused, with the class to name instead. Passing the class your add-on extends is equivalent and
order-independent, because XenForo resolves it to the most derived class.

## 5.12.0

Nothing to do, unless a transactional test of yours runs code that calls `rollbackAll()` - running a
failing job through the job manager is the common case. That test was leaving rows in the database;
it now fails. Test the method directly, or leave `UsesDatabaseTransactions` off that class and undo
its writes yourself.

## 5.11.0

Nothing to do, unless a test of yours added a class extension and a later test relied on it still
being applied. Each test that needs it now adds it - and if more than one does, extend a class no
other test resolves, because the same class cannot be extended twice in one run.

## 5.10.0

Nothing to do. A suite that was failing intermittently with
`Cannot declare class ... XFCP_... because the name is already in use` should stop.

`setStaticProperty()` now restores the original value after each test. A test that relied on a static
it set persisting into a later test will need to set it in both.

If one of your test classes already defines a `runConsoleCommand()` method, rename it.

## 5.9.0

Nothing to do. If one of your test classes already defines a `renderJsonReply()` method, rename it,
and if one overrides `dispatch()`, add the new trailing `$method = 'GET'` parameter to your
signature.

## 5.8.0

Nothing to do, with two things to know.

`makeEntity()` now throws when a value it was given did not land, rather than returning an entity
missing it. A test relying on the old silence will fail where the value is set; use `setTrusted()`
for a value XenForo's verifiers reject.

`dispatch()` and `callAction()` now fire `app_pub_setup`, `app_admin_setup` or `app_api_setup` for
the type being dispatched, so a listener of your own on those events runs during tests where it
previously did not.

If one of your test classes already defines a `setAppClassType()` method, rename it.

## 5.7.0

If one of your test classes already defines a `renderBbCode()` or `mockFind()` method, rename it:
`TestCase` now has protected methods of those names.

Otherwise nothing to do, unless a test of yours deliberately runs schema changes inside
`UsesDatabaseTransactions`. That committed the transaction silently before; it now fails the test.
Move the schema change out of the transactional class, or clean up after it and reopen a
transaction for teardown to roll back.

## 5.6.0

Nothing to do. If one of your test classes already defines an `uploadedFile()`,
`uploadedFileFromPath()`, `assertNoUnresolvedPhrases()` or `renderRawReply()` method, rename it:
`TestCase` now has protected methods of those names. If one overrides `callAction()`, add the new
trailing `array $files = []` parameter to your signature.

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
