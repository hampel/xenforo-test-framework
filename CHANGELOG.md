# Changelog

## 5.33.0 (unreleased)

* `assertTemplateModificationAppliedTimes()` and `assertAddOnTemplateModificationsApplyTimes()`
  assert that a template modification applies exactly the given number of times in each template
  it reaches. The existing assertions say applied at least once, which a `str_replace`
  modification whose find matches twice still passes while inserting its block twice. The count is
  checked per template rather than summed, since XenForo logs a row per style holding its own copy
  of the template, and a sum counts styles as well as matches
* `assertAddOnTemplateModificationFindsMatch()` asserts that the find of every template
  modification in the add-on's working copy matches its template exactly the given number of
  times, read from `_output/template_modifications/` and the master template text rather than
  from the forum's log - so it fails before an import, and it tells zero matches from more than
  one. It counts as XenForo does: `substr_count()` for `str_replace`, `preg_match_all()` for
  `preg_replace` and `callback`. A disabled artifact is skipped, and a working copy with no
  artifacts is refused
* `renderTemplateWithoutModification()` takes the dispatched reply as an optional third argument
  and passes it to XenForo's global template data as `renderReply()` does, so `$xf.reply` is the
  same on both sides of the comparison
* `assertReplyIsLoginRequired()` asserts that a reply is the refusal of a guest from a route that
  calls `assertRegistrationRequired()`: the `login` template, the `XF:Error\RegistrationRequired`
  view class and a 403. It is a view, so `assertReplyIsError()` fails on it, and the login page
  itself renders the same template at a 200
* `assertExceptionNotLogged()` refuses a count with a `LogicException` naming
  `assertExceptionLoggedTimes()`. An int reached the filter before and failed as not callable
* docs: `TIPS.md` says to prefix the helpers a test class adds, because a private method of the
  same name as one of the framework's protected helpers is a fatal at class load
* docs: `TIPS.md` says to use a whitelisted method prefix when mutating a template to prove a test
  can fail, because a method the compiler rejects leaves the template unmutated and the test green
* docs: `assertNoTemplateErrors()` names the case where an add-on owning no templates can still
  filter by name - it is the only add-on modifying the one core template its markup is in

## 5.32.1 (2026-10-23)

* docs: the README recommends `hampel/xenforo-phpstan` for analysing an add-on with PHPStan, and
  says why PHPStan cannot analyse one on its own

## 5.32.0 (2026-10-19)

* `createNodeTreeFixture()` takes an optional `key` for each node in the spec, which is what that
  node is called in the array the helper returns - for a test whose own name for a node is not the
  title a reader of the forum would see. It defaults to the title, so a spec naming no keys behaves
  as it did. The key is what has to be unique rather than the title, so two nodes may share a title
  where their keys differ, as they may on a real forum
* docs: `renderWidgetHtml()` says that its three refusals can only reach a render where the
  widget named a template. A widget that declines returns before it names one, so a decline
  test on its own does not check that the widget's template still exists - render the showing
  case beside it
* docs: `dispatch()` and `followRedirect()` name the two reasons a `GET` is answered with a
  redirect, and the type that tells them apart. A permanent one is an address correction, and a
  node's canonical address carries its title as well as its id, so `forums/<id>/` is a redirect
  rather than the page; a temporary one is a controller pointing at something it stored, as What's
  New and search both do, where no address a test could dispatch avoids it

## 5.31.0 (2026-10-18)

* `renderWidgetHtml()` renders one widget and returns its HTML, named either by its definition
  class or by the key of a widget the forum has configured. A widget that decides not to show
  itself renders an empty string and that is preserved, so an assertion on the absence means the
  widget declined. The other three ways a widget render comes back empty are refused instead - a
  renderer carrying no template name, a template that does not exist, and a template that errored -
  because an assertion cannot tell them from a widget that declined. The style a render needs is
  installed for you; the application type is not. The short `Vendor\AddOn:MyWidget` identifier is
  refused, since XenForo does not expand one for a widget

## 5.30.1 (2026-10-18)

* `createThreadFixture()` honours an explicit `user_id` of 0, so a thread can be attributed to a
  guest. It resolved 0 to user 1 instead, which gave the thread that member's name, attributed its
  first post to a member the test never named, and adjusted that member's message count - a write
  a guest's post does not make at all
* `createThreadFixture()` refuses a `user_id` with no `xf_user` row unless a `username` is given
  beside it, naming the lookup that found nothing. A thread's `username` column is required, so the
  save failed naming that column instead - including for a visitor built by `actingAsMember()`,
  whose id need match no row

## 5.30.0 (2026-10-17)

* `createNodeFixture()`, `createNodeTreeFixture()` and `createThreadFixture()` create forum content
  without rebuilding the forum's node tree. Saving an `XF:Node` the ordinary way schedules a
  nested-set rebuild and enqueues a `permissionRebuild` job, and the rebuild writes every row in
  `xf_node` - so inside a test's transaction every node on the forum is locked until the test ends,
  whether or not any value changes. A fixture node is one write rather than four and touches no
  existing row. `createNodeFixture()` creates a node at the root and refuses a parent, because a
  child has to be numbered inside its parent and a parent whose range is wider than its children
  makes XenForo report that a childless node has children; `createNodeTreeFixture()` builds a
  parent and its children in one call and numbers them tightly. `createThreadFixture()` creates the
  thread's first post as well, since a thread whose `first_post_id` is 0 has a null `FirstPost`
* docs: a node at the root has an empty `breadcrumb_data`, which is what makes the behaviour rebuild
  the tree whenever such a node is saved again - true of a real root node, not only of a fixture.
  The option to set on that save is named

## 5.29.0 (2026-10-17)

* `assertAddOnTemplateModificationsApply()` asserts that every template modification an add-on
  registers still applies. One whose `find` stops matching is silent - XenForo records it with
  status `ok` and an apply count of zero, so nothing fails and the markup it inserts is absent from
  every render. An id matching no installed add-on, an add-on that is disabled, and an add-on
  registering no enabled modification are each refused rather than passing over an empty list. The
  add-on must be enabled but need not be idle: XenForo gathers modifications with no
  `disableProcessing` option, so one part way through an install still has its modifications
  applied, unlike its class extensions. It reads the database, so a modification edited in
  `_output/template_modifications/` and never imported is invisible to it
* `sideNavHtml()` and `sidebarHtml()` read back what a render contributed through `<xf:sidenav>` and
  `<xf:sidebar>`. Both compile to a write into the templater rather than to markup, so what they
  produce is in neither the rendered output nor the page parameters, and XenForo's account pages
  build their whole sidebar that way - an add-on adding a link there previously had nothing to
  assert on. Both accumulate across renders within one test, as the page parameters do
* `assertNoUnresolvedPhrases()` searches the side navigation and the sidebar as well as the
  rendered output and the page parameters, and the failure names which surface each key came from.
  A key that failed to resolve inside `<xf:sidenav>` is in no other surface, so a search of the
  rendered output alone passed having checked nothing
* docs: `actingAsMember()` says that its default `user_id` of 1 is the owner on most forums for
  reading as well as for display - anything fetching rows keyed by the visitor's id answers with
  the owner's records, so a test asserting it found none fails on a forum where the owner has one.
  It gives the id to pass instead
* docs: `setVisitorContentPermissions()` says that a test counting queries cannot see per-node
  permission loading for the ids it set, since they are in the permission cache already and
  XenForo returns before querying - so a query-count assertion over those ids cannot notice code
  that stopped loading permissions at all
* docs: `TIPS.md` has what a query count in a test does and does not measure - the two queries the
  first link built in a test pays for, and the permission loading above
* docs: the testing tips are their own document, `TIPS.md`, and ship with the package. They were
  section 13 of the README, where they had drifted from the copy published alongside the resource
  page: the README's was missing the console-command assertion, the entity-double `get()` rule, the
  negative-assertion pairing and the unit-versus-feature split, and it still advised against
  writing feature tests, which the framework has supported since 5.15.0. The README's section 13
  links to it, and `README.md`'s documentation section now lists every published document
* docs: `renderTemplate()` says that a render on a forum shared with other add-ons raises their
  errors and writes them to `xf_error_log`, and points at `fakesErrors()` - the mechanism was
  documented from `assertNoTemplateErrors()`'s side only, which is not where a reader of
  `renderTemplate()` meets it
