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

**An id that matches no installed add-on is ignored, not refused.** The filter keeps the installed
add-ons whose ids are named, so an id naming nothing simply matches nothing - which is how to ask
for no add-ons at all, with a list holding one id you know is not there:

```php
protected $addonsToLoad = ['None/None'];
```

A misspelled id behaves the same way, so an add-on silently not loading is worth checking against
the ids in the control panel's add-on list.

Naming ids in `$addonsToLoad` filters three things:

* **Composer autoloading** - only the named add-ons' autoloaders are registered
* **class extensions** - other add-ons' class extensions are not applied
* **code event listeners** - other add-ons' listeners do not run, including `app_setup`

Nothing else is filtered: the forum's database, options, phrases and templates are the real ones.

**Content type handlers are the ones to know about, because they fail inside XenForo's own code.**
The handlers come from `xf_content_type_field` in the database, so every installed add-on's are
registered whatever `$addonsToLoad` says. Core then walks them, reaches a handler belonging to an
add-on this test did not load, and that handler calls a method the unapplied class extension was
going to add:

```text
Error: Call to undefined method XF\Entity\User::hasResourceCategoryPermission()
```

The message names neither isolation nor the add-on it came from. Reaching it needs an operation that
iterates every content type - a search with no type given is the usual one - so the remedy is the
test's: narrow the operation to the content types the add-on owns, or name in `$addonsToLoad` the
add-ons whose content types the test reaches.

**It cannot remove a class extension that has already been resolved in this process.** XenForo caches
each resolution and aliases an XFCP proxy for it, and a proxy cannot be undeclared - so once any test
has resolved an extended class, an application booted later with a narrower `$addonsToLoad` still
gets the extended one, and with it any column the extension added to an entity structure. A test
asserting that an extension is *absent* therefore passes on its own and exercises the extension in a
full run. `requireClassNotExtended()` is the guard for such a test.

**It cannot add one either, which is the same cache from the other side - and that case is now
refused rather than silent.** Once a class has been resolved under one list, an application booted
later with a wider `$addonsToLoad` still gets the class resolved the first time: the extension is
registered, but the proxy it needs is a `class_alias()` PHP cannot declare twice. So a test class
naming more add-ons than its siblings gets those extensions only when it happens to run first.
Rather than let it exercise XenForo's own class with the extension's methods missing, `setUp()`
refuses, naming the class and the extension that will not reach it:

```text
A class extension the add-ons this suite loads register cannot be applied, because the class was
already resolved in this run under a list that did not have it:

    XF\Entity\User is not extended by XFRM\XF\Entity\User
```

Such a class passes on its own and is refused in a full run, which is the condition rather than a
defect in the test - so the message is read as *this suite needs one list*, not as *this test is
wrong*. Only widening is refused; narrowing is the case above, which a test can want.

**Which is why the list belongs to the suite rather than to a test class.** Name every add-on whose
class extensions or content types any of your tests reach, identically in every class - your own
`tests/TestCase.php` is the place for it - and neither of these is order-dependent any more. That
one list is also the remedy for the content type handlers above, so it answers both.

**Your own add-on's listeners do run**, including while the container builds something your test is
not otherwise interested in - so a mock standing in for one subsystem may still need expectations
for whatever your listeners ask of it.

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

**`html` and `emailHtml` wrap the output** in `<div class="bbWrapper">`, so the expected value has to
include it. `simpleHtml` does not wrap, `bbCodeClean` returns BB code, and `editorHtml` renders
different markup again - `[b]x[/b]` becomes `<p><strong>x</strong></p>`.

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class BbCodeTest extends TestCase
{
	public function test_bbcode_bold()
	{
		$bbCode = '[b]this should be bold[/b]';

		$expectedHtml = '<div class="bbWrapper"><b>this should be bold</b></div>';
		$this->assertBbCode($expectedHtml, $bbCode, 'html');

		// simpleHtml does not wrap
		$this->assertBbCode('<b>this should be bold</b>', $bbCode, 'simpleHtml');
	}
}	
```

**It compares exactly.** A tag whose output goes through a template - an image with a lightbox, say
- is not worth matching character for character. Use `renderBbCode()` below and assert on what
matters.

### setAppClassType
Put one of XenForo's own application classes in `\XF::app()`, as a request of that type would have.

`dispatch()` and `callAction()` call it, so a test usually does not. Call it before
`renderTemplate()` or `renderMacro()` when what you render depends on the application type.

##### Parameters:

* `type` - `'public'`, `'admin'` or `'api'`

##### Example:

```php
$this->setAppClassType('public');

$html = $this->renderTemplate('public:my_addon_block');
```

The stand-in shares this application's container, so container keys, fakes and swaps are the same
objects, and the type's setup event - `app_pub_setup`, `app_admin_setup` or `app_api_setup` - fires
once per test. `app()` still returns the application the framework booted.

### renderJsonReply
Render a reply through the JSON renderer and return the decoded document, for an endpoint whose view
shapes its own response in `renderJson()`.

##### Parameters:

* `reply` - a view reply from `dispatch()` or `callAction()`

##### Example:

```php
$reply = $this->dispatch('my-addon/callback', 'public', ['key' => '...'], [], 'POST');

$document = $this->renderJsonReply($reply);

$this->assertSame('ok', $document['result']);
```

`renderRawReply()` cannot stand in: the raw renderer asks the view for `renderRaw()`, which a JSON
view does not have, so the body comes back empty.

**Dispatch first, or call `setAppClassType()`.** View classes resolve through the application type,
which is `Cli` by default, so a view that does not exist for that type resolves to `XF\Mvc\View` -
and XenForo then answers with a document of its own, well formed and carrying nothing of yours.
This refuses both cases rather than returning it. The document is not recognisable by its shape: its
content is empty when the reply names no template, which is the usual case for a view answering
JSON, and the whole rendered template when it does.

### renderBbCode
Render BB code and return the HTML, for asserting on part of it. The BB code twin of
`renderTemplate()`.

##### Parameters:

* `bbCode`
* `type` - optional - `html` (the default) and the others listed above
* `context` - optional - `unitTest` by default
* `content` - optional - the content being rendered, typically an entity

##### Example:

```php
$html = $this->renderBbCode('[img]https://example.com/a.png[/img]');

$this->assertSee($html, 'js-lbImage');
$this->assertNoTemplateErrors();
```

Like the template helpers, it throws when a render produces nothing because it raised an error. BB
code that legitimately renders to nothing is returned as it is.

### actingAs / actingAsMember / actingAsGuest
Run a test as a given user, so code that reads `\XF::visitor()` or checks permissions behaves as
it would for that user. The visitor is restored automatically after each test, and so is one a
test set itself with `\XF::setVisitor()`.

Users are built in memory and are never written to the database.

**So an id the code under test stores against the visitor resolves to whatever real account holds
that id when a relation loads it back.** `actingAsMember()` defaults to `user_id` 1, which on most
forums is the owner: a service recording `created_by_user_id = \XF::visitor()->user_id` and a
template printing `$record.CreatedBy.username` renders the owner's name, not the built visitor's.
Asserting on the column passes; asserting on the rendered name fails - or, on a forum whose user 1
happens to match, passes for the wrong reason.

**The other half is reading.** Anything that fetches rows keyed by the visitor's id finds whatever
user 1 really has on that forum - so an add-on's own finder or repository, asked for the visitor's
records, answers with the owner's. A test asserting it found nothing fails on a forum where the
owner happens to have one, and a test asserting a count gets the owner's. Pass an id above the
forum's own users, as `buildVisitor()` does for permission combinations:

```php
$highest = $this->app()->db()->fetchOne('SELECT MAX(user_id) FROM xf_user');
$user = $this->actingAsMember(['user_id' => $highest + 1000]);
```

`createUserAccount()` is the other answer, and takes a real auto-increment id rather than one you
chose - see the caveats in its own section.

Where the page has to read the account back, create one with `createUserAccount()` and act as that:

```php
$user = $this->createUserAccount('alice');
$this->actingAs($user, ['forum' => ['view' => true]]);
```

**An empty `$permissions` grants nothing, and for a saved account that is not the same as denying
everything.** Permissions are only written when you pass some, so a user with a real
`permission_combination_id` - any account from `createUserAccount()`, or one already in the forum -
keeps whatever that forum grants it. A built visitor has a combination of its own that nothing has
granted, so there it makes no difference.

This matters for a test asserting a member is *refused* something: written as
`actingAs($user)` against a saved account, it answers from the forum's configuration rather than
from the test, and passes for the wrong reason wherever that member happens to lack the permission
anyway. Pass an explicit empty set to decide it in the test:

```php
$user = $this->createUserAccount();

$this->actingAs($user);
$this->setVisitorPermissions($user, []);   // now nothing is granted, whatever the forum says
```

That writes rows, so the test class needs `UsesDatabaseTransactions`.

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

**A test counting queries cannot see per-node permission loading for the ids it set here.**
XenForo's `cacheContentPermsByIds()` drops every id already in the permission cache and returns
before querying, and these are set in memory, so the ids a test seeded cost **no** queries while
ids it did not seed cost one. A query-count assertion over the seeded ids therefore cannot notice
code that stopped loading permissions - which is usually exactly the ids under test. Assert on the
permission decision rather than on the query count.

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

**These values go in as stored column data rather than through the entity's setters**, which is
the difference between this and `createUserAccount()`. A column XenForo keeps as a string built
from an array - `secondary_group_ids`, which is `LIST_COMMA` - is accepted as either the array or
the stored string, since an array is encoded to the stored form for you. Nothing verifies a value
here, so a column a setter would reject is set without complaint.

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

### actingAsAdministrator
Run the code under test as an administrator - the three lines an admin test opens with, in one.

##### Parameters:

* `adminPermissions` - optional - permission id => bool, as `hasAdminPermission()` reads them
* `adminValues` - optional - extra columns for the administrator record, eg
  `['is_super_admin' => true]`
* `userPermissions` - optional - ordinary permissions, as `setVisitorPermissions()` takes them, for
  code that reads those too

##### Example:

```php
$admin = $this->actingAsAdministrator(['notice' => true]);

$this->assertReplyIsView($this->dispatch('notices', 'admin'));
```

It is the long name because `actingAsAdmin` is one add-on suites have already written for
themselves, and a method on this class cannot be shadowed by an incompatible one without a fatal.

### createUserAccount
Create a member in the database that XenForo's own pages can display.

`createEntity('XF:User')` writes the `xf_user` row alone, so a page reading the profile, option,
privacy or authentication records fails on a null relation. This hydrates them through XenForo's own
user repository, as registration does.

The rows are real, so call it from a class using `UsesDatabaseTransactions`.

**The account gets a real permission combination**, so it holds whatever this forum grants that
user group until the test says otherwise - see `actingAs` above for why an assertion about a
member being refused something needs `setVisitorPermissions($user, [])` rather than an empty
argument.

**The account inherits this forum's `registrationDefaults` option.** XenForo's own option and
privacy entities read it while their defaults are set up, which covers eight columns on the option
record - `receive_admin_email`, the two watch states and five more - and five on the privacy
record. So an assertion about what a "normal" member has describes the forum under test rather
than the test. Set what the test needs afterwards:

```php
$user = $this->createUserAccount();
$user->Option->receive_admin_email = true;
$user->Option->save();
```

Or change the option with `setOption('registrationDefaults', $defaults)` before creating the
account, which covers every account the test then creates.

**Saving a user is the most contended write this package makes, and the transaction does not make
it reliable.** XenForo rewrites the user's group memberships, which locks an index gap, so two
tests creating users against one database can deadlock - reliably fine against a forum nothing else
writes to, intermittent against a shared development install, and the failure names
`xf_user_group_relation` rather than anything here. `UsesDatabaseTransactions` below has the
mechanism. Where a test needs a visitor rather than a row, `actingAsMember()` writes nothing at
all; where it needs an account that already exists, updating one takes no such lock.

##### Parameters:

* `username` - optional - a unique one is generated when not given
* `values` - optional - columns for the user, eg `['user_state' => 'moderated']`

##### Example:

```php
$user = $this->createUserAccount('probebob');

$html = $this->renderReply($this->dispatch("users/{$user->user_id}/edit", 'admin'));

$this->assertSee($html, 'probebob');
```

### createAdministratorFixture
Create an administrator in the database: a member, and the administrator record XenForo reads admin
permissions from.

`setVisitorAdminPermissions()` and `actingAsAdministrator()` grant the visitor alone, in memory.
Code that reads administrators from the database - a command listing who may do something, a page
of administrators - needs rows, and this is `createUserAccount()` plus `createEntity('XF:Admin')`
as one call. The record is saved through XenForo's own entity, which marks the member `is_admin`
and writes the permission entries the control panel reads beside the cache.

The rows are real, so call it from a class using `UsesDatabaseTransactions`; the user save is the
contended write `createUserAccount()` describes.

**A permission id this forum does not define is refused**, naming it. The cache would answer true
for it while no control panel could grant it, so a test would pass on a permission that cannot
exist. The ids are the `admin_permission_id` values of `xf_admin_permission`.

##### Parameters:

* `permissions` - optional - permission id => bool, as `hasAdminPermission()` reads them; a plain
  list of ids is accepted too
* `adminValues` - optional - extra columns for the administrator record, eg
  `['is_super_admin' => true]`
* `userValues` - optional - columns for the user, eg `['username' => 'alice']`

##### Example:

```php
$granted = $this->createAdministratorFixture(['user' => true], [], ['username' => 'alice']);
$other = $this->createAdministratorFixture(['option' => true]);

[, $output] = $this->runConsoleCommand(new Config());

