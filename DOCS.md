# XenForo Addon Unit Test Framework

Unit testing framework for XenForo

## Framework Documentation

### Unit Test Configuration

Your add-on owns one file, `tests/TestCase.php`, and it holds two properties. README.md covers
this under "9. Configuring the Framework".

```php
protected $rootDir = '../../../..';

protected $addonsToLoad = [];
```

`$rootDir` is the path to the XenForo root, relative to the directory the tests run from.
`'../../../..'` suits an add-on id carrying a vendor - `src/addons/Vendor/AddonId`. Without one -
`src/addons/AddonId` - use `'../../..'`. An absolute path works too. No trailing slash.

`$addonsToLoad` lists the add-on ids to load. Name your own and nothing else on the forum is
loaded. Leave it empty to load every installed add-on.

Naming ids in `$addonsToLoad` filters three things:

* **Composer autoloading** - only the named add-ons' autoloaders are registered
* **class extensions** - other add-ons' class extensions are not applied
* **code event listeners** - other add-ons' listeners do not run, including `app_setup`

Nothing else is filtered: the forum's database, options, phrases and templates are the real ones.

The framework boots the application: `Hampel\Testing\TestCase::createApplication()` requires
XenForo's `XF.php`, starts it, and passes `$addonsToLoad` to `XF::setupApp()`. A
`tests/CreatesApplication.php` from an earlier version still works if you keep it; delete it and
its `use` line to use the framework's boot.

To boot the application yourself, override `createApplication()` and pass the ids on:

```php
return \XF::setupApp('Hampel\Testing\App', ['xf-addons' => $this->addonsToLoad]);
```

If `$addonsToLoad` is set and the application is booted without it, `TestCase` throws a
`LogicException`.

### assertBbCode
Helper function for testing custom BBCode functions. Simply pass it some BBCode, tell it how you
want it parsed and then you can pass the expected HTML output to validate that your BBCode is being converted as
expected.

##### Parameters:

* `$expectedHtml` - the output you expect to receive
* `$bbCode` - the bbcode to be rendered
* `$type` - optional - the type of rendering to apply - see type options below
* `$context` - optional - the context for rendering
* `$content` - optional - the content being rendered, typically an entity

Type options:
*  `bbCodeClean` - renders a cleaned version of the BBCode itself
*  `editorHtml` - a blended HTML and BBCode version for display in the editor
*  `emailHtml` - a simplified HTML suitable for display in emails
*  `html` - the default fully rendered HTML output for browsers
*  `simpleHtml` - a simplified HTML suitable for display in signatures and so on

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class BbCodeTest extends TestCase
{
	public function test_bbcode_bold()
	{
		$bbCode = '[b]this should be bold[/b]';

		$expectedHtml = '<b>this should be bold</b>';
		$this->assertBbCode($expectedHtml, $bbCode, 'html');
	}
}	
``` 

### actingAs / actingAsMember / actingAsGuest
Run a test as a given user, so code that reads `\XF::visitor()` or checks permissions behaves as
it would for that user. The visitor is restored automatically after each test, and so is one a
test set itself with `\XF::setVisitor()`.

Users are built in memory and are never written to the database.

##### Parameters:

* `actingAs($user, $permissions = [])` - act as an existing `XF\Entity\User`
* `actingAsMember($values = [], $permissions = [])` - act as a logged-in member. Defaults to
  `user_id` 1 with a valid user state; pass `$values` to override any column
* `actingAsGuest($permissions = [], $username = null)` - act as a guest, `user_id` 0

`$permissions` is `group => [permission => value]`, matching the way XenForo caches global
permissions. Anything not granted is denied.

**Pair a denial test with a positive one.** A visitor with no permissions is denied everything, so
a test that only asserts denial passes even with the permission check removed.

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class PermissionTest extends TestCase
{
	public function test_a_member_who_may_post()
	{
		$this->actingAsMember(
			['user_id' => 5, 'username' => 'Alice'],
			['forum' => ['view' => true, 'postThread' => true]]
		);

		$this->assertTrue(\XF::visitor()->hasPermission('forum', 'postThread'));
		$this->assertFalse(\XF::visitor()->hasPermission('forum', 'deleteAnyPost'));

		// ... now exercise code that checks those permissions
	}

	public function test_a_guest()
	{
		$this->actingAsGuest();

		$this->assertSame(0, \XF::visitor()->user_id);
	}
}
```

### setVisitorPermissions / setVisitorContentPermissions
Grant permissions for a user without reading the permission cache from the database. Usually you
would pass permissions straight to `actingAs`; these are for granting more later, or for granting
content permissions.

##### Parameters:

* `setVisitorPermissions($user, $permissions)` - `$permissions` is `group => [permission => value]`
* `setVisitorContentPermissions($user, $contentType, $contentId, $permissions)` - for node
  permissions and the like

**Content permissions are not grouped.** They are `permission => value`, with no permission group
above them. Passing a grouped array grants nothing.