* docs: `assertNoTemplateErrors()` says that an add-on's own modification raises in other add-ons'
  suites rather than in its own, since under isolation its entity extension resolves - so a clean
  render is not evidence the modification is clean elsewhere. It gives the assertion that is true
  on a shared forum, that none of the logged messages is the add-on's own. It also notes that one
  template error reads as two logged messages whose text differs, so a count from
  `getLoggedMessages()` is not a count of errors and deduplicating them does not make it one

## 5.28.0 (2026-10-15)

* `assertAddOnClassExtensionsApply()` asserts that every class extension an add-on registers
  actually applies. One that does not is silent - XenForo's own class runs, the add-on's code in
  the extension never executes, and a suite with no test on the extended class has nothing to go
  red, so it passes identically with the extension present and absent. The list comes from the
  database, so it works on an install with no development output; a more derived resolution counts
  as applied, since another add-on extending the same class resolves to its own; and an add-on
  registering no active extension is refused rather than passing over an empty list. Each state
  that cannot be asserted is refused with its own cause: an id matching no installed add-on, an
  add-on disabled, an add-on part way through an install, and an add-on registering nothing.
  XenForo's extension cache filters on the add-on's own state as well as each row's, so a disabled
  or mid-upgrade add-on keeps active rows that nothing resolves - reading the rows alone would
  report every one of them as a failure on an add-on that is merely switched off
* docs: `assertAddOnClassExtensionsApply()` reads `xf_class_extension`, so it does not see an
  artifact in `_output/class_extensions/` that was never imported - the drift a commit without an
  import leaves. A test reading that directory catches what this one cannot, so the two are
  complementary and replacing such a test with this one trades a check away

## 5.27.0 (2026-10-15)

* `describeReply()` is protected rather than private, so a test can use it to see what a reply was.
  It renders each error phrase to its text, which `var_export()` and `print_r()` do not: an error
  reply's errors are `XF\Phrase` objects, and a phrase reaches the language, the database adapter
  and the adapter's configuration - so dumping one prints the database password and user in plain
  text, into the test output and from there into a build log. One phrase is enough
* docs: the reply assertions carry that hazard and the safe routes - `describeReply()`,
  `replyErrors()` and rendering - and note that the assertions compare text, so their own failures
  are safe to paste

## 5.26.0 (2026-10-14)

* `recordsLog($key)` records what an add-on logs through a PSR-3 logger of its own, instead of
  writing it. `fakesLogger()` covers XenForo's moderator log; this is for an add-on that puts a
  `Psr\Log\LoggerInterface` in the container under a key of its own, which nothing here could see
  into. `assertLogged()`, `assertNotLogged()` and `assertNothingLogged()` assert against it, with an
  optional level and either a string the message must contain or a closure given the whole entry;
  `getLogRecords()` and `getLogRecorder()` read it. An entry is `level`, `message` and `context`,
  with the message as the code passed it and its `{placeholder}` braces intact
* `createTempDirectory()` creates an empty directory for a test to write into and removes it when
  the test finishes, so a test needs no `tearDown()` of its own. The path is absolute and inside the
  system temporary directory, and the removal refuses any path that does not resolve inside there -
  so it cannot reach the forum. Reach for it rather than pairing `File::getTempDir()` with
  `File::deleteDirectory()`, which resolves a relative or empty path against the XenForo root
* `makeEntity()`, `createEntity()` and `createUserAccount()` refuse a value for a read-only or
  auto-increment column themselves, naming the `setTrusted()` call that sets it. Such a call already
  failed, with XenForo's own message naming `forceSet` - an option this framework does not surface
  and not the one to reach for
* docs: `UPGRADING.md`'s 5.0.0 entry says that listener filtering can take coverage away without
  anything failing. A suite with an optional integration with another add-on was testing the
  present branch by default before that release and the absent branch after it, so a workaround
  that forced the fallback becomes dead code that still passes and the present branch loses its
  only coverage - a green suite after upgrading is consistent with having stopped testing half of
  it. Adding the other add-on to `$addonsToLoad` is named as often unsafe, since booting one that
  vendors its own PHPUnit puts a second copy on XenForo's class loader
* docs: `setOption()` says that an option whose value is an array is replaced whole rather than
  merged, so naming two sub-options leaves the rest absent and the code under test fails on an
  undefined array key. `registrationDefaults` holds fifteen on a stock install
* docs: `fakesHttp()` recommends installing an empty queue in `setUp()`, so a request no test
  expected is refused with `Mock queue is empty` rather than reaching the real endpoint with
  whatever credentials the forum holds
* `assertNothingLoggedContains($needle)` asserts that no logged message contains the given text -
  for keeping a credential, a token or a user's data out of the log, where the per-message
  assertions need a message the test cannot predict. Errors and exceptions are both read, since an
  error is recorded as an `\ErrorException` entry. Pair it with an assertion that something was
  logged: a run that logged nothing contains the text either. An empty needle is refused
* `getLoggedMessages()` returns the message of everything logged, in order and with each caller's
  prefix, for a test that asserts on the set rather than on one entry
* `buildVisitor()`, and so `actingAsMember()` and the rest, accepts an array for a column XenForo
  stores as a string built from one - `secondary_group_ids`, which is `LIST_COMMA`. These values go
  in as stored column data, so the array used to reach the entity unencoded and the first read of
  the column was a `TypeError` inside XenForo's value formatter, naming neither the column nor the
  helper. The stored string is still accepted and is unchanged
* docs: `buildVisitor()` says its values go in as stored column data rather than through the
  entity's setters, which is what differs from `createUserAccount()`, and that nothing verifies a
  value on that path
* docs: `assertReplyParam()` says that only a **non-string** third argument is refused. A string
  cannot be told apart from a failure message and is taken as one, so
  `assertReplyParam($reply, 'key', 'a value')` asserts no more than that the key exists - the
  previous wording said any value there was refused
* docs: `dispatch()` says that a non-`GET` admin dispatch writes a real `xf_admin_log` row, from
  XenForo's own admin controller, for anything that is not a `GET` or a `HEAD` and does not answer
  with a `Reroute`. The row carries the route path and the input the test sent, of which only
  `_xfToken` and keys containing `password` are masked, and a class extension on the admin log
  repository runs with it. `UsesDatabaseTransactions` rolls the row back
* docs: `UsesDatabaseTransactions` says that a `tearDown()` which cleans up runs even when `setUp()`
  threw, so it has to check what it is about to act on - and that
  `XF\Util\File::deleteDirectory()` resolves a relative or empty path against the XenForo root, so
  an unset property reaches it as the forum root and deletes the installation. The guard to write
  instead is given
* docs: `createUserAccount()` says the account inherits the forum's `registrationDefaults` option -
  eight columns on the option record and five on the privacy one - so what a test can assert about
  a "normal" member follows the forum under test. Both ways to set what the test needs are given

## 5.25.1 (2026-10-14)

* `renderReply()` renders a reply whose template name already carries a type, rather than
  prepending the dispatch type and failing. XenForo's standard delete plugin answers with
  `public:delete_confirm` for an admin controller as well as a public one, because the control
  panel reuses that template - so an admin delete confirmation was refused with
  `Template name 'public:delete_confirm' contains invalid characters`, which named nothing about
  the type. A bare name still takes its type from the dispatch

## 5.25.0 (2026-10-13)

* `assertNoUnresolvedPhrases()` reports a phrase key that ends at the prefix it was given. Every
  key XenForo structures for an add-on puts the add-on's own id last - an option group's title is
  `option_group.<groupId>` - so a key is often the prefix and nothing more, and those were passing
  silently. A dotted segment in front of the prefix is now reported as part of the key, so the
  failure names a key that can be searched for rather than its tail
* docs: `assertNoUnresolvedPhrases()` lists the keys XenForo structures and says that the bare
  add-on prefix is the wrong argument for an admin option page. With development mode on, XenForo
  displays each option's id as ordinary text, so every option on the page is reported whether or
  not its phrase resolved - pass the keys instead. The ids are displayed only in development mode,
  so a bare prefix there passes on one install and fails on another
* `assertNoTemplateErrors()` takes a closure as its filter, given each error XenForo recorded. An
  add-on whose only markup is a template modification of a core template raises its errors while
  rendering a template somebody else owns - and so does a sibling add-on, so no template name tells
  them apart. A string filter still matches the template name and is never called, whether or not
  it happens to name a PHP function; anything that is neither a string, a list of strings nor a
  closure is refused