$this->assertStringContainsString('alice', $output);
```

The user is returned with its `Admin` relation loaded. It is not the visitor; `actingAs($user)`
makes it one.

### createNodeFixture
Create a node and its type data row, without rebuilding the forum's node tree.

```php
$forum = $this->createNodeFixture('Test forum');
$category = $this->createNodeFixture('Test category', 'Category');
$forum = $this->createNodeFixture('Tagged forum', 'Forum', [], ['min_tags' => 1]);
```

Returns the `XF:Node`. Its type row is on `$node->Data`, or fetch it by the node's id -
`$this->app()->em()->find('XF:Forum', $node->node_id)`.

**Saving an `XF:Node` the ordinary way is not safe on a shared forum.** XenForo's `TreeStructured`
behaviour schedules a nested-set rebuild and enqueues a `permissionRebuild` job on every insert,
and the rebuild writes **every** row in `xf_node` - so inside a test's transaction every node on
the forum is locked until the test ends. The values it writes are usually identical, which is why
comparing values suggests nothing is wrong: the cost is the write, not the change.

| a single node insert | queries | writes | `xf_node` UPDATEs |
|---|---|---|---|
| saved the ordinary way | 9 | 4 | one per row in the table |
| `createNodeFixture()` | 3 | 1 | 0 |

The helper turns that behaviour's `rebuildCache` option off, which suppresses both the rebuild and
the job, and supplies the nested-set columns itself. It numbers the node clear of the forum's own
tree, so a fixture can never be numbered into it.

**It creates a node at the root and refuses a parent.** A child has to be numbered inside its
parent, and `hasChildren()` is `(rgt - lft) > 1`, so a parent whose range is wider than its
children makes XenForo report that a childless node has children. Build a parent and child
together with `createNodeTreeFixture()`.

**A fixture node grants nothing.** `canView()` is false until the test grants the permission, as
for any built visitor - see `setVisitorContentPermissions()`.

**If the code under test saves the node again, the rebuild fires after all.** The behaviour
rebuilds whenever `breadcrumb_data` is empty, and empty is what XenForo itself stores for a node
at the root - so this is true of a real root node too, not just a fixture. Where a test saves a
node, set the option on that save as well:

```php
$node->getBehavior('XF:TreeStructured')->setOption('rebuildCache', false);
```

### createNodeTreeFixture
Create a tree of nodes in one call, numbered the way XenForo numbers one.

```php
$nodes = $this->createNodeTreeFixture([
    'Top category' => ['type' => 'Category', 'children' => [
        'Inner forum' => ['type' => 'Forum'],
        'Other forum' => ['type' => 'Forum', 'data' => ['min_tags' => 1]],
    ]],
]);

$thread = $this->createThreadFixture($nodes['Inner forum'], 'A thread');
```

Each key is a title and each value may carry `type` (default `Forum`), `values` for the node,
`data` for its type row, `children`, and `key`. Returns every node created, parents first.

**`key` is what a node is called in the array that comes back**, for a test whose own name for a
node is not the title a reader of the forum would see:

```php
$nodes = $this->createNodeTreeFixture([
    'Announcements and site news' => ['type' => 'Category', 'key' => 'parent', 'children' => [
        'Scheduled maintenance' => ['key' => 'child'],
    ]],
]);

$thread = $this->createThreadFixture($nodes['child'], 'A thread');
```

It defaults to the title, so a node naming none is keyed by its title and the two forms mix in one
tree. The key is the value that has to be unique rather than the title - two nodes may share a
title where their keys differ, as they may on a real forum - and a key used twice is refused, since
one of the two would be unreachable in that array.

**A tree has to be built in one call rather than a node at a time**, because a child's range sits
inside its parent's and the parent's range is not known until its children have been numbered. The
result is tight, so `hasChildren()` is true for a parent and false for a leaf, and
`WHERE lft > ? AND rgt < ?` returns exactly the descendants.

Each child carries its ancestors in `breadcrumb_data`, built by the helper from what XenForo
stores. A node at the root has an empty trail, which is what XenForo stores for one.

### createThreadFixture
Create a thread in a node, with its first post.

```php
$forum = $this->createNodeFixture('Test forum');
$thread = $this->createThreadFixture($forum, 'A thread', ['message' => 'The body']);

$this->assertSame('The body', $thread->FirstPost->message);
```

Takes the node or its id. `message` is used for the first post; anything else is set on the thread.
The author defaults to the current visitor, or to user 1 where a test has not set one.

**An explicit `user_id` is honoured as given, including 0.** A guest thread needs a `username` as
well, since there is no user row to read a name from:

```php
$thread = $this->createThreadFixture($forum, 'By a guest', [
    'user_id' => 0,
    'username' => 'A passer by',
]);
```

The thread and its first post always carry the same author, so a count or a permission check that
reads one agrees with the other. A `user_id` with no `xf_user` row and no `username` beside it is
refused, naming the lookup that found nothing - which is the case a visitor built by
`actingAsMember()` falls into, since the id it carries need match no row.

**A guest post adjusts no member's message count.** XenForo only adjusts the count of a post that
has a user, so attributing a guest's post to a member would both misreport the author and write to
that member's row inside the test's transaction.

**The post is not optional.** A thread whose `first_post_id` is 0 has a null `FirstPost`, so
anything reading through it fails on a fixture that looks complete - so the helper creates the post
and points the thread's first and last post columns at it.

**Saving a thread writes more than the thread.** XenForo updates the forum's counters and writes to
`xf_news_feed` and `xf_content_activity_log`, so a thread fixture belongs under
`UsesDatabaseTransactions` like any other content.

### dispatch
Dispatch a route the way XenForo does, and return the reply its controller produced.

The controller's `preDispatch()` runs, so its access checks are tested - which a controller called
directly does not do.

##### Parameters:

* `routePath` - the route as it appears after the `?` in a URL, eg `help/terms`
* `type` - optional - `public` (the default), `admin` or `api`
* `input` - optional - the `GET` parameters the route reads, as `$_GET` would carry them
* `server` - optional - request server values, as `$_SERVER` would carry them, merged over the
  defaults. Not `REQUEST_METHOD`: the method comes from the `method` argument, and passing one here
  is refused rather than ignored

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

**The application is one of XenForo's own for the duration.** `dispatch()` puts an `XF\Pub\App`,
`XF\Admin\App` or `XF\Api\App` in `\XF::app()`, sharing this application's container, and fires
that type's setup event - `app_pub_setup` and its siblings - once per test. Without it a container
key an add-on registers in that event never exists, and a listener gated on
`$app instanceof \XF\Pub\App` never runs its body. Call `setAppClassType('public')` yourself
before `renderTemplate()` or `renderMacro()` when the markup depends on it. `app()` is unchanged.

**Most public routes need `general.view`**, which a built visitor does not have, so a public
dispatch returns a 403 until the test grants it:

```php
$member = $this->actingAsMember();
$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

$reply = $this->dispatch('members');
```

**A whole family of core routes is exempt, so a dispatch there succeeds without the grant.**
`XF\Pub\Controller\AbstractController` is where the check lives, and eleven core controllers
override it with an empty method - account, account confirmation, email stop, error, help, login,
logout, lost password, register, security lock and service worker - while the misc controller
exempts itself per action. Those are also the routes a test is most likely to reach for, so a
dispatch to `account/preferences` that works without `general.view` is not evidence that the grant
is unnecessary anywhere else.

**Api routes need it too.** `XF\Api\Controller\AbstractController` checks `general.view` ahead
of the controller's own `preDispatchController()`, with only the error controller exempt, and its
refusal is the same reply an add-on's gate gives - see `actingAsApiKey()`.

**`dispatch()` sends a `GET` unless you ask for something else.** Pass the method as the fifth
argument. XenForo asserts a CSRF token in `preDispatch()` for anything that is not a `GET`, so a
non-GET dispatch carries a token the request has a matching cookie for - which means the action
behind the check is reachable, including on a controller that does not opt out:

```php
$reply = $this->dispatch('my-addon/callback', 'public', ['key' => '...'], [], 'POST');
```

That is the one case where the opt-out is the thing worth testing: the same endpoint's
`assertViewingPermissions()` and `assertBoardActive()` overrides also live in `preDispatch()`, and
`callAction()` skips all three.

**The method comes from this argument and nowhere else.** `REQUEST_METHOD` in `$server` is refused,
by `dispatch()`, `dispatchWithoutCsrfToken()` and `callAction()` alike, because it could not work:
the CSRF token is minted according to the `method` argument, so a `POST` smuggled through `$server`
would be sent without one and answered with a 400.

**A non-`GET` admin dispatch writes a real `xf_admin_log` row.** XenForo's own admin controller
logs the request from `postDispatchType()`, for anything that is not a `GET` or a `HEAD` and does
not answer with a `Reroute`, so it happens for every admin controller rather than for particular
actions. The row carries the route path and **the input the test sent**, of which only `_xfToken`
and keys containing `password` are masked. A class extension on the admin log repository runs with
it.

Use `UsesDatabaseTransactions` for a class that dispatches admin writes - measured, the row is
rolled back with everything else. A visitor whose `user_id` is 0 writes nothing, but
`actingAsAdministrator()` and `actingAsMember()` default to 1.

**The response code says which layer refused.** Dispatch such an endpoint with a `GET` and its own
`assertPostOnly()` answers 405 - which is itself evidence that `preDispatch()` let the request
through. Remove one of the overrides and the same dispatch returns 403 from that check instead, so
one test per override isolates it.

**A route that requires registration refuses a guest with a view, not an error.**
`assertRegistrationRequired()` returns the `login` template with a 403, so `assertReplyIsError()`
fails on it. `assertReplyIsLoginRequired()` is the assertion for it:

```php
$this->assertReplyIsLoginRequired($this->dispatch('account'));
```

**Parameters go in `input`, not in the route path.** `dispatch('my-addon/user?user_id=1')` is a
404:

```php
$reply = $this->dispatch('my-addon/user', 'api', ['user_id' => 1]);
```

**To assert that the CSRF check refuses, use `dispatchWithoutCsrfToken()`.** It sends no token at
all, which XenForo answers with a 400 - so a test can still tell a guard that fires from one that
has been deleted. To send an invalid token instead of none, pass your own `_xfToken` in the input.

An action opening with `assertPostOnly()` needs the method, so `dispatch($path, $type, $input, [],
'POST')` reaches it. `callAction()` remains the way to call an action without `preDispatch()` at
all.

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

**A reply's contents follow the forum's own configuration, not just the test's fixtures.** A thread
list coming back short is the likeliest instance: a forum carries `list_date_limit_days`, and where
it is set `XF\Pub\Controller\ForumController` limits the finder to threads whose `last_post_date`
falls inside that many days - so a fixture thread with an older last post is filtered out by the
forum rather than by anything the test did. The limit lifts per request:

```php
$reply = $this->dispatch('forums/announcements.1/', 'public', ['no_date_limit' => 1]);
```

It is a field on each forum rather than a board option, so the same test can pass against one forum
on an install and fail against another.

Note the trailing slash. A public controller compares the url it would build against the url the
request carries, and a forum's canonical route has one - so `dispatch('forums/announcements.1')`
is answered with a redirect to `forums/announcements.1/` rather than with the page.
`followRedirect()` is the other way to reach it, and it works whatever the canonical form turns
out to be.

**A `GET` answered with a redirect has two unrelated causes, and the redirect's type tells them
apart.** A controller correcting the address answers with a **permanent** redirect, from
`assertCanonicalUrl()`; a controller that stored something and is pointing at it answers with a
**temporary** one. Only the first can be avoided by dispatching a different address:

| type | what happened | what to do |
|---|---|---|
| `permanent` | the address is not the canonical one | dispatch the canonical one, or follow |
| `temporary` | the controller stored a result and points at it | follow it - no address avoids it |

**The canonical comparison is against a link the controller builds, so it follows whatever that
link carries.** A node's canonical address carries its title as well as its id, which matters
because an address built from a node's id is never the canonical one: a node created in a test and
dispatched as `forums/<id>/` is answered with a permanent redirect to `forums/<title>.<id>/`, and so
is `categories/<id>/` on a forum where *Categories have their own page* is on.

```php
$node = $this->createNodeFixture('My test forum');

$member = $this->actingAsMember([], ['general' => ['view' => true]]);
$this->setVisitorContentPermissions($member, 'node', $node->node_id, ['view' => true]);

$reply = $this->dispatch('forums/' . $node->node_id . '/');
$this->assertReplyIsRedirect($reply, null, 'permanent');

$this->assertReplyTemplate($this->followRedirect($reply), 'forum_view');
```

`$this->app()->router('public')->buildLink('forums', $node)` is the canonical address, so building
the link rather than the string reaches the page in one dispatch.

The correction does not depend on the *Use full friendly URLs* option - a node's title is part of
the route path in either url form - and it reaches only an html `GET` or `HEAD`, since
`assertCanonicalUrl()` returns before comparing anything otherwise. So an api dispatch, or any
non-`GET`, is never corrected however wrong the address.

### dispatchWithoutCsrfToken
Dispatch a route sending no CSRF token, for asserting that XenForo's check refuses. Takes the same
arguments as `dispatch()`, with the method defaulting to `POST`, since a `GET` is not checked.

Without it there would be no way to tell a CSRF guard that fires from one that has been deleted:
every non-GET `dispatch()` carries a valid token.

##### Example:

```php
$member = $this->actingAsMember();
$this->setVisitorPermissions($member, ['general' => ['view' => true]]);

// with a token, the controller runs
$this->assertReplyIsView($this->dispatch('help/terms', 'public', [], [], 'POST'));