Each user built by the framework has its own permission combination, so granting a permission to one
does not grant it to another - see [buildVisitor](#buildvisitor).

**Grant permissions before the first permission check on that user.** XenForo caches the
`PermissionSet` relation, so a grant made after a check has no effect.

Admin permissions are granted separately, with `setVisitorAdminPermissions()`.

##### Example:

```php
$user = $this->actingAsMember();

$this->setVisitorPermissions($user, ['forum' => ['view' => true]]);
$this->setVisitorContentPermissions($user, 'node', 7, ['view' => true]);

$this->assertTrue($user->hasPermission('forum', 'view'));
$this->assertTrue($user->hasNodePermission(7, 'view'));
$this->assertFalse($user->hasNodePermission(8, 'view'));
```

### buildVisitor
Build an `XF\Entity\User` in memory without writing it to the database, for cases where you want
the entity but not to act as it.

##### Parameters:

* `values` - optional - column => value overrides
* `username` - optional

##### Example:

```php
$user = $this->buildVisitor(['user_id' => 99, 'username' => 'Built']);
```

**Each built user gets its own permission combination id**, counting up from 1000000. Permissions
granted to one built user do not apply to another, and a permission the test never granted is
denied. Pass `permission_combination_id` yourself to use an existing one:
`['permission_combination_id' => 1]` reads the forum's guest permissions.

**A built user has no `Admin` record**, so `hasAdminPermission()` is false. Grant admin permissions
with `setVisitorAdminPermissions()`, or pass a user you loaded yourself to `actingAs()`.

A user whose `user_state` is not `valid` uses the guest permission combination, as in XenForo, so
permissions granted to it have no effect. `actingAsMember()` sets `user_state` to `valid`.

### setVisitorAdminPermissions
Grant admin permissions to a built user, by giving it an administrator record.

##### Parameters:

* `user` - must already have `is_admin` set
* `permissions` - permission id => bool, as XenForo caches them
* `values` - optional - extra columns for the administrator record

##### Example:

```php
$admin = $this->actingAsMember(['is_admin' => true]);
$this->setVisitorAdminPermissions($admin, ['option' => true]);
```

Pass `['is_super_admin' => true]` in `values` for a super administrator, who has every permission
regardless of what is granted here.

### dispatch
Dispatch a route the way XenForo does, and return the reply its controller produced.

The controller's `preDispatch()` runs, so its access checks are tested - which a controller called
directly does not do.

##### Parameters:

* `routePath` - the route as it appears after the `?` in a URL, eg `help/terms`
* `type` - optional - `public` (the default), `admin` or `api`
* `input` - optional - the `GET` parameters the route reads, as `$_GET` would carry them
* `server` - optional - request server values, as `$_SERVER` would carry them, merged over the
  defaults. `REQUEST_METHOD` cannot be changed

##### Example:

```php
$reply = $this->dispatch('help/terms');

$admin = $this->actingAsMember(['is_admin' => true]);
$this->setVisitorAdminPermissions($admin, ['option' => true]);
$reply = $this->dispatch('options', 'admin');

$this->assertReplyTemplate($reply, 'option_group_list');
```

Reroutes are followed, so the reply is the one at the end of the chain.

An api route returns an `ApiResult` rather than a view - see `assertReplyIsApiResult()` below.

**An admin route needs a visitor with `is_admin` set**, and `dispatch()` throws if there is not
one.

**It does not render the page.** The reply carries the template name, view class and parameters. To
assert on HTML, pass the reply to `renderReply()` below, which renders the template but not the page
wrapper around it.

**A public route needs `general.view`**, which a built visitor does not have, so a public dispatch
returns a 403 until the test grants it:

```php
$member = $this->actingAsMember();
$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

$reply = $this->dispatch('members');
```

**Parameters go in `input`, not in the route path.** `dispatch('my-addon/user?user_id=1')` is a
404:

```php
$reply = $this->dispatch('my-addon/user', 'api', ['user_id' => 1]);
```

**`dispatch()` sends a `GET` only.** XenForo requires a CSRF token for anything else, so an action
opening with `assertPostOnly()` returns a 405. Use `callAction()` to test the action itself.

**Pass the server values the code under test reads** - IP address, user agent, referrer:

```php
$reply = $this->dispatch('help/terms', 'public', [], [
    'REMOTE_ADDR' => '2001:db8::7',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'HTTP_REFERER' => 'https://forum.example.com/threads/1/',
]);

$this->app()->request()->getIp();          // '2001:db8::7'
$this->app()->request()->getRobotName();   // 'google'
```

Without them, `REMOTE_ADDR` is `127.0.0.1` and the user agent and referrer are empty.

* `getIp()` applies the forum's trusted-proxy setting to `REMOTE_ADDR`, so on a forum with trusted
  proxies configured the address read back can differ from the one sent. `getIp(true)` reads
  `HTTP_X_FORWARDED_FOR` and `HTTP_CLIENT_IP`.
* On XenForo 2.3 `getFromSearch()` always returns an empty string: search referrals are not
  detected, whatever the referrer.

### callAction
Call a controller action directly with a `POST` request, and return the reply it produced. Use it
to test saving, toggling and deleting.

It skips `preDispatch()` - so the CSRF check and the controller's permission check do not run. Use
`dispatch()` to test those:

```php
// the guard - dispatch() runs preDispatch()
$this->actingAsMember(['is_admin' => true]);
$this->assertReplyIsError($this->dispatch('notices/save', 'admin'), 403);

// the action - callAction() does not
$reply = $this->callAction('XF:Notice', 'save', 'admin', [
    'title' => 'Maintenance',
    'message' => '...',
    'notice_type' => 'block',
    'display_style' => 'primary',
]);
$this->assertReplyIsRedirect($reply);
$this->assertDatabaseHas('xf_notice', ['title' => 'Maintenance']);
```

##### Parameters:

* `controller` - `'XF:Notice'` or a full class name
* `action` - as it appears in a route, slashes included: `'save'`, `'toggle'`, `'archive-users/run'`
  calls `actionArchiveUsersRun()`
* `type` - optional - `'public'` (default), `'admin'` or `'api'`
* `input` - optional - the request input, as `$_POST` would carry it
* `params` - optional - route parameters, eg `['notice_id' => 3]`
* `method` - optional - `'POST'` (default) or `'GET'`
* `server` - optional - request server values, as for `dispatch()`

**A validation failure comes back as an `Error` reply**, and `replyErrors()` returns its errors
keyed by field:

```php
$reply = $this->callAction('XF:Notice', 'save', 'admin', ['title' => '']);

$this->assertReplyIsError($reply);
$this->assertArrayHasKey('title', $this->replyErrors($reply));
```

A reply thrown as `XF\Mvc\Reply\Exception` - by `assertPostOnly()`, `assertRecordExists()` or a
permission check - comes back as that reply. The request is placed in the container as well as
passed to the controller. A `Reroute` is followed, and the action it leads to does run its
`preDispatch()`. Work queued with `\XF::runOnce()` has run by the time the reply is returned.

**A toggle's input is keyed by the column it toggles** - `active` unless the controller says
otherwise - so turning a notice off is `['active' => [$id => false]]` to the `toggle` action. With
the wrong key nothing is saved and the success message is still returned, so assert the row rather
than the reply.

**It writes.** Use `UsesDatabaseTransactions` on the test class, and `fakesRegistry()` where the
action rebuilds a cache.

A missing controller or action throws a `LogicException`.

### assertReplyIsView / assertReplyTemplate / assertReplyViewClass
Assert that a reply is a view, optionally rendering a given template or using a given view class.

##### Parameters:

* `reply` - as returned by `dispatch()`
* `template` or `viewClass` - for the second and third

##### Example:

```php
$reply = $this->dispatch('options', 'admin');

$this->assertReplyIsView($reply);
$this->assertReplyTemplate($reply, 'option_group_list');
$this->assertReplyViewClass($reply, 'XF:Option\GroupList');
```

When the reply is not the view expected, the failure message describes what it was, including an
error reply's text.

### assertReplyParam / replyParam
Assert that a view reply passed a given parameter to its template, and read it.

##### Parameters:

* `reply` - as returned by `dispatch()`
* `key` - the parameter name

##### Example:

```php
$reply = $this->dispatch('options', 'admin');

$this->assertReplyParam($reply, 'groups');
$this->assertCount(5, $this->replyParam($reply, 'groups'));
```

### assertReplyIsApiResult / replyApiResult
Assert that a reply is an api result - what an api route returns instead of a view - and read the
body a client would see.

##### Parameters:

* `reply` - as returned by `dispatch($routePath, 'api')`

##### Example:

```php
$reply = $this->dispatch('me', 'api');

$this->assertReplyIsApiResult($reply);
$this->assertSame('Admin', $this->replyApiResult($reply)->me->username);
```

Rendering is **not recursive**: a nested entity comes back as another result object needing its own
`render()`.

Without `actingAsApiKey()`, an api dispatch uses XenForo's fallback key, which is not a super user.
A route needing a scope that key does not carry returns an error with a 403.

### assertReplyIsRedirect / assertReplyIsError / assertReplyIsMessage
Assert that a reply is a redirect, an error or a plain message.

A route that does not exist returns an error with a 404, and a route whose permission check refuses
the visitor returns an error with a 403.

##### Parameters:

* `reply` - as returned by `dispatch()`
* `url` - optional, for `assertReplyIsRedirect`
* `type` - optional, for `assertReplyIsRedirect` - `permanent` or `temporary`
* `code` - optional http response code, for `assertReplyIsError`
* `errorText` - optional, for `assertReplyIsError` - matched as a substring of the error text
* `message` - optional, last on every one of them - added to the failure

##### Example:

```php
$this->assertReplyIsError($this->dispatch('no-such-route'), 404);

// an administrator without the permission the controller asserts
$this->actingAsMember(['is_admin' => true]);
$this->assertReplyIsError($this->dispatch('options', 'admin'), 403);

$this->assertReplyIsRedirect($this->dispatch('help/terms'), null, 'permanent');
```

**Assert the error text when more than one guard can refuse with the same code.** A test asserting
only a 403 passes whichever guard refused. `replyErrors($reply)` returns the messages as plain text.

**Assert a redirect's `type`, not its code.** `getResponseCode()` is `200` for every redirect reply;
the renderer later sends a 301 for permanent and a 303 for temporary.

Pass a `message` to identify the route in a test that dispatches several:

```php
foreach ($routes AS $route)
{
	$this->assertReplyIsError($this->dispatch($route, 'admin'), 403, null, $route);
}
```

### actingAsApiKey
Run api dispatches as a given api key.

##### Parameters:

* `values` - optional - columns for the key

##### Example:

```php
$this->actingAsMember();
$this->actingAsApiKey();                              // a super-user key with all scopes
$this->actingAsApiKey(['is_super_user' => false]);    // one that is not
```

`is_super_user` sets the key type that `assertSuperUserKey()` checks, and `allow_all_scopes`
grants every scope.

`\XF::$apiKey` is restored after each test.

**A super-user key does not bypass permissions.** That also needs `api_bypass_permissions` on the
request, which `dispatch()` cannot send, so the visitor's own permissions still apply.

### renderTemplate / renderReply
Render a template to HTML, with no web server - to check that a template modification applied, or
that a phrase resolved rather than rendering as a raw key.

**On a development install, a template with an `_output/` copy renders from that copy.** XenForo
re-imports the file before rendering whenever it differs from what was last compiled, so an edit to
`_output/` is seen without `xf-dev:import`, and a template changed only in the database is replaced
before it renders. The re-import saves the template and rewrites its compiled file, which a
transaction cannot roll back. With `$config['development']['enabled']` off, the database copy
renders.

To check that a template test fails when the template is wrong, break the `_output/` file rather
than the database copy, and restore it and `_metadata.json` afterwards.

**Template modifications are not re-imported this way.** An edit to
`_output/template_modifications/` is not seen until `xf-dev:import`.

##### Parameters:

* `template` - `type:title`, eg `public:thread_view`. The types are `public`, `admin` and `email`
* `params` - optional - the parameters the template reads

`renderReply($reply)` takes a reply from `dispatch()` instead, and renders the template it named
with the parameters it passed. Call it in the same test that dispatched, since the template type
comes from the dispatch.

**The `xf` parameter is set as it is for a page** - `$xf.options`, `$xf.visitor`, `$xf.time`,
`$xf.app` and the rest, from `getGlobalTemplateData()`. It is rebuilt for every render, so
`$xf.visitor` is whoever `actingAs()` set. An `xf` parameter the test adds to the templater itself
is left alone. Building it fires the `templater_global_data` event, so a listener for it runs on
every render.

**Pass every parameter the template requires.** A template such as `admin:user_edit` calls into
`$xf.app` with its own parameters, and a missing one fails the render rather than leaving part of
the template blank.

##### Example:

```php
$html = $this->renderTemplate('public:thread_view', ['thread' => $thread]);
$this->assertSee($html, 'Reply to thread');

// or straight from a dispatch
$reply = $this->dispatch('options', 'admin');
$this->assertSee($this->renderReply($reply), 'Option groups');
```

**A template that does not exist throws a `LogicException`**, rather than rendering as an empty
string.

**A template that fails and renders nothing throws too**, naming the error - a PHP error, a missing
macro or included template, or an exception.

**A template that raises an error but still renders markup is returned as normal.** Use
`assertNoTemplateErrors()` to fail on those as well.

A template that throws is detected in either debug mode: with `$config['debug'] = true` XenForo
renders the exception as markup, which is recognised, and without it the render is empty.

**This renders the template, not the page** - no navigation, header or footer. `pageParam()` reads
the values the template set for the page, such as its title.

### renderMacro
Render one macro from a template, with the arguments a caller would pass it - for a macro your
add-on adds, or where rendering the whole template would need parameters the test does not have.

##### Parameters:

* `template` - `type:title`, the template the macro is declared in
* `macro` - the macro's name, as in `<xf:macro name="...">`
* `arguments` - optional - the macro's arguments, by name

##### Example:

```php
$html = $this->renderMacro('public:thread_list_macros', 'item', ['thread' => $thread]);

$this->assertSee($html, $thread->title);
```

A macro that does not exist throws a `LogicException`. A macro that renders nothing for the
arguments given is returned as an empty string.

The `xf` parameter is set as for `renderTemplate()`.

### pageParam
A page parameter the rendered template set, such as the title from `<xf:title>`. These do not
appear in the rendered HTML. Call it after a render.

##### Parameters:

* `name` - XenForo's own name for the parameter

| the tag | the name |
|---|---|
| `<xf:title>` | `pageTitle` |
| `<xf:description>` | `pageDescription` |
| `<xf:h1>` | `pageH1` |
| `<xf:pageaction>` | `pageAction` |

##### Example:

```php
$html = $this->renderTemplate('public:thread_view', ['thread' => $thread]);

$this->assertSame('Welcome to the board', $this->pageParam('pageTitle'));
```

Returns `null` if the render did not set it. Each render can overwrite the values, so read the one
you want before rendering something else.

### assertNoTemplateErrors
Assert that no template this test rendered raised an error.

##### Example:

```php
$html = $this->renderReply($this->dispatch('my-addon/report', 'admin'));

$this->assertSee($html, 'Reports');
$this->assertNoTemplateErrors();
```

It covers every render in the test, not only the last.

**A template error is also written to the forum's `xf_error_log`.** To prevent that, either:

* call `fakesErrors()` before the render - the errors are still available to
  `assertNoTemplateErrors()`, and `assertExceptionLogged()` can assert on them; or
* use `UsesDatabaseTransactions` on the test class, which rolls the rows back.

### assertSee / assertDontSee / assertSeeInOrder
Assert on rendered output.

##### Parameters:

* `html` - as returned by `renderTemplate()` or `renderReply()`
* `value`, or `values` for `assertSeeInOrder`
* `escape` - optional, default `true`

##### Example:

```php
$this->assertSee($html, "Bob's thread");            // matches Bob&#039;s thread
$this->assertSeeInOrder($html, ['First', 'Second']);
$this->assertSee($html, '<div class="block">', false);
```

**The expected value is escaped by default**, matching `XF::escapeString()`. Pass `false` to match
raw markup.

**To check a phrase resolved, assert its key is absent - not your add-on's prefix.** A phrase
XenForo cannot find renders as its key; your prefix also appears legitimately in field names and CSS
classes.

```php
// a phrase resolved: its text is present, and its key is not
$this->assertSee($html, 'Email me a digest');
$this->assertDontSee($html, 'myaddon_preference_label');
```

**Pair every `assertDontSee()` with an `assertSee()`.** An absence assertion alone still passes if
nothing rendered.

### assertTemplateModificationApplied
Assert that a template modification has applied, using its apply count - useful where its insertion
has no distinctive markup to search for.

##### Parameters:

* `modificationKey` - as in `_output/template_modifications/`

##### Example:

```php
$this->assertTemplateModificationApplied('myaddon_helper_account');
```

A modification whose `find` no longer matches still has status `ok`, with an apply count of zero.

The apply count is the one recorded when the template was last compiled.

A modification inserting an `<xf:include>` does not put the included template's name in the output,
so assert on the markup it produces instead.

### assertSeeText / assertDontSeeText / textOf
Assert on the text of rendered output, ignoring the markup. Tags are stripped and entities decoded
first, so text split across a tag boundary still matches.

##### Parameters:

* `html`
* `value`

##### Example:

```php
// passes against <p>Bob&#039;s <b>thread</b></p>
$this->assertSeeText($html, "Bob's thread");
```

`textOf($html)` returns that text, for asserting on it yourself.

### swap
Register an instance of an object in the container.

##### Parameters:

* `key` - the container key to be swapped
* `instance` - the object or closure to swap in

##### Alternative parameters - subcontainers:

* `key (array)` - array containing the container key or instance to be swapped and the subcontainer key to be swapped 
* `instance` - the object or closure to swap in

##### Examples:

```php
<?php namespace Tests\Unit;

use Hampel\Testing\SimpleCache; // our custom SimpleCache implementation
use Tests\TestCase;

class SwapTest extends TestCase
{
	public function test_swap()
	{
		// replace the simpleCache with our custom implementation
		$this->swap('simpleCache', function () {
			return new SimpleCache([]);
		});		

		// retrieve the simpleCache from the app container
		$simpleCache = $this->app['simpleCache'];
		
		// do something which causes an update to the simpleCache
		
		// now check that the simpleCache contains the key/value that we expect
		$this->assertTrue(
			$simpleCache()->keyExists('MyAddon', 'foo'),
			"The expected [foo] key does not exist."
		);
	}
	
	public function test_subcontainer_swap()
	{
	    // replace the 'userChecker' key from the 'spam' subcontainer
	    $this->swap(['spam', 'userChecker'], function () {
	        return new MyImplementationOrMock();
	    });
	    
	    // retrieve the userChecker from the app container
	    $checker = $this->app()->spam()->userChecker();
	    
	    // now you can interact with your replaced userChecker
	    
	    // alternative syntax - specify the actual subcontainer object as the first array entry
	    $this->swap([$this->app()->spam(), 'userChecker'], function () {
	        return new MyImplementationOrMock();
	    });	    
	}
}	
```

### mock
Mock an instance of an object in the container

##### Parameters:

* `key` - the container key to be swapped with a mock
* `abstract` - the base class or interface to use for the mock
* `mock` - optional - the mock closure to define expectations on

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;
use XF\Http\Request;

class MockTest extends TestCase
{
	public function test_mock()
	{		
		// mock our Request object so we can control what actually goes in there
		$this->mock('request', Request::class, function ($mock) {
		   $mock->expects()->getIp(false)->once()->andReturns('10.0.0.1');
		});
		
		// execute some test code which causes the Request object to be queried, for example
		$ip = $this->app->request()->getIp();
		
		// validate we received the expected data in response
		$this->assertEquals('10.0.0.1', $ip);
	}
}	
```

### spy
Record what the container's object was asked to do, and assert it afterwards.

`mock()` declares up front what must happen. `spy()` records every call, and the assertions come
after the code under test has run.

##### Parameters:

* `key` - the container key to be swapped with a spy
* `abstract` - the base class or interface to use for the spy
* `mock` - optional - a closure to set return values on, for the calls whose result matters

##### Example:

```php
$request = $this->spy('request', \XF\Http\Request::class);

// run the code under test, which asks the request for the visitor's IP

$request->shouldHaveReceived('getIp');
$request->shouldNotHaveReceived('getUserAgent');
```

Arguments are matched the way they were passed, so a call the code made as `getIp(false)` is
asserted as:

```php
$request->shouldHaveReceived('getIp')->with(false);
```

**A spy returns `null` from every method it was not told about.** Where the code under test uses
the return value, give the spy a closure:

```php
$request = $this->spy('request', \XF\Http\Request::class, function ($mock)
{
    $mock->allows()->getIp(false)->andReturns('10.0.0.1');
});
```

or reach for `mock()` instead.

Like `swap()`, a spy does not reach anything already built from the key it replaces.

### mockFactory
Mock a factory builder in the container.

##### Parameters

* `key` - the container key to be swapped with a mock
* `abstract` - the base class or interface to use for the mock
* `mock` - optional - the mock closure to define expectations on

#### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;
use XF\Http\Request;

class FactoryTest extends TestCase
{
	public function test_data()
	{		
		// mock our factory
		$this->mockFactory('data', \XF\Data\Currency::class, function ($mock) {
			$mock->expects()->getCurrencySymbol('AUD')->once();
		});
		
		// execute some test code which causes the mocked code to be executed, for example
		$currency = $this->app()->data('XF:Currency');
		$currency->getCurrencySumbol('AUD');
	}
}	
```

### mockService
Mock a service factory builder in the container.

##### Parameters

* `shortName` - the short name of the service class to be mocked
* `mock` - optional - the mock closure to define expectations on

* **It replaces the container's entire `service` factory.** Every `$app->service(...)` call for the
  rest of the test returns the same mock, whatever short name is asked for. If the subject of your
  test is itself a service, resolve it *before* calling `mockService()`.
* **The short name must resolve to a class that exists**, or it throws a `LogicException`. XenForo
  2.3 service classes carry a `Service` suffix: `MyAddon:MessageEvent` resolves to
  `MessageEventService`.
* **The mock is typed as the class XenForo would build**, including an add-on's extension of it.

#### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;
use XF\Http\Request;

class ServiceTest extends TestCase
{
	public function test_service()
	{		
		// mock our service class
		$this->mockService('XF:User\EmailStop', function ($mock) {
			$mock->expects()->stop('list')->once();
		});
		
		// execute some test code which causes the mocked code to be executed, for example
		$emailStop = $this->app()->service('XF:User\EmailStop');
		$emailStop->stop('list');
	}
}	
```

### UsesDatabaseTransactions
Wrap each test in a database transaction and roll it back afterwards, so tests can save entities
and run real queries without leaving anything behind. Add the trait to each test class that needs it.

Code under test may open and commit its own transactions; they nest inside the wrapper, and are
rolled back with it - including when the code commits and then throws.

Two things it cannot roll back:

* DDL implicitly commits, so anything altering the schema - a `Setup.php` step, for instance -
  escapes the transaction and must clean up after itself.
* Only this connection sees the uncommitted rows, so a test that reads the database through a
  second connection will not see what it wrote.

It cannot be combined with `mockDatabase()`, and throws if you try.

##### Example:

```php
<?php namespace Tests\Unit;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class ThingTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_saving_a_thing()
	{
		$thing = $this->app()->em()->create('MyVendor\MyAddon:Thing');
		$thing->title = 'probe';
		$thing->save();

		$this->assertDatabaseHas('xf_myaddon_thing', ['title' => 'probe']);

		// ... and the row is gone again once the test finishes
	}
}
```

### assertDatabaseHas / assertDatabaseMissing / assertDatabaseCount
Assert against rows actually present in the database. Most useful alongside
`UsesDatabaseTransactions`, which keeps whatever the test writes from persisting.

These read the real database, so they cannot be used with `mockDatabase()` and will throw if the
database has been mocked.

##### Parameters:

* `table` - the table name, in full - no `xf_` prefix is assumed
* `criteria` - an array of `column => value` pairs, combined with `AND`. A `null` value matches
  `IS NULL`
* `expected` - `assertDatabaseCount` only - the number of rows expected

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class ThingTest extends TestCase
{
	public function test_database_state()
	{
		$this->assertDatabaseHas('xf_user', ['user_id' => 1]);
		$this->assertDatabaseMissing('xf_user', ['username' => 'nobody']);
		$this->assertDatabaseCount('xf_user', 1, ['user_id' => 1]);

		// no criteria counts the whole table
		$this->assertDatabaseCount('xf_myaddon_thing', 0);
	}
}
```