* docs: `fakesSimpleCache()` documents `getSimpleCache()`, and its example reads the fake back
  through that rather than out of the container - the accessor refuses when the fake was never
  installed, where the container hands back the forum's real cache. Both spellings of
  `assertSimpleCacheEqual()` and `assertSimpleCacheNotEqual()` are listed
* docs: the README's helper list names `assertNoUnresolvedPhrases()`, `renderRawReply()`,
  `renderJsonReply()`, `renderBbCode()` and `textOf()`, which were documented in `DOCS.md` and
  missing from the list a reader uses to decide what the package covers. Its
  `renderTemplateWithoutModification()` entry carries the way to pair it with a dispatch
* The refusals on the rendering assertions print the call they want. `assertSee()`,
  `assertDontSee()` and `assertSeeInOrder()` take the escaping flag third and the failure message
  fourth, while `assertSeeText()` and `assertDontSeeText()` take no flag at all - so a value in the
  wrong slot is answered with that assertion's own argument list rather than with a description of
  it
* docs: `assertConsoleCommandsLoad()` says what it returns - the classes XenForo would list as
  commands, which is fewer than the walk loaded. A file the runner skips, such as an abstract base
  beside the commands or a class that is not a Symfony `Command`, is loaded and left out of the
  list, so an assertion over the whole list counts commands rather than files
* docs: add-on isolation does not filter content type handlers. They come from the database, so
  every installed add-on's are registered whatever `$addonsToLoad` names, and an operation that
  iterates every content type reaches a handler whose class extension was not applied. It fails
  inside XenForo's own code with `Call to undefined method`, naming neither isolation nor the
  add-on - narrow the operation, or name the add-ons whose content types the test reaches
* docs: `$addonsToLoad` cannot add a class extension to a class already resolved in the process,
  as well as being unable to remove one. A test class naming more add-ons than its siblings gets
  those extensions only when it happens to run first, so the list belongs to the whole suite rather
  than to a test class
* docs: an id in `$addonsToLoad` that matches no installed add-on is ignored rather than refused,
  which is how to ask for no add-ons at all
* docs: `dispatch()` notes that a reply follows the forum's own configuration as well as the test's
  fixtures - a forum's `list_date_limit_days` keeps older threads out of its thread list, and
  `no_date_limit` in the input lifts it - and that a forum's canonical route carries a trailing
  slash
* docs: `fakesRegistry()` says what actually writes a registry row - core rebuilding one of its own
  caches, from a core entity's `_postSave()` or `_postDelete()` - so a suite can tell whether the
  recommendation applies to it, and why calling it anyway is still the recommendation. It also says
  that the fake intercepts writes without isolating reads, so an assertion that a registry key
  exists passes whether or not the code under test wrote it, and that nothing needs decaching for
  it to reach a consumer built earlier
* A test class whose `$addonsToLoad` would extend a class the run has already resolved is now
  refused in `setUp()`, naming the class and the extension that will not reach it. The resolution
  is cached for the whole process and the proxy XenForo aliases to reach an extension is a
  `class_alias()` PHP cannot declare twice, so such a class got those extensions only when it
  happened to run first and otherwise exercised XenForo's own class with the extension's methods
  missing. Declare one `$addonsToLoad` for the whole suite. Only widening is refused; a list that
  cannot *remove* an already-resolved extension is unchanged, and `requireClassNotExtended()`
  stays the guard for a test that asserts on that
* `followRedirect()` dispatches the route a redirect reply points at and returns the reply, so a
  test of a save can assert what the page the user lands on shows rather than stopping at the
  redirect. The route type is read from the url: the control panel has its own script, the api is
  routed through the public one behind a prefix, and link building honours the friendly-urls option
  for the public and api routers but not the admin one. One hop, as a `GET`; a url pointing at
  another host is refused
* docs: `renderTemplateWithoutModification()` says what still differs from a `renderReply()` of the
  same template - `$xf.reply`, which describes the page, comes from the reply and this renders
  without one. Compare against `renderTemplate()` where the template reads it
* `globalTemplateData()` hands back the `xf` template parameter a render would see, so a test can
  assert on what a `templater_global_data` listener of yours contributed. XenForo fires that event
  from inside `getGlobalTemplateData()`, which is why nothing else shows that the listener is
  registered with the class, method and signature XenForo calls. Passing a reply adds the `reply`
  entry describing the page. Calling XenForo's method directly instead answers `style => null`,
  which is the state a template reading `$xf.style` logs an error for
* `runConsoleCommand()`, `assertConsoleCommandLoads()` and `assertConsoleCommandsLoad()` refuse a
  command name carrying whitespace or braces. Symfony validates a command name against colons and
  nothing else, so a name with an argument placeholder written into it is accepted and the command
  runs - but `cmd.php list` prints the name as written, the command is reachable only as an
  abbreviation of itself, and a second command sharing that prefix makes both ambiguous. Declare
  arguments and options with `addArgument()` and `addOption()` instead

## 5.24.1 (2026-10-12)

* docs: `renderTemplateWithoutModification()` names the way to pair it with a dispatch - pass the
  reply's own parameters, which is what `renderReply()` renders the template with, so the two
  renders differ by the modification and nothing else
* docs: `createUserAccount()` says that saving a user is the most contended write this package
  makes and that a transaction does not make it reliable. Two tests creating users against one
  database can deadlock, naming `xf_user_group_relation`; the mechanism and the alternatives were
  already under `UsesDatabaseTransactions`, where a reader meets them only after the failure

## 5.24.0 (2026-10-12)

* rendered templates are given a style. `$xf.style` was null in every render, because only a real
  request sets one - so a core template calling a method on it logged an error and rendered nothing
  for that block, and a test could assert that markup was absent and be right for the wrong
  reason. Ten public and two admin templates read it on a stock install
* `assertNoUnresolvedPhrases()` searches the text a page displays rather than the whole markup. An
  add-on's prefix belongs in a form field's name, an id, a label's `for` and a CSS class, so a
  phrase that resolved perfectly well was reported as having failed - XenForo's resource standards
  require that prefix on a column added to a core table, and a field is named after its column. A
  phrase that failed to resolve inside an attribute is no longer seen; assert on that one directly
* `renderTemplateWithoutModification()` renders a template with one of its template modifications
  left out, so a test asserting on inserted markup can show the modification is what inserts it.
  Nothing is written and the modification is left enabled, so it is safe on an install other suites
  are running against. It refuses a modification that does not exist or is disabled
* `assertExceptionLogged()` and `assertExceptionNotLogged()` name their second parameter `$filter`
  rather than `$callback`, which described only one of the forms it accepts. The value and its
  forms are unchanged, and a positional call is unaffected; a call passing it by name must be
  updated
* `assertExceptionNotLogged()` is documented as taking a string the logged message must contain as
  well as a callback. Narrowing it denies only the exceptions matching the filter rather than all
  of that class, and a count is not accepted there - use `assertExceptionLoggedTimes()`
* docs: an empty `$permissions` given to `actingAs()` grants nothing rather than denying
  everything, so an account with a real permission combination - anything from
  `createUserAccount()`, or a user already in the forum - keeps what that forum grants it. A test
  asserting such a member is refused something needs `setVisitorPermissions($user, [])`
* docs: eleven core public controllers do not require `general.view`, account and login among
  them, so a dispatch that succeeds without granting it is not evidence that the next route will

## 5.23.1 (2026-10-12)

* docs: the upgrade note for 5.23.0's `REQUEST_METHOD` refusal names the three helpers it applies
  to, and no longer offers a search for the key as the way to find affected tests. Building an
  `XF\Http\Request` and swapping it into the container is unaffected, and there the key is how the
  method gets set - so the search finds tests that are correct and must be left alone, and the
  remedy it gave would have removed the method from them
* docs: `UsesDatabaseTransactions` names `xf_member_stat` as a second row a test that creates a
  user contends for, alongside `xf_user_group_relation`. Saving an `XF:User` empties the cached
  `staff_members` statistic and clears its expiry, which XenForo's own cron then rebuilds, and
  `fakesRegistry()` does not cover it. Includes the condition - the write happens for an ordinary
  member, not only for a member of staff - and how to mock the repository that performs it
* docs: `assertExceptionLogged()` states that the string form matches the prefix passed to
  `\XF::logException()` as well as the exception's own message, in the bullet describing that form
  and in the method's docblock. It was said only in the paragraph about the callback

## 5.23.0 (2026-10-12)