// without one, XenForo answers 400
$this->assertReplyIsError($this->dispatchWithoutCsrfToken('help/terms', 'public'), 400);
```

To send an *invalid* token rather than none, pass your own `_xfToken` in the input - a token the test
supplies is never replaced.

### callAction
Call a controller action directly with a `POST` request, and return the reply it produced.

It skips `preDispatch()` - so neither the CSRF check nor the controller's permission check runs.
That, rather than the request method, is the difference from `dispatch()`, which can send a `POST`
of its own. Reach for this where running the action without its guards is the point:

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

* `controller` - `'XF:Notice'` or a full class name. Name the class your add-on **extends**,
  never your extension of it: XenForo resolves it to the most derived class, which is yours, and
  an extension named directly is refused. The proxy an extension inherits from is declared while
  XenForo resolves the class being extended, so the extension's own name loads only after
  something else in the run has resolved it
* `action` - the controller's own action, not a route: `'save'` calls `actionSave()`, and
  `'archive-users/run'` calls `actionArchiveUsersRun()`. Nothing about routing applies, so a route's
  `action_prefix` is not added for you - an action reached at `links/save` under the prefix `link`
  is called here as `'link-save'`. An action the controller does not have is refused, and the
  message names the method it looked for
* `type` - optional - `'public'` (default), `'admin'` or `'api'`
* `input` - optional - the request input, as `$_POST` would carry it
* `params` - optional - route parameters, eg `['notice_id' => 3]`
* `method` - optional - `'POST'` (default) or `'GET'`
* `server` - optional - request server values, as for `dispatch()`
* `files` - optional - uploaded files, keyed as the form names them. Build each one with
  `uploadedFile()` or `uploadedFileFromPath()`

##### Uploading a file:

An action reading `$this->request->getFile('...')` needs one, and without it only its no-file
branch can be tested.

```php
$reply = $this->callAction('MyVendor\MyAddon:Thing', 'save', 'admin', [], [], 'POST', [], [
    'upload' => $this->uploadedFile('id,name' . "\n" . '1,Probe', 'import.csv'),
]);
```

`uploadedFile($contents, $name, $type)` writes a real temporary file, because XenForo's `Upload`
needs a readable one, and removes it when the test finishes. `uploadedFileFromPath($path, $name,
$type)` takes a fixture from disk and copies it, so the code under test cannot move or delete your
fixture. The mime type is guessed from the contents unless you pass one.

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
`preDispatch()`. Work queued with `\XF::runOnce()` has run by the time the reply is returned, as
`runDeferred()` runs it.

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

### assertReplyParam / assertReplyParamSame / replyParam
Assert that a view reply passed a given parameter to its template, assert what that parameter holds,
and read it.

##### Parameters:

* `reply` - as returned by `dispatch()`
* `key` - the parameter name
* `expected` - **`assertReplyParamSame` only** - the value the parameter must hold, compared
  strictly, as `assertSame()` does
* `message` - optional - a failure message, as every reply assertion takes

**`assertReplyParam()` has no expected-value argument, and `assertReplyParamSame()` is the one that
compares.** Three arguments to `assertReplyParam()` is a failure message. A **non-string** there is
refused, since nothing would compare it and the assertion would pass whatever the reply held - but a
**string cannot be told apart from a message and is taken as one**, so
`assertReplyParam($reply, 'subject', 'October news')` asserts no more than that the key exists.
Compare a value with `assertReplyParamSame()`, whose four argument positions make the two roles
unmistakable.

##### Example:

```php
$reply = $this->dispatch('options', 'admin');

// the parameter is there
$this->assertReplyParam($reply, 'groups');

// and it holds what it should
$this->assertReplyParamSame($reply, 'canAdd', true);

// or read it and assert however you like
$this->assertNotEmpty($this->replyParam($reply, 'groups'));
```

The page title is not a reply parameter - it is set by the template, so `pageParam()` reads it.

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

### assertReplyIsRedirect / assertReplyIsError / assertReplyIsMessage / assertReplyIsLoginRequired
Assert that a reply is a redirect, an error, a plain message, or the refusal of a guest from a route
that requires registration.

A route that does not exist returns an error with a 404, and a route whose permission check refuses
the visitor returns an error with a 403. A route that requires registration refuses a guest with a
**view**: the `login` template, the `XF:Error\RegistrationRequired` view class and a 403.
`assertReplyIsLoginRequired()` asserts all three, since the login page itself renders the same
template at a 200.

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

// a guest at a route that calls assertRegistrationRequired()
$this->assertReplyIsLoginRequired($this->dispatch('account'));
```

**Assert the error text when more than one guard can refuse with the same code.** A test asserting
only a 403 passes whichever guard refused. `replyErrors($reply)` returns the messages as plain text.

**Never `var_export()` or `print_r()` a reply, a phrase, or `$reply->getErrors()`.** An error
reply's errors are `XF\Phrase` objects; a phrase holds the language, the language holds the
database adapter, and the adapter holds its own configuration - so dumping one prints **the
database password and user in plain text**, into the test output and from there into a build log.
One phrase is enough, and `print_r()` reaches it too. XenForo does not redact the adapter.

Everything this framework hands you is already text, so the safe routes are the ordinary ones:

| to see | use |
|---|---|
| what the reply was, in one line | `describeReply($reply)` |
| the error messages | `replyErrors($reply)` |
| the rendered page | `renderReply($reply)` and `textOf()` |

The assertions compare text for the same reason, so their own failures are safe to paste. If you
write your own assertion over errors, compare the strings `replyErrors()` gives you rather than the
objects behind them - a PHPUnit failure prints the values it compared.

**Assert a redirect's `type`, not its code.** `getResponseCode()` is `200` for every redirect reply;
the renderer later sends a 301 for permanent and a 303 for temporary.

**A link a controller builds comes from the dispatched type's router**, so the url of an admin
redirect is an `admin.php` one and can be asserted as it stands. `XF\Mvc\Controller::buildLink()`
asks for no type, and `XF\App` hard-wires the untyped router to the public one - a real
`XF\Admin\App` overrides that, and so does `dispatch()`.

**Build the expected url rather than writing it out, and match it as a substring.** The `url`
argument compares exactly, and a redirect's url is often more than the route: XenForo canonicalises
some of them to an absolute url, a route may carry a trailing slash, and a controller may append a
fragment such as `#__42` to scroll to what it just saved.

```php
$this->assertReplyIsRedirect($reply);
$this->assertStringContainsString(
    $this->app()->router('admin')->buildLink('notices'),
    $reply->getUrl()
);
```

Writing the url out is worse than verbose - it depends on the forum. With the *Use full friendly
URLs* option off a public link is `/index.php?help/terms` and an api one `/index.php?api/users/`;
with it on they are `/help/terms` and `/api/users/`, so a literal passes on one forum and fails on
another. Admin links are unaffected, since that formatter does not read the option.

**The option needs no particular setting for a suite to work.** `dispatch()` builds its request in
whichever form the forum is configured for, because a public controller compares the url it builds
against the url the request carries - so a request in the other form would be answered with a
redirect to its own canonical url instead of with the page. What a test must not do is write a url
out, which is what the paragraph above is for.

Pass a `message` to identify the route in a test that dispatches several:

```php
foreach ($routes AS $route)
{
	$this->assertReplyIsError($this->dispatch($route, 'admin'), 403, null, $route);
}
```

### followRedirect
Dispatch the route a redirect reply points at, and return the reply that produced.

A controller answers a save, a toggle or a canonical url correction with a redirect, which says
nothing about what the next page shows - so a test that asserts only the redirect stops one step
short of what a user would see.

##### Parameters:

* `reply` - the redirect to follow. Anything else fails, naming what it got
* `server` - optional - request server values for the new request, as `dispatch()` takes them

##### Example:

```php
$admin = $this->actingAsMember(['is_admin' => true]);
$this->setVisitorAdminPermissions($admin, ['notice' => true]);

$reply = $this->callAction('XF:Notice', 'save', 'admin', [
    'title' => 'My new notice',
    'message' => 'Hello',
    'active' => 1,
    'notice_type' => 'block',
    'display_style' => 'primary',
]);
$this->assertReplyIsRedirect($reply);

$list = $this->followRedirect($reply);

$this->assertReplyTemplate($list, 'notice_list');
$this->assertSee($this->renderReply($list), 'My new notice');
```

**The visitor needs whatever the followed route requires**, which `callAction()` did not: it skips
`preDispatch()`, and this does not - the follow is an ordinary `dispatch()`. So a save tested
through `callAction()` with no permissions set up succeeds and then the follow is refused with a
403, which is the controller working rather than the helper failing.

**The route type comes from the url, not from the dispatch that produced the redirect.** That is
the work this does for you: the forum serves the control panel from its own script and the api
behind a prefix on the public one, and link building honours the *Use full friendly URLs* option
for the public and api routers but not the admin one. All six shapes are read:

| | friendly urls off | friendly urls on |
|---|---|---|
| public | `/index.php?notices&page=2` | `/notices?page=2` |
| admin | `/admin.php?notices/&page=2` | `/admin.php?notices/&page=2` |
| api | `/index.php?api/notices&page=2` | `/api/notices?page=2` |

Either form may carry the board's scheme and host in front, which is what a canonical url does, and
the query that is not the route becomes the input the followed dispatch sends.

**One hop, as a `GET`.** A redirect is followed with a GET whatever method produced it, and a reply
that is itself a redirect comes back as one - assert it, or follow it again.

A fragment is ignored. A controller commonly appends one - `#__1602`, to scroll to the row it just
saved - and it is no part of the route.

**Some pages work by storing a result and redirecting to it, so the follow is how the page is
reached rather than a correction.** What's New runs its search, saves an `xf_find_new` row and
answers with a temporary redirect to that row's own address; core search does the same with
`xf_search` and `search/<id>/`. The id does not exist until the search has run, so there is no
address a test could dispatch instead:

```php
$this->actingAsMember([], ['general' => ['view' => true]]);

$reply = $this->dispatch('whats-new/posts/', 'public', ['unread' => 1]);
$this->assertReplyIsRedirect($reply, null, 'temporary');

$this->assertReplyTemplate($this->followRedirect($reply), 'whats_new_posts');
```

Both write a row, so a class dispatching them wants `UsesDatabaseTransactions`. Neither always
redirects: What's New answers with the page directly when its search found nothing and no filters
were given, and a search with no results answers with a message.

A redirect pointing at another host is refused: there is no route to dispatch, so assert the url
with `assertReplyIsRedirect()` instead.

### actingAsApiKey
Run api dispatches as a given api key.

##### Parameters:

* `values` - optional - columns for the key

##### Example:

```php
$this->actingAsMember([], ['general' => ['view' => true]]);
$this->actingAsApiKey();                              // a super-user key with all scopes
$this->actingAsApiKey(['is_super_user' => false]);    // one that is not
$this->actingAsApiKey([                               // a user key holding one scope
    'is_super_user' => false,
    'allow_all_scopes' => false,
    'scopes' => ['node:read'],
]);
```

`is_super_user` sets the key type that `assertSuperUserKey()` checks, and `allow_all_scopes`
grants every scope.

**The default is the most powerful key there is**, a super user holding every scope, so a guard
that refuses super-user keys, or a scope check, is never reached by it. A test of either passes
the fields explicitly, as the last call above does; `scopes` takes the scope ids as a list.

**An api dispatch needs `general.view`, and core's refusal reads as an add-on's own.** Core checks
it before the controller's `preDispatchController()` runs, and the 403 carries the same
*do not have permission* sentence a gate's `noPermission()` gives, so a test that a gate refuses
passes on core's refusal and keeps passing with the gate deleted. Grant `general.view` first, and
pair the refusal with the same dispatch succeeding once the gate is satisfied. A key with no scope
is refused differently, naming the scope, so that one cannot be mistaken for the other.

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

**Neither are phrases.** A render resolves a phrase from the database, so editing
`_output/phrases/` changes nothing and a test asserting the new wording passes against the old. To
check that a phrase assertion fails when the phrase is wrong, change it through its `XF:Phrase`
entity - `language_id` 0 for the master language - and do it **before** the render under test: the
language keeps each phrase it has resolved, so changing one after a render in the same test does not
change what a second render produces.

##### Parameters:

* `template` - `type:title`, eg `public:thread_view`. The types are `public`, `admin` and `email`
* `params` - optional - the parameters the template reads

`renderReply($reply)` takes a reply from `dispatch()` instead, and renders the template it named
with the parameters it passed. Call it in the same test that dispatched, since the template type
comes from the dispatch.

**A reply whose template name already carries a type is rendered as named.** XenForo's standard
delete plugin answers with `public:delete_confirm` for an admin controller as well as a public one,
because the control panel reuses that template, so the dispatch type is not prepended to a name
that has one.

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

**This renders the template, not the page.** The page's furniture - navigation, header, footer,
the staff bar - is itself a template, `public:PAGE_CONTAINER`, and `renderTemplate()` renders it
given the content and the name of the template it wraps:

```php
$this->actingAsMember(['is_moderator' => true], ['general' => ['view' => true]]);
$this->app()->session()->set('unapprovedCounts', ['total' => 3]);

$page = $this->renderTemplate('public:PAGE_CONTAINER', [
	'content' => 'the content',
	'template' => 'thread_view',
]);

$this->assertSee($page, 'the content');
$this->assertSee($page, 'p-staffBar');
```

What the public app adds for a request - `pageSection`, `controller`, `action`, `classType`,
`containerKey`, `contentKey`, `view` - is absent unless passed, so a block reading one of them does
not render. **`$xf.session` is the container's session**: a value set on it before the render, as
above, is what a template reads from it. `pageParam()` reads the values the template set for the
page, such as its title.

**Markup a template sets through `<xf:title>`, `<xf:h1>`, `<xf:description>` or `<xf:pageaction>` is
not in the returned HTML**, and a template modification whose find sits inside one of those tags
goes with it - core's `<!--[XF:actions_menu:bottom]-->` in `admin:user_edit` is inside
`<xf:pageaction>`, so a link added there is absent from the string while the page shows it. Those
tags compile to a write into the templater's page params rather than to output. Read them with
`pageParam()` before the next render.

**`<xf:sidenav>` and `<xf:sidebar>` are absent the same way, and are not page params either.**
They compile to a write into the templater of their own, so what they produce is in neither the
returned HTML nor `pageParam()`. The account pages build their whole sidebar with `<xf:sidenav>`,
so an add-on adding a link there has nothing in the rendered string to assert on. Read them with
`sideNavHtml()` and `sidebarHtml()`.