### mockDatabase
Mock the database adapter.

##### Parameters

* `mock` - optional - the mock closure to define expectations on

**Call it before `mockRepository()`, `mockFinder()` or `mockEntity()`.** It rebuilds the entity
manager, which discards any repository, finder and entity mocks registered before it.

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;
use XF\Http\Request;

class DbTest extends TestCase
{
	public function test_db()
	{		
		// mock our Database so we can execute queries during tests without side effects
		$this->mockDatabase(function ($mock) {
			$cutoff = \XF::$time - 86400;

			$mock->expects()->delete('xf_mytable', 'some_date < ?', $cutoff);
		});
		
		// execute some test code which causes the query to be executed, for example
		$this->db()->delete('xf_mytable', 'some_date < ?', \XF::$time - 86400);		
	}
}	
```

### mockRepository
Mock a repository.

##### Parameters

* `identifier` - the short class name for the repository 
* `mock` - optional - the mock closure to define expectations on

Any spelling `$app->repository()` accepts works here too, and reaches the same mock: `XF:User`,
`XF:UserRepository`, or the full `\XF\Repository\UserRepository`.

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class RepoTest extends TestCase
{
	public function test_repo()
	{		
		// mock our Repository and specify expectations
		$this->mockRepository('MyVendor\MyAddon:MyRepo', function ($mock) {
			$mock->expects()->myRepoFunction()->with('foo')->andReturn('bar');
		});
		
		// execute some test code which causes the repository function to be executed, for example
		$repo = $this->app()->repository('MyVendor\MyAddon:MyRepo');
		$result = $repo->myRepoFunction('foo');	
		
		// check we got the expected response
		$this->assertEquals('bar', $result);
	}
}	
```