* a `REQUEST_METHOD` value in `$server` is refused rather than ignored, by `dispatch()`,
  `dispatchWithoutCsrfToken()` and `callAction()`. The request method comes from the `$method`
  argument, which also decides whether a CSRF token is minted, so a method supplied through
  `$server` could only have produced a request XenForo refuses - the value was discarded and the
  request sent with the method the argument named. A test asserting on a view could pass against a
  method it had not asked for
* `assertNoUnresolvedPhrases()` searches the page parameters the render set as well as the HTML.
  `<xf:title>`, `<xf:description>`, `<xf:h1>` and `<xf:pageaction>` compile to a write into the
  templater rather than to markup, so a phrase that came out as its own key in one of them was
  nowhere in the string the assertion was given. The failure names which surface each key came from
* `assertSee()`, `assertDontSee()`, `assertSeeText()`, `assertDontSeeText()` and
  `assertSeeInOrder()` refuse an empty value, and `assertSeeInOrder()` refuses an empty list. An
  empty value is found at position 0 of anything, so a positive assertion could not fail and a
  negative one could not pass
* `assertExceptionLogged()`'s `$callback` is documented as accepting a string, which it has since
  5.17.0. The parameter carried two docblocks, the older of which said otherwise
* every assertion in `InteractsWithTemplates` takes an optional failure message last, prefixed to
  what the assertion found rather than replacing it - `assertSee()`, `assertDontSee()`,
  `assertSeeText()`, `assertDontSeeText()`, `assertSeeInOrder()`, `assertNoUnresolvedPhrases()`,
  `assertNoTemplateErrors()` and `assertTemplateModificationApplied()`. The five output assertions
  took no message at all, and a third argument passed to `assertSeeText()` or `assertDontSeeText()`
  was discarded without a word
* a failure message written where `assertSee()`, `assertDontSee()` or `assertSeeInOrder()` take
  their `$escape` flag is refused, and a bool written where `assertSeeText()` or
  `assertDontSeeText()` take their message is refused. A non-empty string is merely truthy, so a
  message in the flag's place left the assertion behaving exactly as it does by default with the
  message gone
* a failure message given to `assertNoUnresolvedPhrases()` no longer replaces the list of
  unresolved keys, which is what says which phrases failed and on which surface
* docs: saving an `XF:User` row rewrites the user's rows in `xf_user_group_relation`, and the
  delete that precedes the insert locks the index gap above the last row for the rest of the
  transaction. Two tests creating users concurrently deadlock there on different user ids.
  `createEntity('XF:User')` reaches it as well as `createUserAccount()`, and `fakesRegistry()` does
  not cover it
* `buildDispatchRequest()`, `routeTypeConfig()`, `installGlobalTemplateData()` and `buildVisitor()`
  carry their own documentation again, having each been left describing the method below them
* docs: call `fakesRegistry()` from the `setUp()` of any class that creates or deletes content
  under `UsesDatabaseTransactions`, not only in a test about the registry
* docs: on XenForo 2.3.0 an entity keeps its cascaded saves after it is saved, so a parent a
  service created re-saves its children and setting a value on one of them throws `Attempted to set
  '<column>' while a save was pending without forceSet`. Re-fetch the parent or call
  `removeCascadedSave()`; `reset()` is not the remedy

## 5.22.0 (2026-10-11)

* a second `fakesHttp()` or `fakesHttpByUrl()` in the same test reaches the clients the first one
  covered. Each fake built its own handler stack, and a Guzzle client holds the stack it was built
  with - so a client an add-on's sub-container had already built, or the shared client a test kept
  in a variable, went on using the first fake's queue. The second fake installed responses nothing
  read, and the first queue, by then drained, answered `OutOfBoundsException: Mock queue is empty`
  from inside the code under test. The stack for each client kind is now built once per test and
  its handler replaced, which also means changing from `fakesHttp()` to `fakesHttpByUrl()` within a
  test takes effect everywhere
* docs: running the code under test twice in one test with the responses queued afresh, and the one
  case that still cannot work - a fake installed after the client was built with no fake in place

## 5.21.1 (2026-10-11)

* a failed `assertSee()`, `assertDontSee()`, `assertSeeText()` or `assertDontSeeText()` no longer
  prints the rendered markup. PHPUnit's own string assertions print the whole subject, so a miss
  against a page was thousands of lines of escaped HTML with the value it looked for at the end.
  The failure now names that value, the escaped form where escaping changed it, and the length
  rendered; `assertDontSee()` adds the offset of the first match and the text either side of it
* docs: markup a template sets through `<xf:pageaction>`, `<xf:title>`, `<xf:h1>` or
  `<xf:description>` is not in the rendered HTML, a template modification inside one of those tags
  included. `assertDontSee()` and `assertNoUnresolvedPhrases()` given the HTML therefore pass
  without checking it - read the value with `pageParam()` and assert on that
* docs: an id the code under test stores against the visitor resolves to whatever real account holds
  that id when a relation loads it back, since `actingAsMember()` builds a user in memory with
  `user_id` 1. Use `createUserAccount()` where the page reads the account back

## 5.21.0 (2026-10-10)

* `runRunnerStep()` runs one step of a XenForo multi-part runner - a clean-up, merge or
  content-change service - to completion, for testing an add-on that contributes work to a step
  without running every other step as well. A step's return value is a resume offset rather than a
  result, so it loops until the step reports it has finished
* the `UsesDatabaseTransactions` check for a transaction that did not survive stays quiet when the
  test has already failed, so a query that failed because the server rolled the transaction back is
  not reported as something having committed it
* the same check's message names both causes: something committed the transaction, or the server
  rolled it back
* docs: the data registry is the row a test is most likely to contend with the live forum for, and
  what to do about it

## 5.20.0 (2026-10-10)

* `assertNoTemplateErrors()` takes the prefix of the templates you own, or a list of them, so it can
  assert on your own templates on a development forum where a sibling add-on's template modification
  raises errors on every render. The filter matches the template name
* `assertNoUnresolvedPhrases()` accepts several prefixes, for an add-on whose older phrases predate
  its naming convention, and reports every unresolved key rather than failing on the first prefix
* docs: the filtering example under `assertNoTemplateErrors()` was a `TypeError` - each error XenForo
  records is an array, not a string
* docs: how to prove `assertNoUnresolvedPhrases()` can fail, and why a phrase-level mutation appears
  to do nothing
* docs: the README's install example requires `^5.20`

## 5.19.0 (2026-10-09)

* a link a controller builds during an `admin` or `api` `dispatch()` comes from that type's router,
  so the url of a redirect is an `admin.php` one and can be asserted. `XF\Mvc\Controller::buildLink()`
  asks for no type and XenForo hard-wires the untyped router to the public one, which a real
  `XF\Admin\App` overrides - so a redirect carried `index.php` and a route the public router does not
  know, and `assertReplyIsRedirect($reply, $url)` could not be given a true url
* `dispatch()` builds its request the way the forum serves that route type: `admin.php` for an admin
  route, the public script with an `api/` prefix for an api one, and the url form the *Use full
  friendly URLs* option selects. A dispatch on a forum with that option on was answered with a
  redirect to its own canonical url rather than the page - the board index was the clearest case
* `createUserAccount()` creates a member with the profile, option, privacy and authentication records
  a user has in practice. `createEntity('XF:User')` writes the `xf_user` row alone, which a XenForo
  page reading one of those relations cannot display
* `actingAsAdministrator()` is the three lines an admin test opens with: a member with `is_admin`, an
  administrator record with the given permissions, and ordinary permissions where they matter
* docs: `createEntity('XF:User')` builds a bare row, and what to use instead
* docs: build the url a redirect assertion expects with the router and match it as a substring - a
  redirect's url may be absolute or carry a fragment, and a public or api one changes shape with the
  *Use full friendly URLs* option, where an admin one does not

## 5.18.1 (2026-10-09)

* `assertConsoleCommandsLoad()` no longer fails on a file XenForo's own runner skips - an abstract
  base beside the commands, as core's own `Cli/Command` has, a trait or interface file, or a class
  that is not a `Command`. None of them is listed by XenForo or affects `cmd.php`, and none appears
  in the returned list. A class that cannot be **loaded** still fails, since that is the case that
  stops `cmd.php` for every add-on on the forum
* `assertConsoleCommandsLoad()` refuses when the files it finds declare nothing XenForo would list,
  so the skips cannot add up to an assertion over nothing
* docs: asserting the whole list of command classes, which also fails when one is added with no test