The direction that fails loudly is the safe one: `assertSee()` reports a miss. The one to watch is
`assertDontSee()`, which passes against a page that does show the value. Assert on the page param
itself:

```php
$html = $this->renderReply($reply);
$action = $this->pageParam('pageAction');

$this->assertSee($action, 'Convert to API user');
$this->assertDontSee($action, 'Delete for ever');
```

`assertNoUnresolvedPhrases()` needs no such handling: it reads these four parameters itself, so the
call that is given the HTML covers them too.

**A render on a development forum shared with other add-ons raises other add-ons' errors, and
writes them to the forum's `xf_error_log`.** Template modifications belong to the install rather
than to `$addonsToLoad`, so a core template carries every installed add-on's, and a sibling's
modification reading a column its own entity extension declares warns on a page that is faultless
in a browser. Call `fakesErrors()` before the render to keep the rows out of the forum - they stay
available to the assertions - and see `assertNoTemplateErrors()` for which of those errors your
add-on can honestly assert on.

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

### renderWidgetHtml
Render one widget and return its HTML.

A widget that decides not to show itself renders an empty string, and that is preserved - it is
how a widget says "not applicable to this visitor", and several core widgets do it. The three
other ways a widget render comes back empty are faults and are refused instead: a renderer
carrying no template name, a template that does not exist, and a template that errored. So
`assertSame('', ...)` on the result means the widget declined, and nothing else.

Those three refusals can only reach a render where the widget **named** a template. A widget that
declines returns before it calls `renderer()`, so there is no template to check and a decline
test on its own cannot notice that the widget's template has gone - render the showing case
beside it, as the second example below does.

##### Parameters:

* `identifier` - a widget definition class, or the key of a widget the forum has configured
* `options` - optional - the widget's options, plus `_title` and `context` if wanted

The two identifier forms are looked up in different places and differ in one way that matters:

| given | found by | stored options |
|---|---|---|
| a definition class | matching the installed definitions | **not applied** |
| a configured widget's key | the widgets the forum configured | applied, `options` merged over |

Given a definition class, the options you pass are the widget's whole configuration - nothing it
was set up with anywhere on the forum applies.

The short `Vendor\MyAddon:MyWidget` form is refused. A widget identifier is never expanded, so
the definition class is passed in full; a configured widget's key is alphanumeric.

`_title` in the options overrides the widget's title, and `context` is passed to the widget as its
context parameters rather than as an option. `_title` applies to the definition-class form.

##### Example:

```php
$html = $this->renderWidgetHtml(\Vendor\MyAddon\Widget\MyWidget::class, [
    'limit' => 5,
    '_title' => 'My widget',
]);

$this->assertSee($html, 'My widget');
$this->assertNoUnresolvedPhrases($html, 'myaddon_');
```

A widget that shows nothing to a visitor without the permission, with the positive assertion
beside it so the absence is not passing on a broken subject:

```php
$this->actingAsMember([], ['myaddon' => ['view' => true]]);
$this->assertNotSame('', $this->renderWidgetHtml(MyWidget::class));

$this->actingAsMember();
$this->assertSame('', $this->renderWidgetHtml(MyWidget::class));
```

The style a render needs is installed for you. The application type is not: call
`setAppClassType('public')` first where what the widget renders depends on it. A widget template is
always a `public:` one whatever the application type, because XenForo prefixes the type itself.

There is no public way to read a definition's default options - XenForo keeps them on the
definition class - so an option a test does not pass takes whatever default that class applies.

On a forum shared with other add-ons the render can carry a **sibling's** template errors, because
template modifications compile into the install's shared template cache whatever `$addonsToLoad`
says. Only an empty render is refused; pair this with `assertNoTemplateErrors()` and the prefix of
the templates you own for the strict form.

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

### globalTemplateData
The `xf` template parameter as a render would see it - `$xf.options`, `$xf.visitor`, `$xf.style`
and the rest.

**Reach for it to assert on what a `templater_global_data` listener of yours contributed.** XenForo
fires that event from inside `XF\App::getGlobalTemplateData()`, so this is what proves the listener
is registered with the class, method and signature XenForo will actually call - which calling the
listener's own method cannot.

##### Parameters:

* `reply` - optional - a reply, as XenForo passes one while rendering a page. It adds `reply` to
  the data, carrying the `controller` short name, `action`, `section`, `containerKey`,
  `contentKey`, and `view` and `template` for a view. Without one there is no `reply` key at all

##### Example:

```php
$this->setAppClassType('public');

$data = $this->globalTemplateData();

$this->assertSame('on', $data['myaddon_banner'], 'the listener should have added this');
```

**Call this rather than `$this->app()->getGlobalTemplateData()`.** That method reads the style off
the templater, and only a real request otherwise sets one - so called directly it answers
`style => null`, which is the state a template reading `$xf.style` logs an error for. This installs
the style first, exactly as a render does, which means it also leaves the style in place for a
render that follows.

Pair it with `setAppClassType()` where the listener is gated on the application type: the event
fires with whichever application built the data, so a listener checking
`$app instanceof \XF\Pub\App` needs one of those in place.

### assertNoTemplateErrors
Assert that no template this test rendered raised an error. That covers a BB code render too, since
a tag rendering through a template goes to the same templater.

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

**On a development forum shared with other add-ons, the blanket form is about the forum rather than
about your add-on.** `$addonsToLoad` loads no sibling's class extensions, but template modifications
belong to the install: they are compiled into the shared template cache and apply to every render.
So a sibling's modification reading a column its own entity extension declares raises a warning on
every render in your suite, for a page that is faultless in a browser.

**Pass the prefix of the templates you own** to assert on yours alone:

```php
$this->assertNoTemplateErrors('myaddon_');

// or several, where your templates do not share one prefix
$this->assertNoTemplateErrors(['myaddon_', 'legacy_name_']);
```

Filter by the names your add-on owns rather than by the siblings to exclude: you know the first list,
and it does not change when somebody installs something else.

A string filter matches the template *name*, not the whole error: the row holds the message too,
and an add-on's name can appear in somebody else's error text.

**An add-on that owns no templates needs a closure instead.** Where your only markup is a template
modification of a core template, the errors you are answerable for are raised while rendering a
template somebody else owns - and so are a sibling's, so no name tells them apart. Pass a closure
and it is handed each error:

```php
$html = $this->renderTemplate('public:account_preferences');

$this->assertSee($html, 'Hide signatures');
$this->assertNoTemplateErrors(function (array $error)
{
	return strpos($error['error'], 'myaddon_') !== false;
});
```

Returning true keeps the error, so the assertion fails on it. The keys are XenForo's own:

| key | |
|---|---|
| `template` | the template that was rendering, as `type:title` |
| `error` | the message, led by the PHP error constant in brackets |
| `type` | that constant as an int |
| `file` | the compiled template file |
| `line` | the line in it |

**An add-on that is the only one modifying a core template can filter by that template's name.**
The errors a modification raises are recorded against the template that was rendering, so where
your add-on owns no templates, modifies one core template, and no other add-on modifies it, every
error under that name is yours and `assertNoTemplateErrors('account_upgrades')` says so. It is the
commonest shape for a small add-on, and it is also the shape a closure on your prefix cannot serve:
a block that only reads option values carries no name of yours for the closure to match. It holds
until a sibling modifies the same template, which is when the closure is the one that still works.

**A string is always a template name, never called.** `header`, `compact` and `link` are PHP
functions as well as plausible template names, so the form decides this rather than whether the
value happens to be callable - pass a closure to filter by anything else. Anything that is neither
a string, a list of strings nor a closure is refused.

A failure message of your own goes after the filter, and is prefixed to the failure.

**Your own modification's errors show up in other add-ons' suites rather than in yours.** Under
isolation your entity extension resolves, so a modification of yours reading a column it declares
renders cleanly in your own tests - while in a suite that does not load your add-on, the same
modification raises on the same page. Measured on one core page: a bare render answers one error
with the add-on isolated and two with `['None/None']`, the extra one being the isolated add-on's
own. So a clean render in your suite is not evidence that your modification is clean in somebody
else's, and the errors you see are usually not the ones you are answerable for.

**What an add-on can honestly claim on a shared forum is that none of the logged messages is its
own**, which is weaker than asserting there were none and is the strongest thing that is true.
Asserting zero errors fails your suite for a sibling's defect, on this forum and not on another:

```php
private function assertNoErrorsFromThisAddOn()
{
	$ours = [];

	foreach ($this->getLoggedMessages() AS $message)
	{
		if (stripos($message, 'myaddon') !== false)
		{
			$ours[] = $message;
		}
	}

	$this->assertSame([], $ours);
}
```

Two things to know before relying on it. **One template error reads as two logged messages** -
`getLoggedMessages()` returns the errors and the exceptions, and a template error is recorded as
both. **The two differ in their text**, one carrying XenForo's `Template error:` prefix and the
other naming the entity, so deduplicating them does not collapse the pair: a reader who suspects
double counting and reaches for `array_unique()` gets two and concludes there were two errors.
Match on content, never on a count. And the filter only works where your add-on's prefix appears in
the message, which for a template error it does, because the getter or template name carries it; an
add-on whose errors would not name it needs something else.

### assertSee / assertDontSee / assertSeeInOrder
Assert on rendered output.

##### Parameters:

* `html` - as returned by `renderTemplate()` or `renderReply()`
* `value`, or `values` for `assertSeeInOrder`
* `escape` - optional, default `true`
* `message` - optional

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

A failure names the value searched for, the escaped form where escaping changed it, and the length
rendered, without printing the markup. `assertDontSee()` also gives the offset of the first match
and the text either side of it.

**Every rendering assertion takes an optional failure message last, and it is prefixed to what
the assertion found rather than replacing it** - so a reader gets the diagnosis and the value
and the size of the render together:

```php
$this->assertSee($html, 'Email me a digest', true, 'the preference block did not render');
$this->assertSeeText($html, "Bob's thread", "the thread list rendered without its titles");
```

**Which position it takes depends on whether that assertion has an `escape` flag**, so the two are
refused in each other's place rather than silently accepted:

| assertion | the message is |
|---|---|
| `assertSee`, `assertDontSee`, `assertSeeInOrder` | the fourth argument, after `escape` |
| `assertSeeText`, `assertDontSeeText` | the third, since they take no `escape` |
| `assertNoUnresolvedPhrases` | the third, after `prefix` |
| `assertNoTemplateErrors` | the second, after the template filter |
| `assertTemplateModificationApplied` | the second |
| `assertTemplateModificationAppliedTimes` | the third, after `times` |

A string where `escape` goes is refused, because a non-empty string is merely truthy and would be
the default - the assertion would behave exactly as it does without it and the message would be
gone. A bool where the message goes is refused for the same reason in reverse: the text assertions
strip the markup before searching, so there is nothing for an escaping flag to do and they do not
take one.

**An empty value is refused**, by all of these and by `assertSeeText()` and `assertDontSeeText()`.
An empty string is found at position 0 of anything, so `assertSee($html, '')` cannot fail and
`assertDontSee($html, '')` cannot pass - the assertion has stopped being about the output. The
usual cause is a value built from something that came back empty itself. `assertSeeInOrder()`
refuses an empty list for the same reason: a loop over no values makes no assertion.

### sideNavHtml / sidebarHtml
The markup a render contributed to the side navigation or the sidebar, as one string.

`<xf:sidenav>` and `<xf:sidebar>` compile to a write into the templater rather than to markup, so
what they produce is in neither the rendered HTML nor the page parameters. XenForo's account pages
build their whole sidebar with `<xf:sidenav>`, so this is the only surface an add-on adding a link
there can assert on.

##### Example:

```php
$this->setAppClassType('public');
$this->renderTemplate('public:account_wrapper', ['templateName' => 'account_preferences']);

$this->assertSee($this->sideNavHtml(), 'My add-on');
```

**Both accumulate across renders within one test**, as the page parameters do, so a test that
renders twice is asserting against both renders' blocks. XenForo keeps them keyed by block; where a
test needs to know which block a string came from, read
`$this->app()->templater()->getSideNavHtml()` for the array.

`assertNoUnresolvedPhrases()` searches both surfaces itself, so the call given the HTML covers them.

### assertNoUnresolvedPhrases
Assert that no phrase in the rendered output came out as its own key - which is what XenForo renders
for a phrase it cannot find.

##### Parameters:

* `html`
* `prefix` - your add-on's phrase prefix, eg `myaddon_`, a whole phrase key, or a list of either
* `message` - optional

##### Example:

```php
$html = $this->renderTemplate('public:account_preferences');

$this->assertSee($html, 'Send me a weekly digest');
$this->assertNoUnresolvedPhrases($html, 'myaddon_');
```

**Only the text the page displays is searched**, because that is where an unresolved phrase
appears. Everything inside a tag is an attribute, and an add-on's prefix belongs in plenty of them:
XenForo's resource standards require a prefix on any column added to a core table, and a form field
is named after its column, so `name="option[myaddon_thing]"` is correct output for a phrase that
resolved perfectly well. The same goes for an id, a label's `for`, a CSS class, and the template
names the templater writes into the first tag for an administrator with the `embedTemplateNames`
option on. `<script>` and `<style>` blocks are dropped too, since they hold identifiers rather than
prose.

The cost is that a phrase which failed to resolve *inside* an attribute - the `value` of a submit
button, say - is not seen here. Assert on that one directly.

**The page parameters the render set are searched as well as the html.** `<xf:title>`,
`<xf:description>`, `<xf:h1>` and `<xf:pageaction>` compile to a write into the templater rather
than to markup, so a key that failed to resolve in one of them is nowhere in the rendered output -
and a search of the html alone passes having checked nothing. The failure names which surface each
key came from, since a key reported from a page parameter cannot be found by searching the html for
it. Page parameters accumulate as each template sets them, so a test that renders twice is told
about both renders.