### makeEntity / createEntity
Build an entity with the given values. `makeEntity` leaves it unsaved and touches no database;
`createEntity` saves it.

`createEntity` writes real rows, so use it with `UsesDatabaseTransactions` unless you want them to
outlive the test.

**If your test classes already have a method called `makeEntity` or `createEntity`, rename it** -
otherwise the class fails to load with `Access level to ... must be protected`.

##### Setting an id on an unsaved entity

A primary key is usually a read-only column, so passing one in `values` throws
`Column 'node_id' is read only, can only be set with forceSet`. Use XenForo's `setTrusted()`:

```php
$user = $this->makeEntity('XF:User', ['username' => 'Alice']);
$user->setTrusted('user_id', 42);
```

##### Building a fixture that spans relations

An unsaved entity cannot load its relations from the database. Link entities in memory with
XenForo's `hydrateRelation()`:

```php
$node = $this->makeEntity('XF:Node', ['title' => 'Test node']);
$node->setTrusted('node_id', 1);

$forum = $this->makeEntity('XF:Forum');
$forum->setTrusted('node_id', 1);

$thread = $this->makeEntity('XF:Thread', ['node_id' => 1]);
$thread->setTrusted('thread_id', 1);

$forum->hydrateRelation('Node', $node);
$thread->hydrateRelation('Forum', $forum);

// $thread->Forum->Node->node_id now resolves, with no database at all
```