* docs: confirming a console command with `['inputs' => ...]` does the work against the forum the
  suite points at - a command built on XenForo's rebuild base class runs its job in its own process
  rather than queueing it, and a transaction is not a reliable guard

## 5.18.0 (2026-10-08)

* `runConsoleCommand()` takes `['inputs' => [...]]`, the answers to a command that asks questions, so
  the confirmed path of a command that confirms before acting is testable. Supplying answers with
  `['interactive' => false]` is refused, since Symfony would answer every question with its default
  and ignore them
* docs: `['interactive' => false]` answers every question with its default, which stops a command at
  its confirmation and writes nothing - the safe test for a destructive command
* docs: `assertNoTemplateErrors()` describes the forum rather than the add-on on an install shared
  with other add-ons, because template modifications are compiled into the shared template cache
  whatever `$addonsToLoad` says. Assert on the errors naming templates you own
* docs: a grep for a value passed where a reply assertion's failure message goes finds only the ones
  written as a literal; the refusal finds the rest
* docs: the README's install example requires `^5.18`

## 5.17.0 (2026-10-08)

* `assertConsoleCommandsLoad()` and `assertConsoleCommandLoads()` assert that the console command
  classes an add-on ships are ones XenForo can load. A class that cannot load takes `cmd.php` down
  for every add-on on the forum, since XenForo loads them all simply to list them, and nothing else
  in a suite loads them at all. The walk refuses when it finds nothing to check
* `assertExceptionLogged()` accepts a string the logged message must contain, as well as a count or
  a callback
* docs: the README's install example requires `^5.17`

## 5.16.0 (2026-10-08)

* `requireClassNotExtended($identifier)` skips a test unless the given class resolves unextended,
  for a test whose point is that an add-on's extension is absent
* docs: `$addonsToLoad` cannot remove a class extension already resolved in the process, because the
  XFCP proxy XenForo aliases cannot be undeclared - so such a test passes on its own and exercises
  the extension in a full run
* docs: upgrading from before 5.15.0 means checking an add-on's own testing notes as well as its
  tests - prose explaining why a write path needed `callAction()` does not fail the way an assertion
  does
* docs: the `league/flysystem` pin and the advisory it carries are explained where a contributor
  will meet them, including what would have to change for the pin to come off
* docs: the README's install example requires `^5.16`

## 5.15.0 (2026-10-08)

* a non-GET `dispatch()` carries a CSRF token the request has a matching cookie for, so an action
  behind XenForo's check is reachable with its `preDispatch()` guards intact. A write path no longer
  has to be tested through `callAction()`, which skips those guards
* `dispatchWithoutCsrfToken()` sends no token, for asserting that the check refuses. A token passed
  as `_xfToken` in the input is never replaced, so an invalid one can be tested too
* `assertReplyParamSame($reply, $key, $expected)` compares a reply parameter. `assertReplyParam()`
  has no expected-value argument - its third argument is a failure message, and a **string** passed
  there is taken as one, so the assertion passes whatever the parameter holds
* docs: a render resolves phrases from the database, so editing `_output/phrases/` proves nothing -
  change the phrase through its entity, before the render under test
* docs: `UPGRADING.md` names the command that lists every `POST` dispatch in a suite, since such a
  test changes meaning rather than failing
* docs: the README's install example requires `^5.15`

## 5.14.0 (2026-09-30)

* a reply assertion refuses a value where its failure message goes. `assertReplyParam($reply, $key,
  $expected)` has no expected-value argument, so the value was taken as the message and the assertion
  passed whatever the reply held. Read the value with `replyParam()` and assert on it instead
* `fakesMail()` can make sending fail: `$transport->failWith($exception)`, and
  `$transport->sendsSuccessfully()` to lift it. XenForo's mailer catches it, logs it and answers
  `false`, so pair it with `fakesErrors()`
* `mockFinder()` refuses a name XenForo cannot turn into a finder class, naming the name and the
  `Vendor:Name` form it wants, rather than failing from inside XenForo
* docs: `assertExceptionLogged()`'s callback is handed the logged entry array, not the throwable
* docs: build a mail *after* installing `fakesMail()` - one built before it sends through the forum's
  real transport while the fake captures nothing
* docs: a suite with no `$addonsToLoad` can die at boot where another add-on vendors a different
  PHPUnit, and the fatal is written to the forum's error log
* docs: the README's install example requires `^5.14`

## 5.13.1 (2026-09-30)

* `composer update` resolves again. Every `league/flysystem` release up to 3.35.2 carries a
  low-severity advisory and Composer blocks an affected package by default, so nothing that needs
  flysystem 1.x - which is what XenForo supplies, and what `swapFs()` needs - could be installed.
  `composer.json` now accepts that one advisory by id, with the reason recorded where
  `composer audit` prints it
* the `league/flysystem` dev floor is `^1.1.4`, above the range of the critical advisory that *is*
  patched, so that one stays enforced rather than ignored
* `runConsoleCommand()`'s type error names what the argument resolved to rather than the argument
* docs: what to put in your own `composer.json` to install `league/flysystem-memory` for `swapFs()`

## 5.13.0 (2026-09-30)

* `callAction()` refuses a controller named by an add-on's extension of it, and names the class to
  pass instead. Naming the extension worked only when something earlier in the run had resolved the
  class being extended, so the same call passed in a full suite and failed under `--filter` with
  `Class "...\XFCP_..." not found`
* `runConsoleCommand()` refuses a command name where a class name is wanted, rather than failing with
  `Class "my-addon:do-thing" not found`
* the shipped `phpunit.xml` no longer sets `failOnPhpunitDeprecation`, which PHPUnit 10.5 does not
  have - with it, the configuration fails validation and `failOnWarning` then fails every run on the
  oldest supported version. **Re-copy `phpunit.xml`**
* the `phpunit/phpunit` floor is `^10.5`, and the `nesbot/carbon` dev floor `^2.50`. The shipped
  `phpunit.xml` sets `failOnDeprecation` and `failOnNotice`, which PHPUnit 10.0 to 10.4 do not have,
  and Carbon 2.0 cannot load on PHP 8.3, so neither old floor was usable
* docs: name the controller your add-on extends, not your extension of it
* docs: `$db->emptyTable()` issues a `TRUNCATE`, so it commits a test's transaction
* docs: the README's install example requires `^5.13`

## 5.12.0 (2026-09-30)

* `UsesDatabaseTransactions` also fails the test when the code under test ended the transaction
  rather than committing it. `rollbackAll()` and `commit()` both do, and XenForo calls `rollbackAll()`
  on its own error paths - the job manager when a job throws - after which everything written,
  including what XenForo writes itself, is permanent. The commit check could not see this, because
  nothing had been committed
* docs: what that means for a test that runs a failing job through the job manager
* docs: the README's install example requires `^5.12`

## 5.11.0 (2026-09-29)

* a class extension added during a test is forgotten when that test finishes, so a later test
  resolving the same class gets the base class again. The cache outlives the application, so such an
  extension previously applied for the rest of the run and which tests failed depended on their order
* extending the same class a second time in a run is refused whether or not the cache still holds it,
  since the proxy XenForo aliases cannot be declared twice in one process
* docs: the README's install example requires `^5.11`

## 5.10.0 (2026-09-29)

* the extension cache is keyed by the class XenForo will actually extend, rather than by the spelling
  it was asked for. A class reached by more than one name - with a leading backslash, or under the
  name XenForo 2.3 renamed it to - was extended once per spelling, and the second attempt failed with
  `Cannot declare class ... XFCP_... because the name is already in use`. It surfaced in a full run
  only, since each test builds its own extension while the cache is shared
* `Extension::addClassExtension()` evicts the cached resolution for that class, so an extension added
  during a test applies even when an earlier test resolved the class unextended. Adding one to a class
  already extended in the same run throws, because the proxy PHP needs cannot be declared twice
* docs: a data provider runs before XenForo boots, so a class constant from XenForo or its vendor tree
  is not available in one
* new `runConsoleCommand()` runs a console command against the booted application and returns
  Symfony's `CommandTester`, for asserting on its output and exit code
* `setStaticProperty()` restores the original value when the test finishes, as the other `set*`
  helpers do. A static outlives the application, so a value set in one test was read by every later
  one
* docs: the README's install example requires `^5.10`

* docs: for an endpoint that opts out of `preDispatch()`'s checks, the response code says which layer
  refused - 405 from the action's own `assertPostOnly()` means the checks let the request through,
  where 403 is one of the checks refusing it

## 5.9.0 (2026-09-27)