**The side navigation and the sidebar are searched too**, for the same reason and with the same
accumulation - a key that failed to resolve inside `<xf:sidenav>` is in no other surface, and the
failure names which of them it came from.

**Pair it with an assertion that the text you expect is present.** Output that never rendered
carries no unresolved key either.

**A prefix matches a key that ends exactly at it.** That is the shape of every key XenForo
structures for you, because the add-on's own id comes last:

| the phrase | its key |
|---|---|
| an option's title | `option.<optionId>` |
| an option's explanation | `option_explain.<optionId>` |
| an option group's title | `option_group.<groupId>` |
| an option group's description | `option_group_description.<groupId>` |
| a permission | `permission.<permissionId>` |

So where the id is the add-on's prefix, the key is the prefix and nothing more. A dotted segment in
front of the prefix is reported as part of the key, so what the failure names is what you can
search the add-on for.

**The bare prefix is the wrong argument for an admin option page.** With development mode on,
XenForo displays each option's id beside its title as ordinary text, so every option on the page is
reported whether or not its phrase resolved. Pass the keys:

```php
$reply = $this->dispatch('options/groups/myaddon/', 'admin');
$html = $this->renderReply($reply);

$this->assertSee($html, 'Hide signatures');
$this->assertNoUnresolvedPhrases($html, [
	'option.myaddon_hide_signatures',
	'option_explain.myaddon_hide_signatures',
]);
```

The ids are displayed only in development mode, so a bare prefix there passes on an install with
development off and fails on one with it on.

**More than one prefix is allowed**, for an add-on whose older phrases predate its naming
convention - renaming a phrase loses any customisation of it, so the old names are permanent:

```php
$this->assertNoUnresolvedPhrases($html, ['myaddon_', 'my_add_on_']);
```

Every unresolved key found is reported, rather than the first prefix that matches failing alone. A
failure message of your own is prefixed to that list rather than replacing it, so the keys and the
surface each came from survive beside it.

**To prove the assertion can fail, reference a phrase that does not exist from the template**, not
from the phrase table. A phrase the language has already resolved in this test is cached, so changing
it afterwards does not change a later render - a phrase row changed under
`UsesDatabaseTransactions` is rolled back like any other row, but the cache is why such a mutation
appears to do nothing.

Put the reference *inside* the block being rendered. Appending it after a macro's `</xf:macro>`
passes, because `renderMacro()` never reaches it - which reads exactly like the assertion not
working.

### renderRawReply
Render a reply through the raw renderer and return the `XF\Http\Response` it produced, with the
body set on it. For a file download or any other action whose view builds its output in
`renderRaw()`, which `renderReply()` never calls.

##### Parameters:

* `reply` - a view reply from `dispatch()` or `callAction()`

##### Example:

```php
$reply = $this->callAction('MyVendor\MyAddon:Export', 'download', 'admin', [], [], 'GET');

$response = $this->renderRawReply($reply);

$this->assertStringContainsString('text/csv', $response->contentType());
$this->assertStringContainsString('id,name', $response->body());
```

**A view that streams its output gives you an `XF\Http\ResponseStream` rather than a string.** A
view built with `$this->response->responseStream($resource, $size)` - XenForo's own attachment view
among them - needs `$response->body()->getContents()` to read it.

### assertTemplateModificationApplied
Assert that a template modification has applied, using its apply count - useful where its insertion
has no distinctive markup to search for.

##### Parameters:

* `modificationKey` - as in `_output/template_modifications/`
* `message` - optional

##### Example:

```php
$this->assertTemplateModificationApplied('myaddon_helper_account');
```

A modification whose `find` no longer matches still has status `ok`, with an apply count of zero.

The apply count is the one recorded when the template was last compiled.

**It says applied at least once.** A `str_replace` modification whose find matches twice inserts
its block twice, and this passes. `assertTemplateModificationAppliedTimes()` is the assertion for
exactly once.

A modification inserting an `<xf:include>` does not put the included template's name in the output,
so assert on the markup it produces instead.

This assertion is also the one to reach for where the insertion lands somewhere the rendered HTML
does not carry at all - inside `<xf:pageaction>`, `<xf:title>`, `<xf:h1>`, `<xf:description>`,
`<xf:sidenav>` or `<xf:sidebar>`. See `renderTemplate()`, which has what those tags do and how to
read them.

### assertTemplateModificationAppliedTimes
Assert that a template modification applies exactly this many times in each template it reaches.

##### Parameters:

* `modificationKey` - as in `_output/template_modifications/`
* `times` - the apply count, at least 1
* `message` - optional

##### Example:

```php
$this->assertTemplateModificationAppliedTimes('myaddon_helper_account', 1);
```

**The count is checked per template, not summed.** XenForo logs a row per template a modification
was applied to - one per style that holds its own copy of the template - so on a forum whose styles
have customised the template the sum is the number of styles, and a sum cannot tell two styles
from one style matched twice. Each row has to carry the count, and the failure names the template
and style of each one that does not.

A `str_replace` modification whose find matches twice inserts its block twice, so for most of them
the count a test wants is 1. A modification with no log row at all, or one applying zero times,
fails as `assertTemplateModificationApplied()` does. Zero is refused: a modification that applies
nowhere is what the plain assertion fails on, and asserting it would pin a stale find as correct.

### assertAddOnTemplateModificationsApply
Assert that every template modification the add-on registers still applies, and return each
modification key with the number of times it applied.

```php
$applied = $this->assertAddOnTemplateModificationsApply('MyVendor/MyAddOn');

$this->assertSame(['myaddon_footer', 'myaddon_header'], array_keys($applied),
    'a modification was added or removed - give it a test, or take one away');
```

With no argument it uses the add-on `$addonsToLoad` isolates, and refuses when that names more than
one or none.

**A modification added or removed fails nothing.** Every enabled modification the add-on registers
is gathered, so a new one joins the set this checks and a deleted one leaves it. The assertion
holds the property; the returned keys are what a suite pins its own inventory against, as the
second line above does, so a modification that arrives without a test, or leaves while its test
remains, is named. The keys come back ordered by modification key.

**A modification whose `find` stops matching is silent.** XenForo records it with status `ok` and an
apply count of zero, so nothing fails, and the markup it inserts is simply absent from every
render. A suite with no assertion on that markup passes identically either way - the same shape as
a class extension that stops applying, which is why `assertAddOnClassExtensionsApply()` exists
beside this.

It fails naming every modification that applied zero times, and refuses rather than passing
vacuously when there is nothing to check:

| | |
|---|---|
| the id matches no installed add-on | refused - the likely cause is a typo |
| the add-on is installed but disabled | refused - XenForo leaves its modifications out |
| it registers no enabled modification | refused - nothing to assert |

**The add-on must be enabled, but it need not be idle.** XenForo gathers modifications with
`whereAddOnActive()` and no `disableProcessing` option, so an add-on part way through an install
still has its modifications applied - which is the opposite of the class extension cache, where
both are checked. So a disabled add-on is refused here and a mid-install one is not.

**It reads what the forum has.** A modification edited in `_output/template_modifications/` and
never imported is invisible to this, so it cannot see the drift a commit without an import leaves.
A test globbing that directory and comparing it against the database catches that, and the two are
worth having together rather than one replacing the other.

**It says applied at least once.** `assertAddOnTemplateModificationsApplyTimes()` is the form for
exactly once, and `assertAddOnTemplateModificationFindsMatch()` is the one that reads the working
copy rather than the forum.

### assertAddOnTemplateModificationsApplyTimes
Assert that every template modification the add-on registers applies exactly this many times in
each template it reaches, and return each modification key with the number of times it applied.

##### Parameters:

* `times` - the apply count every modification must carry in every template, at least 1; or a map
  of modification key to the count for that one, naming every modification the add-on registers
* `addOnId` - optional - defaults to the add-on `$addonsToLoad` isolates

##### Example:

```php
$applied = $this->assertAddOnTemplateModificationsApplyTimes(1);

$this->assertSame(['myaddon_footer', 'myaddon_header'], array_keys($applied));
```

The same refusals as `assertAddOnTemplateModificationsApply()`, and the same per-template check as
`assertTemplateModificationAppliedTimes()`: XenForo logs a row per template a modification was
applied to, so the count is checked on each row rather than summed, and the failure names each
modification, template and style that is off. One count covers every modification the add-on
registers; where they differ, give a map of modification key to count instead:

```php
$this->assertAddOnTemplateModificationsApplyTimes(['myaddon_footer' => 1, 'myaddon_header' => 2]);
```

A map names every modification the add-on registers and no other - one left out, or one the add-on
does not have, is refused - so the map pins the inventory as well. With one count for all, a
modification added or removed fails nothing here either; pin the returned keys, as above.

### assertAddOnTemplateModificationFindsMatch
Assert that the find of every template modification in the add-on's working copy matches its
template exactly this many times, and return each modification key with its match count.

##### Parameters:

* `addOnId` - optional - defaults to the add-on `$addonsToLoad` isolates
* `times` - optional - the number of matches each find must have, at least 1; 1 by default. Or a
  map of modification key to the count for that one, naming every enabled artifact

##### Example:

```php
$this->assertAddOnTemplateModificationFindsMatch();

// where one find legitimately matches its template more than once
$this->assertAddOnTemplateModificationFindsMatch(null, [
	'myaddon_footer' => 1,
	'myaddon_header' => 2,
]);
```

**It reads `_output/template_modifications/` and the master template text, not the log.** The
other modification assertions see a modification as the forum last compiled it, so an artifact
edited and never imported is invisible to them, and a XenForo release that rewords the template is
seen only once the forum has recompiled. This fails before an import, and it tells zero matches
from more than one. The count is taken as XenForo's own repository takes it: `substr_count()` for
`str_replace`, `preg_match_all()` for `preg_replace` and `callback`.

It fails naming each artifact whose find matches the wrong number of times, whose template the
forum does not have, or whose find XenForo could not apply, and refuses rather than passing
vacuously where the working copy has no `_output/template_modifications/` directory or no artifact
in it. A disabled artifact is skipped, since it would apply nowhere.

**It runs where the add-on is developed.** A release zip carries no `_output/`, so on an install
that holds the add-on as a release this refuses. Keep it beside
`assertAddOnTemplateModificationsApply()` rather than in place of it: one reads what the working
copy ships, the other what the forum holds, and the two catch different days.

### renderReplyPage
Render a View reply as the page a request would produce: the reply's template wrapped in the page
container of the application it was dispatched through - navigation, header, footer, the staff bar
- with the parameters XenForo adds for a request read from the reply: `pageSection`, `controller`,
`action`, `classType`, `containerKey`, `contentKey`, `view` and `template`.

##### Parameters:

* `reply` - a View, as `dispatch()` or `callAction()` returned it
* `pageParams` - optional - merged over the page parameters the reply's template set, as the
  container reads them: `section`, `skipSidebarWidgets`, `breadcrumbPath`, or `template` to wrap
  with another container

##### Example:

```php
$this->fakesErrors();
$this->actingAsMember([], ['general' => ['view' => true]]);
$reply = $this->dispatch('help');

$page = $this->renderReplyPage($reply);

$this->assertSee($page, 'data-template="help_index"', false);
$this->assertSee($page, 'p-nav', false);
```

The wrap is XenForo's own: `public:PAGE_CONTAINER` for a public reply and `admin:PAGE_CONTAINER`
for an admin one - or the login container unless the visitor is an administrator. Where
`renderReply()` gives the template and `renderTemplate('public:PAGE_CONTAINER', ...)` gives the
furniture around content of your own, this gives the two together with the reply read into the
container, so a modification or a condition on the page that depends on the request - the
section, the controller, the template name - renders as it does in a browser.

**The container renders the forum's real navigation, notices and sidebar widgets.** On a shared
forum a sibling add-on's modification or widget can raise a template error here that the reply
alone did not: call `fakesErrors()`, and filter `assertNoTemplateErrors()` to your own templates.
`['skipSidebarWidgets' => true]` leaves the widgets out.

It refuses what `renderReply()` refuses: anything but a View, and a reply under the Cli
application, whose page render wraps nothing - a reply from `dispatch()` or `callAction()` carries
its type, one built by hand needs `setAppClassType()` first.

### renderTemplateWithoutModification
Render a template with one of its template modifications left out, so a test can show that the
markup it asserts on is the modification's work.

`assertTemplateModificationApplied()` and an assertion on the inserted markup both pass whether or
not the modification is the thing producing it. The control is the same render without it - and
getting that by disabling the modification recompiles the install's templates for every other suite
running against the same forum.

##### Parameters:

* `modificationKey` - as in `_output/template_modifications/`
* `params` - optional - template parameters, as `renderTemplate()` takes them
* `reply` - optional - the dispatched reply, when comparing against `renderReply()`

##### Example:

```php
$this->fakesErrors();

$with = $this->renderTemplate('public:account_preferences');
$without = $this->renderTemplateWithoutModification('myaddon_preferences_hint');

$this->assertSee($with, 'Hide adverts');
$this->assertDontSee($without, 'Hide adverts');
```

**To pair it with a dispatch, pass the reply's own parameters, and the reply:**

```php
$reply = $this->dispatch('account/preferences');

$with = $this->renderReply($reply);
$without = $this->renderTemplateWithoutModification('myaddon_hint', $reply->getParams(), $reply);
```

The parameters are what `renderReply()` renders the template with, and the reply is what it hands
XenForo's global template data, which is where `$xf.reply` - the controller, action and template of
the page - comes from. With both, the two renders differ by the modification and nothing else.
Without the reply, a template reading `$xf.reply.template` sees null on this side; in core that is
a generated advertising template restricted to particular pages, and most templates never read it.