##### Parameters:

* `shortName` - eg `XF:User`, or `MyVendor\MyAddon:Thing`
* `values` - column => value

##### Example:

```php
<?php namespace Tests\Unit;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class ThingTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_something()
	{
		// in memory only - nothing is written
		$unsaved = $this->makeEntity('XF:User', ['username' => 'Alice']);

		// a real row, removed again when the test finishes
		$user = $this->createEntity('XF:User', [
			'username' => 'Bob',
			'email' => 'bob@example.com',
		]);

		$this->assertDatabaseHas('xf_user', ['username' => 'Bob']);
	}
}
```

### mockFinder
Mock a Finder.

##### Parameters

* `identifier` - the short class name for the finder 
* `mock` - optional - the mock closure to define expectations on

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class FinderTest extends TestCase
{
	public function test_finder()
	{		
		// Finders will return entities or collections of entities, create one to return from our mock
		$entity = $this->app()->em()->create('MyVendor\MyAddon:MyEntity');
		$entity->foo = 'bar';
		
		// mock our Finder and specify expectations
		$this->mockFinder('MyVendor\MyAddon:MyEntity', function ($mock) use ($entity) {
			$mock->expects()->where('entity_id', '=', 1)->once()->andReturnSelf();
			$mock->expects()->fetchOne()->once()->andReturns($entity);
		});
		
		// execute some test code which causes the Finder function to be executed, for example
		$finder = $this->app()->finder('MyVendor\MyAddon:MyEntity');
		$result = $finder->where('entity_id', 1)->fetchOne();
		
		// check we got the expected response
		$this->assertEquals('bar', $result->foo);
	}
}	
```

### mockEntity
Mock an Entity.

##### Parameters

* `identifier` - the short class name for the repository 
* `inherit` - optional - set to false to disable inheritance, thus bypassing the `final function save()` issue
* `mock` - optional - the mock closure to define expectations on

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class EntityTest extends TestCase
{
	public function test_entity()
	{		
		// mock an entity that we can pass around without needing to fully hydrate
		$user = $this->mockEntity('XF:User');
		
		// a Finder or Repository may return a user
		$this->mockRepository('XF:User', function ($mock) use ($user) {
			$mock->expects()->getVisitor()->with(0)->once()->andReturns($user);
		});
		
		// execute some test code which causes 
		$visitor = \XF::visitor();
	}
}	
```