* `dispatch()` takes a request method as its fifth argument, defaulting to `GET`. For a controller
  that deliberately opts out of the CSRF check - a server-to-server endpoint overriding
  `checkCsrfIfNeeded()` - this is the only way to reach its access checks, since `callAction()`
  skips `preDispatch()` entirely
* new `renderJsonReply()` renders a reply through the JSON renderer and returns the decoded
  document, for a view that shapes its own response in `renderJson()`. It refuses a view that cannot
  answer JSON rather than returning XenForo's own well-formed but empty document
* docs: an entity double takes its expectations on `get()`, since `Entity::__get()` delegates to it
  and an expectation on the magic method never applies; array access needs its own expectation
* docs: the isolated add-on's own code event listeners do run, including while the container builds
  something the test is not otherwise interested in
* docs: the README's install example requires `^5.9`

## 5.8.0 (2026-09-26)

* `dispatch('')` reaches the board index instead of returning a permanent redirect to it. The
  request uri carried a trailing `?`, which does not match the canonical url XenForo compares it
  against. An empty route with input no longer gets a stray `&` either
* `dispatch()` and `callAction()` put one of XenForo's own application classes in `\XF::app()` for
  the type being dispatched, sharing the test application's container, and fire that type's setup
  event - `app_pub_setup`, `app_admin_setup` or `app_api_setup` - once per test. A container key an
  add-on registers there now exists, and a listener gated on `$app instanceof \XF\Pub\App` runs.
  The new `setAppClassType()` does the same before a render that needs it
* `makeEntity()` throws when a value it was given did not land. XenForo verifies many columns as
  they are set - a unique key already in use, an invalid email - and records a failure rather than
  throwing, which left the entity quietly missing the value. `setTrusted()` sets one anyway
* docs: the README's install example requires `^5.8`

## 5.7.0 (2026-09-26)

* new `renderBbCode()` renders BB code and returns the HTML, for asserting on part of the output
  where `assertBbCode()`'s exact comparison cannot be used
* new `mockFind()` decides what `\XF::em()->find()` returns for one id, stubbing the finder calls
  it makes and clearing the entity cache it reads first
* docs: `assertBbCode()`'s example expected unwrapped output - `html` and `emailHtml` wrap in
  `<div class="bbWrapper">`, `simpleHtml` does not - and it compares exactly, so a tag rendering
  through a template is better rendered and asserted on with `assertSee()`
* docs: the `swap()` example called the value the container returns, which is already the object
* docs: `\XF::em()->find()` goes through a finder, so it needs `mockFinder()` rather than
  `mockEntity()`, and it consults the entity cache first
* docs: `assertNoTemplateErrors()` covers a BB code render, which uses the same templater
* the scaffold's `$addonsToLoad` comment says to name your own addon, and what an empty value costs
  on a forum where another addon vendors its own PHPUnit
* docs: the README's `build.json` examples no longer run `composer install` without a
  `--working-dir`, which stripped the dev dependencies from the addon you are developing in; they
  remove `.phpunit.cache`, which otherwise ships inside the release; and they use `rm -fv`
* docs: an `AUTO_INCREMENT` counter is not rolled back with the transaction
* docs: `setTrusted()` must not be used on the id of a visitor built by `actingAs()`
* docs: a route requiring registration refuses a guest with the `login` view and a 403, not an error
* docs: a test needing a real table cannot use `UsesDatabaseTransactions`, with the pattern to use
* docs: raising `config.platform.php` to install this stops Composer guarding your runtime
  dependencies' own PHP floor, and an addon's dev dependencies are visible to other addons' suites
* docs: the README's install example requires `^5.7`

* `UsesDatabaseTransactions` now fails the test when something has committed its transaction,
  rather than rolling back nothing and leaving every row the test wrote in the database. MySQL
  commits implicitly on any DDL, which includes the `TRUNCATE` XenForo runs when it compiles a
  template - as it does on a development install for any template whose `_output/` copy has not
  been imported
* `dispatch()`, `callAction()` and `runJobToCompletion()` no longer write XenForo's `autoJobRun`
  registry row when they run deferred work. Under `UsesDatabaseTransactions` on MariaDB 11.8 or
  later that write could fail with `Record has changed since last read`, on some runs and not
  others, when the forum's own traffic or cron had touched the row
* docs: `renderRawReply()` on a view that streams its output returns an `XF\Http\ResponseStream`,
  read with `getContents()`
* docs: `UsesDatabaseTransactions` puts the test in a race for any row the live forum also writes

## 5.6.0 (2026-09-23)

* `callAction()` takes an optional `$files` argument, so an action reading
  `$this->request->getFile()` can be tested with a file. Build the entry with the new
  `uploadedFile()` or `uploadedFileFromPath()`, which write a real temporary file and remove it
  when the test finishes
* new `assertNoUnresolvedPhrases()` asserts that no phrase rendered as its own key, ignoring the
  template names the templater embeds for an administrator. Named so it does not collide with an
  `assertPhrasesResolved()` of your own
* new `renderRawReply()` renders a reply through the raw renderer and returns the response, with
  its body, for an action whose view builds its output in `renderRaw()`
* docs: `callAction()`'s action argument is the controller's own action - a route's `action_prefix`
  is not applied
* docs: `UsesDatabaseTransactions` cannot roll back a file, including what an entity compiles into
  the code cache when it saves
* docs: `getHttpRequests()` and `getHttpHistory()` read the requests a test sent, and the history
  is kept for the whole test rather than reset by a second `fakesHttp()`
* docs: the README's install example requires `^5.6`

## 5.5.0 (2026-09-23)

* `fakesHttp()` and `fakesHttpByUrl()` now also fake clients built with
  `$app->http()->createClient()`, which an add-on uses when it needs its own `base_uri`, headers or
  timeouts. Previously only the shared `client` and `clientUntrusted` were faked, so those requests
  were sent for real. A client created before the fake is installed still uses the real handler
* the fake reaches those clients while `fakesEvents()` is active as well
* docs: on a development install, a template with an `_output/` copy renders from that copy, and a
  template changed only in the database is replaced before it renders. Template modifications still
  need `xf-dev:import`
* docs: the README's install example requires `^5.5`

## 5.4.0 (2026-09-22)

* `renderTemplate()`, `renderReply()` and `renderMacro()` set the `xf` template parameter as XenForo
  does for a page, from `getGlobalTemplateData()`, so `$xf.options`, `$xf.visitor` and the rest are
  available to the template. It is rebuilt for every render, and an `xf` parameter the test set
  itself is left alone
* a visitor a test set with `\XF::setVisitor()` is cleared after the test, rather than carried
  into the next one
* docs: the README's install example requires `^5.4`

## 5.3.0 (2026-09-22)

* new `runJobToCompletion()` runs a job until it completes and returns its final `JobResult`. Each
  pass is a new instance built from the previous pass's data, and work queued with
  `\XF::runOnce()` runs after each pass. A failed result, a job that does not complete within
  `maxPasses`, and a job that does not exist throw a `LogicException`
* docs: `callAction()` accepts an action name containing slashes
* docs: `UsesDatabaseTransactions` rolls back a nested commit even when the code then throws
* docs: the README's install example requires `^5.3`

## 5.2.0 (2026-09-22)

* `dispatch()` and `callAction()` take an optional `$server` argument - request server values such
  as `REMOTE_ADDR`, `HTTP_USER_AGENT` and `HTTP_REFERER`, merged over the defaults. `REQUEST_METHOD`
  cannot be changed this way
* docs: on XenForo 2.3 `Request::getFromSearch()` always returns an empty string

## 5.1.0 (2026-09-21)

* new `callAction()` calls a controller action directly with a `POST` request and returns its
  reply, for testing saves, toggles and deletes. It skips `preDispatch()`, so neither the CSRF
  check nor the controller's permission check runs - use `dispatch()` to test those. A validation
  failure comes back as an `Error` reply with its errors keyed by field, a reply thrown as
  `XF\Mvc\Reply\Exception` comes back as that reply, a `Reroute` is followed, and work queued with
  `\XF::runOnce()` has run before the reply is returned
* docs: the README describes running each test class on its own to find a test extending plain
  `PHPUnit\Framework\TestCase` that depends on an earlier test having booted XenForo

## 5.0.0 (2026-09-21)