**Nothing is written.** The template's text is read, the other modifications are applied to it by
XenForo's own repository, and the result is compiled and rendered in memory - so the forum's
compiled templates, its modification rows and its style cache are untouched, and this is safe on a
shared install and inside a transaction. The real template renders again afterwards, so a later
render of it in the same test is the forum's own.

**The modification has to exist and be enabled**, or this refuses. A render missing a modification
that was never applying looks exactly like a render missing one that was, so the assertion written
afterwards would pass for the wrong reason.

The template is read at `style_id` 0, which is the style the templater renders under here.

### assertSeeText / assertDontSeeText / textOf
Assert on the text of rendered output, ignoring the markup. Tags are stripped and entities decoded
first, so text split across a tag boundary still matches.

##### Parameters:

* `html`
* `value`
* `message` - optional

##### Example:

```php
// passes against <p>Bob&#039;s <b>thread</b></p>
$this->assertSeeText($html, "Bob's thread");
```

`textOf($html)` returns that text, for asserting on it yourself.

These take no `escape` flag, because the markup is stripped and the entities decoded before the
search - so there is nothing to escape the value against, and the third argument is the failure
message. See `assertSee()` for what a message does and where each assertion takes one.

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
			$simpleCache->keyExists('MyAddon', 'foo'),
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

### assertAddOnClassExtensionsApply
Assert that every class extension your add-on registers actually applies.

**An extension that does not apply is silent.** XenForo's own class runs, your code in the
extension never executes, and a suite with no test on the extended class has nothing to go red -
so the same suite passes with the extension present and with it absent, and a green run is
evidence of neither.

**It passes on a current release, and that is what it is for.** This reads the extensions from the
database and resolves each one the way XenForo does, so an extension registered against a pre-2.3
class name still applies today. What it guards is the future: a XenForo release that renames or
removes a class one of your extensions targets takes that extension out of service silently, and so
would a regression in how the framework keys them. Either becomes a failure here instead of code
that has quietly stopped running.

The list comes from the database rather than from `_output/`, so it works on an install with no
development output as well as on a development one.

##### Parameters:

* `addOnId` - optional - defaults to the add-on `$addonsToLoad` isolates, when it names exactly one

##### Example:

```php
public function test_every_class_extension_this_addon_registers_applies()
{
    $this->assertAddOnClassExtensionsApply();
}
```

**A more derived resolution still counts as applied.** Where another add-on extends the same class,
the resolution is the most derived class in the chain and yours is an ancestor of it.

**It reads `xf_class_extension`, so it cannot see an artifact in `_output/class_extensions/` that
was never imported.** That is the drift a merge, or a commit without an import, leaves behind: an
extension the repository declares and the forum has never registered. A test that reads that
directory instead catches it, and this one cannot - so **the two are complementary rather than one
replacing the other.** If your suite has such a test, keep it; replacing it with this one trades a
drift check away. Which of the two is right depends on the question: the database is what the forum
actually runs, and the artifact is what your repository promises.

**It refuses when the add-on registers no active extension**, rather than passing over an empty
list. An add-on whose extensions are all inactive reaches the same refusal, and an id matching no
installed add-on is refused separately and says so - that one is usually a typo, and the other
message reads as though the add-on exists.

It returns the class each extension was registered against, mapped to what it resolved to, for a
test that wants to assert on the set.

### requireClassNotExtended
Skip the test unless the given class resolves unextended, for a test whose point is that an add-on's
extension is absent.

`$addonsToLoad` filters which extensions are *registered*; it cannot remove one already resolved in
this process, because the XFCP proxy XenForo aliases cannot be undeclared. Such a test passes alone
and tests the opposite of what it says in a full run, so this refuses to run it rather than letting
it pass. The skip message names the class, what it resolved to, and the command to run that test
class on its own.

##### Parameters:

* `identifier` - an entity short name, `XF:UserProfile`, or any class name

##### Example:

```php
public function test_the_column_is_nullable_for_a_forum_without_the_addon()
{
    $this->requireClassNotExtended('XF:UserProfile');

    // with the extension absent, XenForo's INSERT never names the column
    $user = $this->createEntity('XF:User', ['username' => 'probe', 'email' => 'probe@example.com']);
    $this->createEntity('XF:UserProfile', ['user_id' => $user->user_id]);

    $this->assertDatabaseHas('xf_user_profile', ['user_id' => $user->user_id]);
}
```

Running each test class in its own process, which the README recommends anyway, is what makes such a
test execute rather than skip.

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

Things it cannot roll back:

* DDL implicitly commits, so anything altering the schema - a `Setup.php` step, for instance -
  escapes the transaction and must clean up after itself.
* Only this connection sees the uncommitted rows, so a test that reads the database through a
  second connection will not see what it wrote.
* An `AUTO_INCREMENT` counter advances inside the transaction and stays advanced after it, so the
  next id is not the next number. Assert that an id is greater than the last one rather than equal
  to a particular value.

**A test that needs a table to exist cannot use this trait.** Code gated on
`SchemaManager::tableExists()` - an install step that reads another add-on's table, for instance -
needs a real `CREATE TABLE`, because `SHOW TABLE STATUS` does not list temporary tables. That is
DDL, so it commits the transaction and the check above fails the test. Leave the trait off that
class and undo the test's writes in `tearDown()`:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->app()->db()->query('CREATE TABLE xf_probe_source (id INT)');
}

protected function tearDown(): void
{
    $this->app()->db()->query('DROP TABLE IF EXISTS xf_probe_source');

    parent::tearDown();
}
```

**A `tearDown()` that cleans up runs even when `setUp()` threw**, which is before the property
holding what to clean up was assigned. So it has to check what it is about to act on rather than
assume `setUp()` finished.

**That is dangerous with `XF\Util\File::deleteDirectory()`, which resolves a relative or empty path
against the XenForo root.** `canonicalizePath()` returns the path unchanged only when it starts
with `/`, a backslash or a drive letter, and otherwise prefixes `\XF::getRootDirectory()` - so an
unset property reaches it as an empty path, resolves to the forum root, and the whole installation
is deleted recursively. The readable, directory and writable checks in front of it all pass for the
root. Hold an absolute path, and refuse to delete anything that is not one:

```php
protected function tearDown(): void
{
    if ($this->tempDir !== null && strpos($this->tempDir, '/') === 0 && is_dir($this->tempDir))
    {
        \XF\Util\File::deleteDirectory($this->tempDir);
    }

    parent::tearDown();
}
```
* An entity that compiles something into the code cache on save writes a file, and a transaction
  cannot roll back a file. `XF:Widget` is one: saving it queues a compile that `dispatch()` and
  `callAction()` then run. Insert the rows directly where a test only needs the record to exist.

**Code that calls `rollbackAll()` ends the wrapper**, and everything written after that goes straight
to the database. XenForo does this on its own error paths - the job manager rolls back when a job
throws, before deciding what to do with the job, and then writes the failure itself. So a test that
runs a failing job through the manager loses the transaction and leaves rows behind. Test the method
directly, or leave this trait off that class and undo the writes yourself. The wrapper notices and
fails the test either way.

**If something commits the transaction, the wrapper says so and fails the test.** MySQL commits
implicitly on any DDL, and the adapter cannot see it happen - so without the check, teardown rolls
back nothing and everything the test wrote stays.

`$db->emptyTable()` is one, and it does not look like DDL: it issues a `TRUNCATE`, which commits.
Use `DELETE FROM` in a test that runs under this trait.

The commonest source on a development install is rendering a template whose `_output/` file does not
match the hash recorded in `_metadata.json` - the state an edit leaves it in. XenForo re-imports and
compiles it, which truncates the CSS cache - `emptyTable()` again, inside XenForo's own code.

**Load a page that renders the template, outside any test.** The watcher re-imports it from the file
and writes the hash, and the compile stops happening. That is the only remedy without a failure
mode, because the watcher treats the file as the source of truth.

Neither development command helps here, and one of them is dangerous:

* `xf-dev:import` reads `_metadata.json` and does not write it, so the next render re-imports again.
* `xf-dev:export` writes the database over the filesystem. This state means your `_output/` file
  holds an edit the database may not have, so exporting can overwrite the edit with the older copy.

A transaction also puts the test in a race with the rest of the forum for any row both write. On
MariaDB 11.8 and later, where snapshot isolation is on by default, the loser gets `Record has
changed since last read`, on some runs and not others; holding the row long enough instead gets a
deadlock, which the server resolves by rolling your transaction back. `dispatch()`, `callAction()`,
`runJobToCompletion()` and `runDeferred()` leave XenForo's own `autoJobRun` registry row alone for
that reason.

**The data registry is the first surface to watch.** `DataRegistry::set()` is a read-modify-write
of a single row keyed by name, so any two writers of that key contend - and the registry is where
XenForo caches counters that core rebuilds as a side effect of something else. A user deletion
rebuilds the approval queue's unapproved counts; a thread move rebuilds forum totals. Whatever your
test is about, read what the service or job you invoke does *after* the part you care about.

**Call `fakesRegistry()` from the `setUp()` of any class that creates or deletes content under this
trait.** It keeps those writes out of the database entirely, so there is no row to contend for, and
it is one line in your own base test case:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->fakesRegistry();
}
```

The fake pre-loads the real registry, so reads still answer with the forum's own data and a class
that does not care about the registry is unaffected. The one thing it changes is a test asserting
that a registry row reached the database; assert on the value the code read instead, or leave the
call off that class.

**Creating a user reaches two further rows, and the registry fake covers neither.** The first is
the user's group memberships. Saving an `XF:User` row rewrites them: XenForo deletes the user's
rows from `xf_user_group_relation` and re-inserts them, inside your transaction. A new user's id is
higher than every id already in that table, so the delete matches nothing and locks the index gap
above the last row until the transaction ends. Two tests creating users at the same time take that
same gap, and each one's insert then waits on the other's lock - a deadlock, which the server
resolves by rolling one of the transactions back. **Different user ids are enough**: nothing is
shared but the gap in the index.

This reaches `createEntity('XF:User')` as well as `createUserAccount()`, because XenForo rebuilds
the relations whenever a user's groups are set, which an insert always does. It is index locking
rather than two writers of one row, so `fakesRegistry()` has nothing to fake here and a server
without snapshot isolation behaves the same way.

**The second is a cached member statistic.** Saving an `XF:User` empties the cached `staff_members`
statistic, which rewrites one row in `xf_member_stat` and clears its expiry - and XenForo's own
`memberStatsCache` cron rebuilds any expired row, every ten minutes. So the forum writes the same
row your test does, and a test holding a transaction open across one of those runs loses it: the
row has changed since the transaction first read it, which the server reports as
`MySQL query error [1020]`.

**Either error is reported with the cause named.** A deadlock, a lock wait that timed out or a row
that changed since the transaction read it reaches PHPUnit wrapped in one sentence saying another
transaction held the row and that on a shared forum it is usually another suite, with the original
error and its trace beneath - so the report says to re-run on an idle forum before reading it as a
defect.

The column XenForo checks is `is_staff`, and an insert sets every column that has a default, so
this happens for an ordinary member rather than only for a member of staff. Where you want the
write gone altogether, mock the repository that performs it, for the class that creates users:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->mockRepository('XF:MemberStat', function ($mock)
    {
        $mock->shouldReceive('emptyCache');
    });
}
```

That double refuses every other call to the repository, so it belongs on a class whose tests do
not read member statistics themselves.

**So a suite that creates users is reliable against a forum nothing else is writing to, and
intermittent against a shared development install.** Where a test needs a visitor rather than a
row, `actingAsMember()` builds one in memory and writes nothing at all. Where it needs the row -
anything rendering a member through XenForo's own pages - the remedy is a forum of its own.

Where you need the real path, run the one step your add-on's behaviour hangs on rather than the
whole service - `runRunnerStep()` does that, and the event still fires from the real constructor.

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
	public function test_removing_an_account_removes_its_things_and_nobody_elses()
	{
		$user = $this->createUserAccount('alice');
		$other = $this->createUserAccount('bob');

		// plant the rows first: an assertion that a row is missing passes against a table that
		// never held it, with the code under test deleted
		$this->createEntity('MyVendor\MyAddon:Thing', ['user_id' => $user->user_id]);
		$this->createEntity('MyVendor\MyAddon:Thing', ['user_id' => $other->user_id]);
		$this->assertDatabaseCount('xf_myaddon_thing', 2);

		$this->app()->service('MyVendor\MyAddon:Remover', $user)->remove();

		$this->assertDatabaseMissing('xf_myaddon_thing', ['user_id' => $user->user_id]);
		// the other user keeps theirs: the control that the removal was scoped, not total
		$this->assertDatabaseHas('xf_myaddon_thing', ['user_id' => $other->user_id]);
	}
}
```

**Plant the row before asserting it is gone.** `assertDatabaseMissing()` passes against a table that
never held the row, and `assertDatabaseCount()` of zero passes against an empty one, with the code
under test deleted. A row the code must leave alone, asserted present afterwards, is the control
that the removal was scoped. A criteria-free `assertDatabaseCount('xf_myaddon_thing', 0)` counts the
whole table.

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

A primary key is an auto-increment column, so an ordinary set cannot touch it. Passing one in
`values` is refused, with the call that sets it:

```text
XF:User declares 'user_id' read-only or auto-increment, so an ordinary set cannot touch it. Leave
it out of the values - makeEntity() takes the rest as it is - and set it on the entity afterwards:
$entity->setTrusted('user_id', 42);
```

```php
$user = $this->makeEntity('XF:User', ['username' => 'Alice']);
$user->setTrusted('user_id', 42);
```

The same applies to any column XenForo declares read-only, and to `createEntity()` and
`createUserAccount()`, which take their values the same way. Left to XenForo the message names
`forceSet`, which is not the option to reach for.