`save()` on the base Entity class is `final`, so a mock cannot stop it reaching the database. Use
`UsesDatabaseTransactions` so the save is rolled back, or pass `false` as the second parameter to
build a mock that does not inherit from the entity class.

### fakesErrors
Allow us to assert that certain errors were (or were not) thrown as a result of executing our test code, without
side-effects (ie no logs written to database).

##### Parameters:

none

##### Assertions available:

* `assertExceptionLogged`
* `assertExceptionLoggedTimes`
* `assertExceptionNotLogged`
* `assertNoExceptionsLogged`
* `assertErrorLogged` - takes an optional message; omit it to assert that any error was logged
* `assertErrorNotLogged` - takes an optional message; omit it to assert that no error was logged
* `assertNoErrorsLogged`

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class ErrorTest extends TestCase
{
	public function test_error()
	{		
		// initialise the error fake system
		$this->fakesErrors();
		
		// we don't want to deal with actual phrases causing DB lookups, so use the expectPhrase helper to mock a phrase
		$phrase = $this->expectPhrase('myaddon_error');
		
		// execute some test code which generates an error
		...

		// assert we got the error we were expecting		
		$this->assertErrorLogged($phrase);
	}
}	
```

Refer to the `Hampel\Testing\Concerns\InteractsWithErrors` trait for full details of available error validation 
functions.

### ~~isolateAddon~~

_Removed in v2.1.0 - use `$addonsToLoad`, described under Unit Test Configuration_

Prevented class extensions and code event listeners from other addons being loaded during tests.

This should be run in the `setup()` function for the test class - it will affect all tests in that class.

##### Parameters:

* `addon` - the `addon_id` of the addon which should be permitted to load listeners / extensions

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class IsolationTest extends TestCase
{
	protected function setUp() : void
	{
		parent::setUp();

		// isolate our addon so only our class extensions and code event listeners get loaded
		$this->isolateAddon('MyVendor/MyAddon');
	}	
	
	public function test_isolation()
	{		
		// execute some test code 	
	}
}	
```

### swapFs
Allow us to swap out the local filesystem with a memory based filesystem which is non-persistent. Ideal for avoiding
side-effects when writing to the filesystem.

Requires `league/flysystem-memory: ^1.0` in your addon's `require-dev`; later versions do not work
with XenForo 2.3.

**This only helps when every access goes through `$app->fs()`.** Code that writes to a real path -
`XF\Util\File::getTempDir()`, `File::getNamedTempFile()` - and reads it back through an abstracted
path such as `internal-data://` fails under a swapped filesystem, because the two no longer point at
the same place. Test such code against the real filesystem, writing to a path you delete in
`tearDown()`.

`$fs->has()` does not reliably report directories, so call `deleteDir()` unconditionally, in a
try/catch, when cleaning up.

##### Parameters

* `fs` - the name of the filesystem to swap (eg `data`, `internal-data`, `code-cache`)

##### Assertions available

* `assertFsHas`
* `assertFsHasNot`

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class SwapFsTest extends TestCase
{
	public function test_swapfs()
	{	
		// replace local filesystem for internal-data with a memory-based filesystem 
		$this->swapFs('internal-data');
		
		// execute some test code which writes to internal data - changes will not be persisted once test completes
		$this->app()->fs()->copy('internal-data://temp/filea.txt', 'internal-data://temp/fileb.txt');
		
		$this->assertFsHas('internal-data://temp/fileb.txt');
	}
}	
```

### mockFs
Allow us to mock the local filesystem to assert that certain operations have taken place without any changes being made

##### Parameters:

* `fs` - the name of the filesystem to mock (eg `data`, `internal-data`, `code-cache`)
* `mock` - optional - the mock closure to set expectations on

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class MockFsTest extends TestCase
{
	public function test_mockfs()
	{	
		// replace local filesystem for internal-data with a memory-based filesystem 
		$this->mockFs('internal-data', function ($mock) {
			$mock->expects()->has('foo')->andReturns(true);
		});
		
		// execute some test code which access internal data
		$this->app()->fs()->has('internal-data://foo');
	}
}	
```

### fakesEvents
Record the code events fired by our code, and stop them reaching any listener - so we can assert
that an event was fired without triggering whatever is listening for it.

Only listeners are suppressed. Class extensions still resolve as normal, so mocked repositories,
finders and entities keep working.

While faking, `fire()` always reports that nothing vetoed the event, because no listener ran to
veto it.

##### Parameters:

none

##### Assertions available:

* `assertEventFired`
* `assertEventFiredTimes`
* `assertEventNotFired`
* `assertNoEventsFired`

Call it **before** the code under test resolves anything that holds the extension.

Truth-test callbacks receive `($args, $hint)`; declaring only `$args` is fine. `getFiredEvents()`
returns every event, each as `['event' => ..., 'args' => [...], 'hint' => ...]`.

Arguments are recorded as they were when the event fired, including ones passed by reference.
Objects are recorded as the same instance.

Requires `enableListeners` in `config.php`, and throws without it.

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class EventTest extends TestCase
{
	public function test_our_event_fires()
	{
		$this->fakesEvents();

		// ... execute code that fires a custom event

		$this->assertEventFired('my_addon_thing_processed');

		// ... or assert how many times
		$this->assertEventFired('my_addon_thing_processed', 2);

		// ... or assert on what it was fired with
		$this->assertEventFired('my_addon_thing_processed', function ($args, $hint)
		{
			return $args[0]->thing_id == 5;
		});

		$this->assertEventNotFired('my_addon_thing_failed');
	}
}
```

### fakesHttp
Allow us to assert that certain HTTP requests were (or were not) sent as a result of executing our test code, and to 
supply mock HTTP responses without side-effects (ie no requests actually sent).

This function relies on the Mock Handler and History Middleware provided by the Guzzle HTTP library used by XenForo.

Refer to the Guzzle documentation [Testing Guzzle Clients](http://docs.guzzlephp.org/en/stable/testing.html) for more
information on how the Mock Handler and History Middleware works. 

##### Parameters:

* `array responseStack` - an array of Psr7 Responses or Request Exceptions to return - one for each request made
* `bool untrusted` - set to true when using the untrusted client in XenForo

##### Assertions available:

* `assertHttpRequestSent`
* `assertHttpRequestSentTimes`
* `assertHttpRequestNotSent`
* `assertNoHttpRequestSent`

##### Example:

```php
<?php namespace Tests\Unit;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use Tests\TestCase;