* the framework now boots the application: `Hampel\Testing\TestCase::createApplication()` reads
  `$rootDir` and `$addonsToLoad` from your test class, and `tests/CreatesApplication.php` is no
  longer part of the scaffold. An existing copy keeps working. To use the framework's boot, delete
  that file and the `use CreatesApplication;` line in `tests/TestCase.php`. To keep a boot of your
  own, override `createApplication()` and pass `['xf-addons' => $this->addonsToLoad]` to
  `XF::setupApp()`
* `$rootDir` and `$addonsToLoad` are declared on `Hampel\Testing\TestCase`, with the scaffold's
  defaults
* fix: add-on isolation now filters code event listeners. Previously every installed add-on's
  `app_setup` listener ran regardless of `$addonsToLoad`
* fix: with `$addonsToLoad` set, a class extension registered on a pre-2.3 class name -
  `XF\Service\User\Login` rather than `LoginService` - was not applied, and XenForo's own class ran
  instead. Also fixed in 4.3.2 and 3.0.8
* `TestCase` throws a `LogicException` when `$addonsToLoad` is set but the application was booted
  without it, as happens with a `tests/CreatesApplication.php` older than 2.1.0
* new `Hampel\Testing\App::isolatedAddOnIds()` returns the add-on ids the application was booted
  with
* new `Hampel\Testing\Extension::forAddOns()` builds an extension carrying only the given add-ons'
  listeners and class extensions
* the `Concerns\InteractsWithExtension` trait has been removed; the application installs the
  filtered extension while it boots
* `mockDatabase()` no longer requires the mock's `fetchAll` to return an array
* docs: a template error is written to the real `xf_error_log`; `fakesErrors()` or
  `UsesDatabaseTransactions` prevents it
* docs: a test extending plain `PHPUnit\Framework\TestCase` cannot load add-on classes unless an
  earlier test has booted XenForo

**Breaking changes:**
* with `$addonsToLoad` set, listeners belonging to add-ons not named there no longer run. A test
  that relied on one - a container entry it registered, an option it set, a class it extended -
  needs that add-on added to `$addonsToLoad`
* a suite that sets `$addonsToLoad` with a `tests/CreatesApplication.php` older than 2.1.0 now fails
  with a `LogicException`. Delete that file and its `use` line
* `Concerns\InteractsWithExtension` has been removed. Remove any `use` of it from your own test
  classes
* where the application is booted with a different add-on list than `$addonsToLoad`, the list the
  application was given decides which listeners and class extensions are active

## 4.3.2 (2026-09-21)

* fix: with `$addonsToLoad` set, a class extension registered on a pre-2.3 class name -
  `XF\Service\User\Login` rather than `LoginService` - was not applied, and XenForo's own class ran
  instead

## 4.3.1 (2026-09-20)

Documentation only.

* docs: `spy()` is documented
* docs: the README states that add-on isolation does not filter code event listeners
* docs: upgrade notes have moved from the README to a new `UPGRADING.md`
* docs: the notes on `failOnRisky` and `failOnDeprecation` have moved into the README's installation
  section
* docs: the README's note on `app.classType` is corrected
* docs: the README's "Compatibility" heading is spelled correctly
* the 3.0.7 entry has been added to this file

## 4.3.0 (2026-09-20)

* fix: `dispatch()` now runs work queued with `\XF::runOnce()` during the dispatch, and rethrows any
  exception it raises
* fix: work left in `\XF::$runOnce` by one test is discarded in teardown rather than running in the
  next
* fix: `renderTemplate()` throws when a template renders nothing because it failed - a PHP error, a
  missing macro or included template, or an exception, including one XenForo renders as markup when
  `$config['debug']` is set
* a render that raised an error but still produced markup is returned as normal. New
  `assertNoTemplateErrors()` asserts that no template the test rendered raised an error
* new `renderMacro()` renders a single macro from a template, and throws for a macro that does not
  exist
* new `pageParam()` reads a value a rendered template set with `<xf:title>`, `<xf:description>`,
  `<xf:h1>` or `<xf:pageaction>`
* `assertReplyIsRedirect()` takes a `type`, `permanent` or `temporary`. A redirect reply's
  `getResponseCode()` is always `200`, so assert the type rather than the code
* every reply assertion takes an optional failure `message` as its last argument
* `assertReplyIsError()`'s third parameter is renamed `$errorText`. A call passing it by name must
  change
* `mockery/mockery` is now `^1.6`
* docs: the `fakesJobs()` example is corrected, and `assertJobQueued()` documents that it matches
  the class string exactly as queued - `'XF:FileCleanUp'` and `\XF\Job\FileCleanUp::class` do not
  match each other
* docs: a public route needs the visitor to hold `general.view`
* docs: code that checks for `XF\Pub\App` does not run under the framework, which boots the base
  `XF\App`
* docs: a class extending a XenForo class cannot be declared at test file scope

## 4.2.0 (2026-09-19)

* new `renderTemplate()` renders a template to HTML, and `renderReply()` renders the template a
  dispatched reply named, with `assertSee()`, `assertDontSee()`, `assertSeeText()`,
  `assertDontSeeText()`, `assertSeeInOrder()` and `textOf()` to assert on it. Expected values are
  escaped by default, matching `XF::escapeString()`
* `renderTemplate()` requires the `type:title` form, and throws for a template that does not exist
* new `assertTemplateModificationApplied()` asserts that a template modification has applied, using
  its apply count
* rendering covers the template, not the page wrapper around it

## 4.1.0 (2026-09-18)

* new `dispatch()` runs a route through XenForo's dispatcher and returns the reply, with
  `assertReplyIsView()`, `assertReplyTemplate()`, `assertReplyViewClass()`, `assertReplyParam()`,
  `replyParam()`, `assertReplyIsRedirect()`, `assertReplyIsError()`, `assertReplyIsMessage()`,
  `assertReplyIsApiResult()`, `replyApiResult()` and `replyErrors()` to assert on it. Route
  parameters are passed as the third argument. Public, admin and api routes are supported, reroutes
  are followed, and the controller's `preDispatch()` checks run
* new `setVisitorAdminPermissions()` grants admin permissions to a built user
* new `actingAsApiKey()` runs api dispatches as a given api key, and restores `\XF::$apiKey`
  afterwards
* `dispatch()` returns the reply without rendering it
* `dispatch()` sends a `GET`; an action requiring `POST` returns a 405
* docs: `DOCS.md` no longer says that code calling `save()` cannot be tested - use
  `UsesDatabaseTransactions`

## 4.0.3 (2026-09-17)

* fix: a user built by `buildVisitor()`, `actingAsMember()` or `actingAsGuest()` could inherit an
  administrator record from the forum the tests run against. `hasAdminPermission()` is now always
  false for a built user; pass a user you loaded yourself to `actingAs()` for a real administrator

## 4.0.2 (2026-09-10)

* fix: under `fakesHttp()` and `fakesHttpByUrl()`, `$response->getBody()->getContents()` returned an
  empty string

## 4.0.1 (2026-09-05)

* docs: `isolateAddon` has been removed from the README's helper list - it was removed in 2.1.0; use
  `$addonsToLoad`
* docs: the README's helper lists include the helpers added in 4.0.0
* docs: the README notes that `swapFs()` needs `league/flysystem-memory`
* the scaffold ships `tests/Feature/ExampleTest.php` in place of `tests/Feature/.gitkeep`
* the 3.0.4 and 3.0.5 entries have been added to this file

## 4.0.0 (2026-09-05)

* new `makeEntity()` and `createEntity()` helpers for building entities with given values
* new `fakesHttpByUrl()` - chooses the http response by request URL rather than by call order
* new `fakesEvents()` - records code events and stops them reaching listeners, with
  `assertEventFired()`, `assertEventFiredTimes()`, `assertEventNotFired()` and
  `assertNoEventsFired()`
* new `actingAs()`, `actingAsMember()` and `actingAsGuest()` helpers - run a test as a given user,
  with permissions granted in memory rather than read from the database
* new `setVisitorPermissions()`, `setVisitorContentPermissions()` and `buildVisitor()` helpers
* new `setConfig()` helper - sets a `config.php` value
* new `UsesDatabaseTransactions` trait - wraps each test in a transaction and rolls it back
* new `assertDatabaseHas()`, `assertDatabaseMissing()` and `assertDatabaseCount()` assertions
* PHPUnit 11 and 12 are now supported
* fix: `fakesRegistry()` failed with a fatal error on XenForo 2.3
* fix: mail assertions failed with a `TypeError` once any mail had been sent
* fix: `assertJobQueued()` with a count called a method that does not exist
* fix: `assertExceptionLogged()`, `assertActionLogged()` and `assertChangeLogged()` with a count
  asserted against the wrong value