**`makeEntity()` refuses a value that did not land.** XenForo verifies many columns as they are
set rather than at save time - a unique key already in use, an email that is not one, a username
below the minimum length - and records a failure instead of throwing, leaving the column null. That
used to hand back an entity quietly missing what the test asked for. It now throws, naming the
column and XenForo's own reason. An error the entity carries for any other reason is left alone.

So a test that wants a value a verifier would reject sets it with `setTrusted()`:

```php
$option = $this->makeEntity('XF:Option');
$option->setTrusted('option_id', 'myaddon_slots');
```

**Never do this to a visitor built by `actingAs()`.** Re-keying `user_id` drops the relations the
visitor was hydrated with, and the next public dispatch then fails inside XenForo with
`Attempt to read property is_discouraged on null`, which points nowhere near the cause. Pass the id
in instead: `actingAsMember(['user_id' => 42])`.

##### Building a fixture that spans relations

An unsaved entity cannot load its relations from the database. Link entities in memory with
XenForo's `hydrateRelation()`:

**Nodes and threads have helpers of their own, and for a node it matters which you use.** The
example below never saves, so it is safe - but `createEntity('XF:Node', ...)` does, and saving a
node schedules a tree rebuild that writes every row in `xf_node`. Use `createNodeFixture()` and
`createThreadFixture()` where a test needs the rows to exist.

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


##### Typing under PHPStan

Both are declared to return `XF\Mvc\Entity\Entity`, which is wide: an entity built here and handed
to a parameter that wants the concrete entity, or asked for one of its methods, is reported from
level 5. The `extension.neon` this package ships types them from the argument instead, through
[hampel/xenforo-phpstan](https://github.com/hampel/xenforo-phpstan), so include both:

```neon
includes:
    - vendor/hampel/xenforo-phpstan/extension.neon
    - vendor/hampel/xenforo-test-framework/extension.neon
```

`'XF:User'` and `User::class` both give `XF\Entity\User`, or your add-on's own extension of it where
the forum resolves to one, as `create()` does. A variable, or a name the forum does not have,
leaves `Entity` standing.

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

**`createEntity('XF:User')` writes the `xf_user` row and nothing else.** A user in practice also has
profile, option, privacy and authentication records, and a XenForo page that reads one of them - the
admin user editor does - fails on a null relation rather than on anything the test is about. Use
`createUserAccount()` where the test needs a member XenForo's own pages can display.

##### Reusing an entity after something else saved it

**On XenForo 2.3.0 an entity keeps its cascaded saves once it has been saved**, where by 2.3.12 the
list is cleared. So a parent a service created re-saves its children on every later save, and
setting a value on one of those children throws `Attempted to set '<column>' while a save was
pending without forceSet` - from the test's own line, naming a column the test may never mention.

Two things work on every 2.3 release:

* re-fetch the parent after the service saved it, so the test goes on from a fresh instance;
* `$parent->removeCascadedSave($child)` for the child the test is about.

`$parent->reset()` is not one of them. The releases that clear the list on save clear it in
`reset()` as well, so on the only version that needs it `reset()` clears nothing.

### mockFinder
Mock a Finder.

##### Parameters

* `identifier` - the entity short name, `Vendor:Name`, or a finder class name. Anything else is
  refused: XenForo cannot turn it into a finder class, and a placeholder string is a mistake in the
  test rather than something to guess at
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

**A class extension added during a test lasts only for that test.** `addClassExtension()` on the
application's extension registers it and clears the cached resolution, so the class is extended from
that point; teardown forgets it again, so a later test resolving the same class gets the base class.
Extending the same class a second time anywhere in the run is refused: the proxy XenForo aliases for
an extension cannot be declared twice in one process.

**Code that looks an entity up with `\XF::em()->find()` needs a finder mock, not `mockEntity()`** -
`find()` resolves through a finder, so a mocked entity is never consulted. `mockFind()` below is
that mock for the common case.

### mockFind
Decide what `\XF::em()->find()` returns for one id.

##### Parameters:

* `shortName` - the entity short name, eg `'XF:User'`
* `id` - the id `find()` will be called with
* `entity` - optional - the entity to return, or `null` for "no such record"

##### Example:

```php
$media = $this->makeEntity('XFMG:MediaItem', ['title' => 'A photo']);

$this->mockFind('XFMG:MediaItem', 42, $media);
$this->assertSame($media, $this->app()->em()->find('XFMG:MediaItem', 42));

// and the missing case
$this->mockFind('XFMG:MediaItem', 43, null);
```

It returns the finder mock, so further expectations can be set on it. `find()` reads the entity
manager's cache before it builds a finder, so this clears the cache for that type - otherwise an
entity the test loaded earlier would be returned instead of the mock.

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

**Set expectations on `get()`, not on `__get()`.** `Entity::__get()` delegates to `get()`, so an
expectation on the magic method never applies - the real `__get()` runs and calls a `get()` nothing
has stubbed. The failure names `get()` with a trace pointing into XenForo rather than at your test:

```php
$user = Mockery::mock(\XF\Entity\User::class);
$user->allows()->get('user_state')->andReturns('valid');

$user->user_state;      // 'valid'
```

**Array access needs its own expectation.** `offsetGet()` delegates to `get()` as well, but a double
replaces `offsetGet()` too, so `$user['user_state']` fails unless it is stubbed in its own right.

### fakesErrors
Allow us to assert that certain errors were (or were not) thrown as a result of executing our test code, without
side-effects (ie no logs written to database).

##### Parameters:

none

##### Assertions available:

* `assertExceptionLogged` - takes the exception class, and optionally a count, a string the logged
  message must contain, or a callback
* `assertExceptionLoggedTimes`
* `assertExceptionNotLogged` - takes the exception class, and optionally a string or a callback,
  which narrows what it denies. A count is refused: there is no number of exceptions that counts
  as none, so a count belongs to `assertExceptionLoggedTimes()`
* `assertNoExceptionsLogged`

**The string form matches the message that was logged, prefix included.**
`\XF::logException($e, false, 'MyAddon: ')` stores that prefix in front of the exception's own
message, so `assertExceptionLogged($class, 'MyAddon: ')` asserts the prefix and
`assertExceptionLogged($class, 'smtp is down')` asserts the exception. It is a substring match,
anywhere in the message.

**A string or a callback given to `assertExceptionNotLogged()` narrows what is denied**, so it
asserts that no exception of that class matching the filter was logged - not that none was logged
at all. A count is not accepted there, since no number of exceptions amounts to none; use
`assertExceptionLoggedTimes()` for a count.

**A callback is handed the logged entry, not the throwable.** It is an array, whose useful keys are
`message` (including any prefix the caller added), `exception_type`, `filename`, `line` and
`raw_exception` - the throwable itself:

```php
$this->assertExceptionLogged(TransportException::class, function (array $entry)
{
    return strpos($entry['message'], 'smtp is down') !== false;
});
```
* `assertErrorLogged` - takes an optional message; omit it to assert that any error was logged
* `assertErrorNotLogged` - takes an optional message; omit it to assert that no error was logged
* `assertNoErrorsLogged`
* `assertNothingLoggedContains` - takes the text that must not appear in any logged message

**`assertNothingLoggedContains($needle)` is for the assertion the others cannot express**: that a
credential, a token or a user's data did not reach the log, whatever else was logged. The
per-message assertions need the message, and the point here is not knowing what the message would
be. It reads errors and exceptions alike, since an error is recorded as an `\ErrorException` entry.

```php
$this->fakesErrors();

// the positive half, so the assertion below examined a message rather than an empty log
$this->assertExceptionLogged(TransportException::class);
$this->assertNothingLoggedContains($apiToken);
```

**Pair it with an assertion that something was logged**, as above. A run that logged nothing
contains the text either, so on its own it passes without having examined anything. An empty needle
is refused, since an empty value is found in every message.

**`getLoggedMessages()` returns the message of everything logged**, in order and with each caller's
prefix, for a test that wants to assert on the set rather than on one entry.

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

**It covers the shared client and any client your add-on builds with
`$app->http()->createClient()`**, which is what an add-on needing its own `base_uri`, headers or
timeouts has to use. A client created while a fake is installed is built on the fake handler, with
the rest of its configuration kept; one created before the fake keeps the real handler, so install
the fake first.

**A second fake in the same test reaches clients already built on the first one**, including one an
add-on's sub-container built and kept, so a test can run the code twice with a fresh queue each
time:

```php
$this->fakesHttp([$account, $fields]);
$this->runConsoleCommand(MyAddOnCommand::class);

// the same command again, with the responses queued afresh
$this->fakesHttp([$account, $fields]);
$this->runConsoleCommand(MyAddOnCommand::class, ['--strict' => true]);
```

**An empty queue is worth installing in `setUp()`, so a request no test expected fails instead of
leaving the machine.** With `fakesHttp([])` in place a stray call raises
`OutOfBoundsException: Mock queue is empty`, naming itself, where without any fake it reaches the
real endpoint with whatever credentials the forum's options hold. Install the responses a test does
expect over the top, which the paragraph above covers:

```php
protected function setUp(): void
{
    parent::setUp();

    // nothing in this suite may reach the network unless a test says so
    $this->fakesHttp([]);
}
```

Changing fake kind within a test works the same way, so a `fakesHttpByUrl()` after a `fakesHttp()`
takes over for every client. What still does not work is installing any fake *after* the client was
built with no fake in place - that client holds the real handler, and nothing retrospectively
replaces it.

##### Parameters:

* `array responseStack` - an array of Psr7 Responses or Request Exceptions to return - one for each request made
* `bool untrusted` - set to true when using the untrusted client in XenForo

##### Assertions available:

* `assertHttpRequestSent`
* `assertHttpRequestNotSent`
* `assertHttpRequestSentTimes`
* `assertNoHttpRequestSent`

##### Reading the requests:

* `getHttpRequests()` - the requests sent, in order
* `getHttpHistory()` - the same, each request with the response it received

Use these where the assertion needs to take a request apart - decoding a JSON body and comparing
its fields reads better as `$this->getHttpRequests()[0]` than as a callback that has to match and
assert at once.

**The history belongs to the test, not to the fake.** A second `fakesHttp()` in the same test keeps
what the first recorded, so to assert on what a second run sent, compare the count before and
after rather than expecting to start from nothing.

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
Work queued with `\XF::runOnce()` runs after each pass, through `runDeferred()`.

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

### runDeferred
Run the work queued with `\XF::runOnce()`, and return the keys of what was queued, in queue order.

XenForo defers side effects this way - an entity's `_postSave()` queuing a cache rebuild,
`XF:Passkey` queuing the update of the user's passkey provider on save and on delete - and a
request runs the queue at the end of its dispatch. `dispatch()`, `callAction()` and
`runJobToCompletion()` run it too. A test that saves or deletes an entity directly is the case this
is for: nothing else runs the queue, and teardown discards it, so the side effect never happens.

##### Example:

```php
$user = $this->createUserAccount('alice');

$this->createEntity('XF:Passkey', [
	'user_id' => $user->user_id,
	'name' => 'Laptop',
	'credential_id' => 'credential',
	'credential_public_key' => 'public-key',
	'aaguid' => str_repeat('0', 32), // nullable on the entity, NOT NULL in the table
]);

$row = ['user_id' => $user->user_id, 'provider_id' => 'passkey'];
$this->assertDatabaseMissing('xf_user_tfa', $row);

$this->assertSame(['updateUserPasskeyProvider-' . $user->user_id], $this->runDeferred());

$this->assertDatabaseHas('xf_user_tfa', $row);
```

**The keys say what was queued**, so assert them: a call that finds nothing queued returns `[]` and
runs nothing. Work that deferred work queues in turn runs as well, as it would in a request, and is
not among the keys.

**Calling `\XF::triggerRunOnce()` yourself is not the same thing.** An exception thrown by deferred
work fails the test here; XenForo's own loop logs it, rolls back whatever transaction is open and
carries on, so the test passes on a transaction the server has ended. And XenForo's `autoJobRun`
registry write is left out, for the reason given under `UsesDatabaseTransactions`.

**The entity cache is not cleared**, as for `runJobToCompletion()`. A save and a delete in one test
are two requests in production, and an entity the first loaded - the provider with its user entries
- still holds what it had before the deferred work ran, so the second finds nothing to remove.
Clear it with `$this->app()->em()->clearEntityCache()` between the two.

### runRunnerStep
Run one step of a XenForo multi-part runner to completion, and return how many times the step ran.

XenForo's clean-up, merge and content-change services are multi-part runners: a list of steps run
by `XF\MultiPartRunnerTrait::runLoop()`. An add-on answering `user_delete_clean_init`,
`user_delete_clean_steps` or their siblings contributes work to one of those steps, so that is the
step a test about the add-on needs - and running the whole service to reach it runs every other step
too, which is how a test ends up contending with the live forum over rows it does not care about.

##### Parameters:

* `runner` - the service, as `app()->service()` built it
* `stepName` - a method name as the runner's own `getSteps()` lists it
* `maxCalls` - optional, how many calls before the step is judged not to finish

##### Example:

```php
$user = $this->createUserAccount();

$service = $this->app()->service('XF:User\DeleteCleanUp', $user->user_id, $user->username);

$this->runRunnerStep($service, 'stepDeleteContent');

$this->assertDatabaseMissing('xf_my_addon_record', ['user_id' => $user->user_id]);
```

**A step is not a method you call once.** Its return value is a resume offset, not a result: `null`
or `false` means finished, and anything else means *call me again from there*. A step asks for that
when it has processed its batch as well as when it has run out of time - `stepDeleteProfilePosts`,
`stepDeleteContentVotes` and `stepDeleteBookmarks` all fetch 1,000 rows per call and ask to resume
whenever that fetch was full, whatever time limit they were given. So calling the step directly
runs one batch, and the fixture a test builds is usually small enough that it looks complete. This
loops until the step reports it has finished.

The step is named rather than passed because the name is checked against the runner's own
`getSteps()`, which fires that runner's event - so a step a listener added is selectable, and a
typo or a step XenForo has renamed is refused with the list of steps the runner does run. It also
refuses a runner that does not use `XF\MultiPartRunnerTrait`, a step that asks to resume from the
position it was just given, and a step still going after `maxCalls`.

Steps are `protected`, so this reaches them by reflection. Nothing is rolled back, exactly as with
`runJobToCompletion()`, so use `UsesDatabaseTransactions` for a step that writes.

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

### recordsLog
Record what your add-on logs through a PSR-3 logger of its own, instead of writing it.

`fakesLogger()` above covers XenForo's moderator log. This is for the other kind: an add-on that
puts a `Psr\Log\LoggerInterface` in the container under a key of its own - bound to another add-on's
channel, or to a `NullLogger` when that add-on is absent - and logs through that. Nothing else here
can see what reached it.

**The container key is yours, so it has to be given.** The framework cannot know it.

##### Parameters:

* `key` - the container key your add-on keeps its logger under, eg `myaddon.log`

##### Assertions available:

* `assertLogged` - takes an optional level, and an optional string the message must contain or a
  closure given the whole entry
* `assertNotLogged` - the same arguments, asserting that nothing matching was logged
* `assertNothingLogged` - asserts that nothing at all was logged
* `getLogRecords` - every entry, in order
* `getLogRecorder` - the recorder itself

**An entry is `level`, `message` and `context`.** The message is recorded as your code passed it,
with its `{placeholder}` braces intact, and the values that would fill them are in `context` - so a
test asserts on what the code asked to be logged rather than on an interpolated string. Match on the
context with a closure.

**A string filter is always matched against the message and never called**, whatever it spells, so
pass a closure to filter on anything else.

**Omit the key on the assertions when the test installed one recorder**, which is the usual case.
With two installed they refuse rather than picking one, and `getLogRecords($key)` takes the key.

##### Example:

```php
<?php namespace Tests\Unit;

use Tests\TestCase;

class ApiFailureTest extends TestCase
{
	public function test_a_refused_request_is_logged_without_the_token()
	{
		$this->recordsLog('myaddon.log');

		// execute some test code which logs through the container's logger
		...

		$this->assertLogged('error', 'refused');
		$this->assertLogged('error', function (array $entry) {
			return $entry['context']['status'] === 403;
		});

		// and the credential did not go into it
		$this->assertNotLogged(null, $apiToken);
	}
}
```

**A swap does not reach a logger something already holds.** `recordsLog()` replaces the container
entry, so code that resolves the key after it is called gets the recorder - but a service built
earlier in the test holds whatever it was handed. Install the recorder before the code under test
resolves it, which for a service built in the test body means first.

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

##### Testing what happens when a send fails

`fakesMail()` returns the transport, and `failWith()` makes every send from then on throw. XenForo's
mailer catches whatever the transport throws, logs it, and answers `false` - so that is what the code
under test sees. A failed send records no mail, so the assertions above see none.

```php
$transport = $this->fakesMail();
$this->fakesErrors();

$transport->failWith(new TransportException('smtp is down'));

$mail = $this->app()->mailer()->newMail()
    ->setTo('member@example.com', 'Member')
    ->setContent('Subject', '<p>Body</p>');

$this->assertFalse($mail->send());
$this->assertMailNotSent();
$this->assertExceptionLogged(TransportException::class);

$transport->sendsSuccessfully();       // sends work again
```

`failWith()` with no argument installs a `TransportException` of its own. **Install `fakesErrors()`
alongside it**, or the mailer's log entry is written to the forum's real error log.

A successful send answers Symfony's `SentMessage`, not `true`, so assert `assertNotFalse()` rather
than `assertTrue()` when checking the other direction.

**Build the mail after installing the fake.** `newMail()` hands the mail the mailer it was built
from, and that reference is not affected by the swap - so a mail built beforehand sends through the
forum's real transport, the fake captures nothing, and `assertMailNotSent()` passes while the message
has actually gone out. Code under test that builds and sends inside the method being tested is
unaffected.

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

### createTempDirectory
Create an empty temporary directory to write into, and have the framework remove it.

Somewhere for a test that writes files - an export, an attachment, a cache file - to put them
without touching the forum. The path is absolute and inside the system temporary directory, and it
is removed when the test finishes whether the test passed, failed or errored, so **a test needs no
`tearDown()` of its own**. That is the point of it.

**Reach for this rather than pairing `File::getTempDir()` with `File::deleteDirectory()`
yourself.** `deleteDirectory()` resolves a relative or empty path against the XenForo root, so a
cleanup holding a path that was never assigned deletes the installation - and a `tearDown()` runs
even when `setUp()` threw before assigning it. `UsesDatabaseTransactions` above has the mechanism.

##### Parameters:

* `prefix` - optional - so the directory is recognisable while a test is running

##### Example:

```php
$dir = $this->createTempDirectory();

$this->app()->service('MyVendor\MyAddOn:Exporter')->writeTo($dir);

$this->assertFileExists($dir . '/export.csv');
```

Nothing it removes can be outside the system temporary directory: the removal refuses any path that
does not resolve inside there, so the forum is unreachable however the path was arrived at.

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

**An option whose value is an array is replaced whole, not merged.** XenForo keeps a group of
sub-options as one option, so setting two of them leaves the rest absent and the code under test
fails on an undefined array key rather than on anything the test is about - `registrationDefaults`
on a stock install holds fifteen, and naming one drops fourteen. Merge over what the forum has
when only one sub-option is the point:

```php
$this->setOption('registrationDefaults', ['receive_admin_email' => true]
    + (array) $this->app()->options()->registrationDefaults);
```

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

**Reach for it in any test class that creates or deletes content under `UsesDatabaseTransactions`**,
not only in a test about the registry. The registry is a read-modify-write of one row per key, and
core rebuilds cached counters into it as a side effect of saves and deletions a test is not
otherwise concerned with - so a transaction and the live forum contend for the same row. See
`UsesDatabaseTransactions` for the shape of that failure.

**What writes a row is core rebuilding one of its own caches**, from the `_postSave()` or
`_postDelete()` of a core entity and the repositories those call. So a suite that saves only its
own add-on's entities, builds its visitors in memory and inserts rows directly may write nothing at
all, and can measure that by swapping `registry` for a subclass of `XF\DataRegistry` that records
each `set()` and `delete()` before calling the parent.

**Calling it anyway is still the recommendation**, because the hazard is not a property of the
suite: the contended row is one the live forum writes, so a suite that writes none today starts
writing one the first time a test saves a core entity, and nothing in the suite says when that
happened.

**It intercepts writes; it does not isolate reads.** A key the test never wrote is read from the
forum's own `xf_data_registry`, as the parameter below says - so an assertion that a registry key
*exists* passes whether or not the code under test put it there. Assert the value.

Unlike the http, mail and filesystem fakes, this one needs nothing decached: every reader asks the
container for the registry at the moment it uses it, so a repository or service a test built before
installing the fake still writes through it.

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
		// initialise the registry fake system
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
* `assertSimpleCacheEqual`, also spelled `assertSimpleCacheEquals`
* `assertSimpleCacheNotEqual`, also spelled `assertSimpleCacheNotEquals`

##### Reading the cache:

* `getSimpleCache()` - the fake, for reading a value back or setting one up

Use this rather than reaching into the container for `simpleCache`: it refuses with a message
naming `fakesSimpleCache()` when the fake was never installed, where the container hands back the
real cache and the test writes to the forum's own.

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
		
		// retrieve the fake, which refuses if fakesSimpleCache() was not called
		$simpleCache = $this->getSimpleCache();

		// do something which causes an update to the simpleCache, for example
		$simpleCache->setValue('MyAddon', 'foo', 'bar');
		
		// now check that the simpleCache contains the key that we expect
		$this->assertSimpleCacheHas('MyAddon', 'foo');
		
		// or check that the value is what we expect
		$this->assertSimpleCacheEqual('bar', 'MyAddon', 'foo');
	}
}	
```

### runConsoleCommand
Run a console command and return Symfony's `CommandTester`, for asserting on its output and exit
code. What `dispatch()` is for a route.

##### Parameters:

* `command` - the command class, or an instance where it needs constructing. The class name, not
  the name the command is invoked by on the command line - `MyAddOnCommand::class`, not
  `'my-addon:do-thing'`
* `input` - optional - arguments and options, as `CommandTester` takes them
* `options` - optional - passed to `CommandTester::execute()`, eg `['verbosity' => …]`, plus
  `['inputs' => [...]]` for a command that asks questions

##### A command that asks before it acts

A destructive command confirms first, and both sides of that are testable.

```php
// stops at the confirmation, and writes nothing: every question answers with its default,
// which is null for a bare question and false for a confirmation
$tester = $this->runConsoleCommand(ArchiveAll::class, [], ['interactive' => false]);
$this->assertStringContainsString('Nothing was archived', $tester->getDisplay());