class HttpTest extends TestCase
{
	public function test_http()
	{	
		// tell Guzzle not to send requests, but to instead return our mock responses, one for each
		// request that we make 
		$this->fakesHttp([
			new Response(200, ['X-Foo' => 'Bar'], 'Hello, World'),
			new Response(202, ['Content-Length' => 0]),
			new RequestException('Error Communicating with Server', new Request('GET', 'test'))
		]);

		// execute some code which sends an Http request
		$response1 = $this->app()->http()->client()->get('/');
		$response2 = $this->app()->http()->client()->get('/foo');
		$response3 = $this->app()->http()->client()->get('/bar');
		
		// assert something about the requests that were sent
		$this->assertHttpRequestSent(function ($request) {
			return strval($request->getUri()) == '/' OR strval($request->getUri()) == '/foo';
		});
		
		// assert something about our responses
		...		
	}
}	
```

### fakesHttpByUrl
Mock the Http client, choosing the response by URL rather than by call order.

Unlike `fakesHttp`, the test does not depend on the order the requests are made in.

##### Parameters:

* `responseMap` - pattern => response. Patterns are `fnmatch()` patterns, tried in order, so `*`
  on its own is a catch-all and belongs last. A value may be a Guzzle Psr7 Response, an exception
  to throw, or a callable receiving the request and returning a response
* `untrusted` - optional - set to true when using the untrusted client

A request matching no pattern throws.

The same assertions as `fakesHttp` apply - `assertHttpRequestSent`, `assertHttpRequestSentTimes`,
`assertHttpRequestNotSent`, `assertNoHttpRequestSent`.

Like `fakesHttp`, the response body is written to Guzzle's `sink` when the request asks for one,
so code that downloads to a file through `XF\Http\Reader::getUntrusted($url, $limits, $saveTo)`
gets the faked content on disk.

Only one http fake is active at a time. Calling either helper again in the same test replaces the
previous fake, including for anything holding `$app->http()->reader()`.

##### Example:

```php
$this->fakesHttpByUrl([
	'*/api/users/*' => new Response(200, [], '{"ok":true}'),
	'*/api/status' => function ($request)
	{
		return new Response(200, [], $request->getMethod());
	},
	'*' => new Response(404),
]);

// ... execute code which makes http requests, in any order

$this->assertHttpRequestSentTimes(2);
```

### fakesJobs
Allow us to assert that certain jobs were (or were not) queued as a result of executing our test code, without
side-effects (ie no jobs written to database or executed).

##### Parameters:

none

##### Assertions available:

* `assertJobQueued`
* `assertJobQueuedTimes`
* `assertJobNotQueued`
* `assertNoJobsQueued`

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class JobTest extends TestCase
{
	public function test_job()
	{		
		// initialise the job fake system
		$this->fakesJobs();
		
		// execute some test code which queues a job, for example:
		$this->app->jobManager()->enqueue('MyVendor/MyAddon:MyJob', [
			'key1' => 'value1',
			'key2' => 'value2'
		]);

		// assert our job was queued as expected
		$this->assertJobQueued('MyVendor/MyAddon:MyJob');

		// alternatively, assert our job was queued with specific attributes - return a truth test
		$this->assertJobQueued('MyVendor/MyAddon:MyJob', function ($job) {
			return $job['execute_data']['key1'] == 'value1'
				&& $job['execute_data']['key2'] == 'value2';
		});
	}
}	
```

**The callback receives an array, not a job object**, with XenForo's own column names:

| key | holds |
|---|---|
| `execute_class` | the job class, exactly as the caller named it |
| `execute_data` | the parameters passed to `enqueue()` |
| `unique_key` | the unique id, or `null` |
| `manual_execute` | whether it was queued to run manually |
| `trigger_date` | the run time |

**Match the job name exactly as the code under test queued it.** Code calling
`enqueue('XF:FileCleanUp', …)` is asserted as `assertJobQueued('XF:FileCleanUp')`, and code calling
`enqueue(\XF\Job\FileCleanUp::class, …)` as `assertJobQueued(\XF\Job\FileCleanUp::class)`; the
two do not match each other.

Refer to the `Hampel\Testing\Concerns\InteractsWithJobs` trait for full details of available job validation 
functions.

### runJobToCompletion
Run a job until it completes, and return its final `JobResult` - to test what the job does, where
`fakesJobs()` tests that it was queued.

Each pass builds a new instance of the job from the data the previous pass returned, as XenForo's
job manager does, so a job that keeps its position only on the instance never completes here either.
Work queued with `\XF::runOnce()` runs after each pass.

##### Parameters:

* `jobClass` - `'MyVendor\MyAddon:ArchiveUsers'` or a full class name
* `data` - optional - the job's parameters, as `enqueue()` would take them
* `maxRunTime` - optional - seconds allowed per pass, default 30
* `maxPasses` - optional - passes allowed before the job is judged not to finish, default 1000

##### Example:

```php
// a batch size of 1 runs every item as a pass of its own
$result = $this->runJobToCompletion('MyVendor\MyAddon:ArchiveUsers', ['batch' => 1]);

$this->assertDatabaseMissing('xf_user', ['user_state' => 'to_archive']);
```

It throws a `LogicException` if the job reports failure, does not complete within `maxPasses`, or
does not exist. An exception the job throws is not caught - it fails the test. Unlike the job
manager, nothing is rolled back, so use `UsesDatabaseTransactions` for a job that writes.

Jobs queued by the job under test are queued as normal; use `fakesJobs()` to assert on them.

**The entity cache is not cleared between jobs**, just as XenForo's job manager does not clear it
when it runs several jobs in one request. So if one job creates a related row without hydrating the
relation on an entity it loaded, a second job run in the same test - or the same request in
production - finds that entity with the relation still empty. Clear it yourself with
`$this->app()->em()->clearEntityCache()` if you want each job to start fresh.

### expectPhrase
Allow us to easily mock the phrase/language system to avoid database lookups and rendering phrases. This is especially
useful when dealing with error messages which include phrases that may be variable.

##### Parameters:

* `key` - the phrase_id
* `parameters` - optional - parameters that are expected to be passed to the phrase
* `response` - optional - the response that should be returned
	 
##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class PhraseTest extends TestCase
{
	public function test_phrase()
	{		
		// initialise the language mocks
		$this->expectPhrase('my_phrase');
		
		// execute some test code which retrieves a phrase:
		$phrase = \XF::phrase('my_phrase');

		// assert we received our phrase as expected		
		$this->assertEquals('my_phrase', strval($phrase));
		
		// alternatively, pass parameters and/or an abitrary rendering
		$this->expectPhrase('my_phrase', ['foo' => 'bar'], 'My phrase renders with [bar]');
		
		// execute some test code which retrieves a phrase:
		$phrase = \XF::phrase('my_phrase', ['foo' => 'bar']);

		// assert we received our phrase as expected		
		$this->assertEquals('My phrase renders with [bar]', strval($phrase));
	}
}	
```

### fakesLogger
Allow us to assert that certain moderator actions were (or were not) logged as a result of executing our test code, 
without side-effects (ie no logs written to database).

##### Parameters:

none

##### Assertions available:

* `assertActionLogged`
* `assertChangeLogged`
* `assertActionLoggedTimes`
* `assertChangeLoggedTimes`
* `assertActionNotLogged`
* `assertChangeNotLogged`
* `assertNoActionsLogged`
* `assertNoChangesLogged`

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class LoggerTest extends TestCase
{
	public function test_logger()
	{		
		// initialise the Logger fake system
		$this->fakesLogger();
		
		// use a mock user so we don't have to hydrate it
		$user = $this->mockEntity('XF:User');
		
		// execute some test code which logs a moderator action, for example:
		$this->app->logger()->logModeratorAction('user', $user, 'rejected', ['reason' => 'foo']);

		// assert our action was logged as expected		
		$this->assertActionLogged('user');
		
		// alternatively, assert our action was logged with specific attributes - return a truth test
		$this->assertActionLogged('user', function ($log) {
			$data = $log->getActions();
			return $data['action'] == 'rejected' && $data['params']['reason'] == 'foo';
		});
	}
}	
```