* fix: `Job\Manager::runByIds()` returned null where an array was documented
* fix: `DataRegistry::delete()` failed when the registry had a cache
* fix: `fakesHttpByUrl()` ignored Guzzle's `sink` option
* fix: a second `fakesHttp()` or `fakesHttpByUrl()` call in one test had no effect
* fix: every user built by `buildVisitor()` shared the forum's guest permission combination. Each
  now gets its own; pass `permission_combination_id` yourself to choose one
* fix: `fakesEvents()` recorded event arguments by reference; they are now recorded as they were
  when the event fired
* fix: `fakesMail()` did not capture mail sent with `queue()`
* fix: `swapFs()` and `mockFs()` returned the real filesystem adapter if the filesystem had already
  been resolved
* fix: `mockRepository()` did not apply when the repository was named in a different spelling from
  the one it was mocked under
* fix: `mockService()` for a class that does not exist returned an untyped double; it now throws a
  `LogicException`, and the mock is typed as the class XenForo would build
* fix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* `Job\Manager::runQueue()`, `runUnique()` and `runJobEntry()` return `null` explicitly
* `swapFs()`, `mockFs()`, `fakesRegistry()` and `buildVisitor()` throw a `LogicException` when
  XenForo returns an object of an unexpected type
* `app()` throws a `LogicException` when called before the application has booted
* `Error::logException()` accepts a non-throwable, as XenForo's own signature does
* docblocks corrected for `_enqueue()`, `setOptions()`, `swap()` and `app()`
* `assertErrorLogged()` and `assertErrorNotLogged()` no longer require a message
* error and exception handlers are restored after each test
* `phpunit.xml` fails the suite on deprecations, notices, warnings, risky tests, PHPUnit
  deprecations (`failOnPhpunitDeprecation`) and a run that executes no tests
  (`failOnEmptyTestSuite`)
* the `tests/Feature` directory is included, with a `.gitkeep`; commit it with your tests
* the `fakes*` helpers and `swapFs()` return the object they document
* code style is now XenForo's own, applied with `xenforo-ltd/xf-cs-fixer`
* `league/flysystem-memory` 2.0 and above are rejected

**Breaking changes:**
* minimum PHP version is now 8.3. If your addon pins `config.platform.php` below 8.3, Composer
  cannot install this package. Raising the pin can also let Composer select runtime dependencies
  above your addon's own PHP floor, so check `composer.lock`'s `packages` afterwards. The 3.x line
  remains available for PHP 8.1 and 8.2
* PHPUnit 12 is now allowed. PHPUnit 12 ignores doc-comment metadata, so tests using
  `@dataProvider`, `@depends`, `@covers` or `@group` must convert them to attributes, or pin
  `phpunit/phpunit` yourself
* `makeEntity()` and `createEntity()` are new protected methods on `TestCase`. If your test classes
  define a method of either name, rename yours. `createEntity()` saves and `makeEntity()` does not
* `phpunit.xml` has changed and should be re-copied into your addon, or diffed against yours
* the files in `tests/` have been restyled, so a diff against your copies shows formatting changes
  too

## 3.0.8 (2026-09-21)

Backported from 4.3.2.

* fix: with `$addonsToLoad` set, a class extension registered on a pre-2.3 class name -
  `XF\Service\User\Login` rather than `LoginService` - was not applied, and XenForo's own class ran
  instead

## 3.0.7 (2026-09-20)

Backported from 4.3.0.

* `mockery/mockery` is now `^1.6`

## 3.0.6 (2026-09-10)

Backported from 4.0.2.

* fix: under `fakesHttp()`, `$response->getBody()->getContents()` returned an empty string

## 3.0.5 (2026-09-04)

Documentation and packaging only.

* the `tests/Feature` directory is now shipped, with a `.gitkeep`. Without it, PHPUnit stops with
  `Test directory "..." not found` and runs nothing
* docs: the README's install command uses `cp -r`
* docs: the README's `fakesMail()` example is updated for Symfony Mailer
* docs: the README's `phpunit.xml` example is updated for PHPUnit 10
* docs: `isolateAddon` has been removed from the README's helper list - use `$addonsToLoad`
* docs: `swapFs()` needs `league/flysystem-memory` in your own `require-dev`
* docs: the source and issue links point at GitHub

## 3.0.4 (2026-09-04)

A maintenance release of the v3 line for PHP 8.1 and 8.2. The same fixes are in 4.0.0.

* fix: `fakesRegistry()` failed with a fatal error on XenForo 2.3
* fix: mail assertions failed with a `TypeError` once any mail had been sent, and only the last mail
  was kept
* fix: `fakesMail()` did not capture mail sent with `queue()`
* fix: `assertJobQueued()` with a count called a method that does not exist
* fix: `assertExceptionLogged()`, `assertActionLogged()` and `assertChangeLogged()` with a count
  asserted against the wrong value
* fix: `swapFs()` and `mockFs()` returned the real filesystem adapter if the filesystem had already
  been resolved
* fix: a second `fakesHttp()` call in one test had no effect
* fix: `mockRepository()` did not apply when the repository was named in a different spelling from
  the one it was mocked under
* fix: `mockService()` for a class that does not exist returned an untyped double; it now throws a
  `LogicException`
* fix: the `fakes*` helpers and `swapFs()` returned a closure rather than the object they document
* fix: `Job\Manager::runByIds()` returned null where an array was documented
* fix: parameters are explicitly nullable, removing deprecation notices on PHP 8.4
* docs: `DOCS.md` no longer lists the `assertMailQueued*()` assertions removed in 3.0.0, and the
  `fakesMail()` example is corrected

## 3.0.3 (2024-12-30)

* bugfix: some code related to mail queueing has been removed since we no longer use it 

## 3.0.2 (2024-08-11)

* new expanded extension class - use global static maps to keep track of extensions and aliases between test runs

## 3.0.1 (2024-08-10)

* for some reason our extension class wasn't working correctly - we'll just remove it

## 3.0.0 (2024-08-10)

* compatibilty with XenForo v2.3
* Hampel\Testing\Job\Manager updated to match changes in XF\Job\Manager, particularly XF\Job\JobParams
* Hampel\Testing\Mail\Transport replaced with Hampel\Testing\Mail\TestTransport which implements Symfony mail transport
* Hampel\Testing\Mail\Queue removed - we now simply disable queueing which results in all mails being sent via the test
  transport

## 2.2.0 (2024-07-10)

* php 8.3 compatibility fix - ReflectionProperty::setValue with a single parameter is now deprecated; but as of php 8.1 
  we can simply use ReflectionClass::setStaticValue without needing to explicitly set private or protected properties 
  as accessible
* we now need to use a minimum of php 8.1
* upgrade to PHPUnit v10.x

## 2.1.0 (2024-03-14)

* allow swapping subcontainer keys using either a class or a string to define the app container key
* new option in TestCase - $addonsToLoad
* new implementation of addon isolation limiting composer autoload and extension/listener loading based on which addons 
  are specified in TestCase

**Breaking changes:**
* isolateAddon function has been removed and replaced by an option in `TestCase.php`
* both `TestCase.php` and `CreatesApplication.php` will need to be updated in addons based on the new versions in this 
  package

## 2.0.2 (2020-09-23)

* should be returning the instance we created when swapping or faking classes

## 2.0.1 (2020-09-15)

* Job Manager - getUniqueJob wasn't returning the job
* don't serialize job paramaters

## 2.0.0 (2020-08-28)

* compatibility changes for XenForo v2.2
* XF 2.2 implements Swiftmailer 6 which changes some method/interface signatures

## 1.2.2 (2020-08-04)

* fixed missing use clause in `Hampel\Testing\Concerns\InteractsWithFilesystem` trait

## 1.2.1 (2020-07-25)

* fixed typo in function name: `Hampel\Testing\Concerns\InteractsWithSimpleCache::assertSimpleCacheEqual()` => 
`assertSimpleCacheEquals()` and `assertSimpleCacheNotEqual()` => `assertSimpleCacheNotEquals()`
* close the database connection on tearDown to avoid connection limit issues (unless it's been mocked)

## 1.2.0 (2019-12-13)

 * Feature: added new functionality to Interacts with Container
   * mockService
 * Feature: Interacts with Http - adds:
   * fakesHttp  

## 1.1.0 (2019-11-26)

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

## 1.0.0 (2019-11-19)

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