// the confirmed path - for a forum you can throw away
$tester = $this->runConsoleCommand(ArchiveAll::class, [], ['inputs' => ['yes']]);
```

`inputs` are the answers in order, one per question. **Supplying them with
`['interactive' => false]` is refused**, because Symfony would answer every question with its default
and ignore them - a test of the confirmed path would pass having answered nothing.

**Confirming a command does the work, against the forum `XF_ROOT` names.** A command built on
XenForo's `Rebuild\AbstractRebuildCommand`, or on anything using its `JobRunnerTrait`, runs its job
in its own process rather than queueing it: `runJob()` loops until the job reports it is done. So
answering yes to a bulk command rebuilds, or deletes, everything it was going to - now, on a
development forum shared with everything else. `UsesDatabaseTransactions` is not a reliable guard
either, since the job manager ends the transaction on its own error paths.

Test the confirmed path of a bulk command on a sandbox forum. On a shared install, the
`['interactive' => false]` run above is the one that belongs in the suite, and a single-record
command confirmed inside a transaction is usually as far as it is worth going.

##### Example:

```php
$tester = $this->runConsoleCommand(MyAddOnCommand::class, ['--force' => true]);

$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
$this->assertStringContainsString('12 rows updated', $tester->getDisplay());
```

The command runs against the booted application, so the fakes and swaps a test installed apply to
it. A command with no name is refused - XenForo's own commands set one in `configure()`.

##### A name carrying whitespace or braces is refused

**Symfony validates a command name against colons and nothing else**, so a name with an argument
placeholder written into it - `setName('myaddon:thing {subject}')` - is accepted, and the command
runs. What it breaks is everything around the command: `cmd.php list` prints the name as written,
placeholder and all, and the command can only be reached as an abbreviation of itself. Add a second
command sharing that prefix and both become unreachable, with `Command "myaddon:thing" is
ambiguous`.

So the name is the command's name, and arguments and options are declared separately:

```php
protected function configure()
{
    $this->setName('myaddon:thing')
        ->addArgument('subject', InputArgument::REQUIRED);
}
```

`assertConsoleCommandsLoad()` checks this for every command an add-on ships, which is where a name
nobody has looked at since it was written gets reported.

### assertConsoleCommandsLoad / assertConsoleCommandLoads
Assert that the console command classes an add-on ships are ones XenForo can load.

**A command class that cannot load takes `cmd.php` down for the whole forum, not just for the add-on
that owns it.** XenForo loads every add-on's command classes simply to list them, with
`class_exists()`, which autoloads - so a missing parent class is a fatal there. Nothing else in a
suite loads these classes, so nothing else notices.

`assertConsoleCommandsLoad()` walks the add-on's `Cli/Command` the way XenForo does, recursively,
turning each `.php` file into a class name by its path. **It returns the classes XenForo would
list as commands, which is fewer than it loaded** - the skips below are loaded and left out, so
an assertion over the whole list counts the commands rather than the files.

**It skips what XenForo's runner skips**, so the layout core itself uses is not a failure: an
abstract base beside the commands - core has `XF\Cli\Command\AbstractCommand` - a trait or
interface file, and any class that is not a Symfony `Command`. None of those is listed by XenForo and
none affects `cmd.php`, so none belongs in the returned list either.

**What it never skips is a class that cannot be loaded.** That is the case that stops `cmd.php` for
every add-on on the forum, and it is reported as such.

It refuses, rather than passing, when the walk finds no PHP files at all, and when the files it finds
declare nothing XenForo would list - otherwise the skips above could add up to an assertion over
nothing.

##### Parameters:

* `addOnId` - optional - defaults to the add-on `$addonsToLoad` names, when it names exactly one

##### Example:

```php
public function test_every_console_command_loads()
{
    $classes = $this->assertConsoleCommandsLoad();

    $this->assertContains(MyAddOnCommand::class, $classes, 'the walk should have found this one');
}
```

The `assertContains()` line is worth keeping: it proves the walk reached the directory you meant,
which the refusal above cannot do on its own.

**The stricter form is to assert the whole list**, which also fails when a command file is added with
no test of its own:

```php
$this->assertSame([
    MyAddOn\Cli\Command\Export::class,
    MyAddOn\Cli\Command\Import::class,
], $this->assertConsoleCommandsLoad());
```

The walk's order follows the filesystem, so sort both sides if that matters to you.

`assertConsoleCommandLoads($class)` checks one class - it exists, is instantiable, extends Symfony's
`Command`, and has a name. That mirrors `XF\Cli\Runner::isValidCommandClass()`, which is protected,
plus the name a listed command needs to be usable.

**A name carrying whitespace or braces fails both of them**, because such a command runs and is
still broken - see `runConsoleCommand()` above for what it breaks and how to write the name
instead.

### setStaticProperty
Set a static property, restoring what was there when the test finishes.

##### Parameters:

* `class`
* `property`
* `value`

##### Example:

```php
$this->setStaticProperty(\XF::class, 'versionId', 2020070);
```

A static outlives the application the framework rebuilds for each test, so without the restoration a
value set here would be read by every later test in the run.

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

Refer to the `Hampel\Testing\Concerns\InteractsWithTime` trait for full details.