Refer to the `Hampel\Testing\Concerns\InteractsWithLogger` trait for full details of available moderator log validation 
functions.

### fakesMail
Allow us to assert that emails were (or were not) sent as a result of executing our test code, without
side-effects (ie no emails actually get sent). Mail queueing is disabled, so all mail goes via the test transport.

Mail sent with `queue()` is captured as well as mail sent with `send()`. It works whether or not the
mailer has already been built.

##### What the assertions receive

Captured mail is a `Symfony\Component\Mime\Email`, and `getTo()` returns an array of
`Symfony\Component\Mime\Address` objects:

```php
$to = $mail->getTo();

$to[0]->getAddress() == 'foo@example.com';   // not array_key_exists('foo@example.com', $to)
$to[0]->getName()    == 'Foo';               // not $to['foo@example.com'] == 'Foo'
```

An assertion comparing against an `email => name` array never matches, and fails with "The expected
mail was not sent."

##### Parameters:

none

##### Assertions available:

* `assertMailSent`
* `assertMailSentTimes`
* `assertMailNotSent`
* `assertNoMailSent`

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class MailTest extends TestCase
{
	public function test_mail()
	{		
		// initialise the Mail fake system
		$this->fakesMail();
		
		$email = 'foo@example.com';
		
		// execute some test code which sends an email, for example:
		$this->app->mailer()
				  ->newMail()
				  ->setTo($email)
				  ->setTempate('foo_template')
				  ->send();
		
		// assert some mail was sent as expected		
		$this->assertMailSent();
		
		// alternatively, assert our mail was sent with specific attributes - return a truth test
		$this->assertMailSent(function ($mail) use ($email) {
			return $mail->getSubject() == "The subject from our mail template"
				   && in_array($email, array_map(
						  function ($address) { return $address->getAddress(); },
						  $mail->getTo()
					  ));
		});		
	}
}	
```

Refer to the `Hampel\Testing\Concerns\InteractsWithMail` trait for full details of available mail validation 
functions.

### setConfig
Set a value in the application config - the values from `config.php`. **These are not options**:
`setOption()` on a config key has no effect.

A config value is usually read once, when the container builds whatever uses it, so call
`setConfig()` before the code under test resolves anything. If the consuming container key may
already exist, discard it as well:

```php
$this->setConfig('enableMailQueue', false);
$this->app()->container()->decache('mailer');
```

`fakesMail()`, `swapFs()` and `mockFs()` already do this for the keys they change.

##### Parameters:

* `key` - the config key to set
* `value` - the value to set it to

Returns the config array as swapped in.

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class ConfigTest extends TestCase
{
	public function test_debug_mode()
	{
		$this->setConfig('debug', true);

		$this->assertTrue($this->app()->config('debug'));
	}
}
```

### setOptions / setOption
Allow us to set arbitrary options to be returned when the application requests an option key, with no side effects - 
options are reset after each individual test is run.

##### Parameters:

setOptions:
* `newOptions` - array of options key=>value pairs

setOption:
* `key`
* `value`

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class OptionTest extends TestCase
{
	public function test_option()
	{		
		// set a single option
		$this->setOption('boardTitle ', 'foo');
		
		// or set a number of options at the same time
		$this->setOptions(['boardTitle' => 'foo', 'boardDescription' => 'bar']);
	}
}	
```

### fakesRegistry
Disables database and cache updates for registry changes - all updates are written to memory only, so no side-effects
when writing to the registry.

##### Parameters:

* `$preLoadData` set to false to disable pre-loading of registry data (data will still be read from database when 
accessed) 

##### Example:  

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class RegistryTest extends TestCase
{
	public function test_registry()
	{		
		// initialise the Mail fake system
		$this->fakesRegistry();
		
		// execute some test code which interacts with the registry:
		...
	}
}	
```

### mockRequest
Mock the request - given there are no HTTP requests created from the console, this is useful if we need to simulate 
certain attributes on a request.

##### Parameters:

* `mock` - optional - mock closure to set expectations

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;
use XF\Http\Request;

class RequestTest extends TestCase
{
	public function test_request()
	{		
		// mock our Request object so we can control what actually goes in there
		$this->mock('request', Request::class, function ($mock) {
		   $mock->expects()->getIp(false)->once()->andReturns('10.0.0.1');
		});
		
		// execute some test code which causes the Request object to be queried, for example
		$ip = $this->app->request()->getIp();
		
		// validate we received the expected data in response
		$this->assertEquals('10.0.0.1', $ip);
	}
}	
```

### fakesSimpleCache
Allow us to assert that keys/value exist (or do not exist) in the SimpleCache as a result of executing our test code, 
without side-effects (ie no changes are actually made to the cache).

##### Parameters:

None

##### Assertions available:

* `assertSimpleCacheHas`
* `assertSimpleCacheHasNot`
* `assertSimpleCacheEqual`
* `assertSimpleCacheNotEqual`

##### Example: 

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class SimpleCacheTest extends TestCase
{
	public function test_simpleCache()
	{		
		// initialise the SimpleCache fake system
		$this->fakesSimpleCache();
		
		// retrieve the simpleCache from the app container
		$simpleCache = $this->app['simpleCache'];
		
		// do something which causes an update to the simpleCache, for example
		$simpleCache->setValue('MyAddon', 'foo', 'bar');
		
		// now check that the simpleCache contains the key that we expect
		$this->assertSimpleCacheHas('MyAddon', 'foo');
		
		// or check that the value is what we expect
		$this->assertSimpleCacheEqual('bar', 'MyAddon', 'foo');
	}
}	
```

### setTestTime
Allow us to set an arbitrary execution time for `\XF::$time`, with no side effects - time is reset after each 
individual test is run.

This is especially useful when dealing with time intervals based on the script execution time. It becomes even more 
useful when combined with the Carbon library, since time intervals become very easy to manipulate.

##### Parameters:

* `time` - timestamp to set XF time to

##### Example: 

```php
<?php namespace Tests\Unit;

use Carbon\Carbon;
use Tests\TestCase;

class TimeTest extends TestCase
{
	public function test_time()
	{		
		$time = time();
		
		// set our script execution time to a known value - 5 minutes into the future
		$this->setTestTime($time + (60*5));
		
		// now we can execute a test which relies on 5 minutes having passed based on some other criteria
		...
		
		// alternatively using Carbon
		$time = Carbon::now();
		$this->setTestTime($time);
		
		// do something which relies on a certain time having been passed, for example:
		$this->foo($time->copy()->subMinutes(5)->timestamp);
	}
}	
```

Refer to the `Hampel\Testing\Concerns\InteractsWithSimpleCache` trait for full details of available cache validation 
functions.
