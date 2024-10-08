# XenForo Addon Unit Test Framework

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/xenforo-test-framework.svg?style=flat-square)](https://packagist.org/packages/hampel/xenforo-test-framework)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/xenforo-test-framework.svg?style=flat-square)](https://packagist.org/packages/hampel/xenforo-test-framework)
[![Open Issues](https://img.shields.io/bitbucket/issues/hampel/xenforo-test-framework.svg?style=flat-square)](https://bitbucket.org/hampel/xenforo-test-framework/issues)
[![License](https://img.shields.io/packagist/l/hampel/xenforo-test-framework.svg?style=flat-square)](https://packagist.org/packages/hampel/xenforo-test-framework)

Unit testing framework for XenForo

## Compatibilty

The test framework is specific to the version of XenForo being run. Given this is a development tool, simply install the
appropriate version of the test framework in your addon based on what version of XenForo you are developing on.

|XenForo|Unit Test Framework|
|-------|-------------------|
|v2.1   |v1.x               |
|v2.2   |v2.x               |
|v2.3   |v3.x               |

## Upgrading

**Unit Test Framework v2.1**

The `TestCase.php` and `CreatesApplication.php` files have been updated in v2.1 of the unit test framework and you 
should edit these files in your addon unit test directory to merge in these changes.

Specifically, there is a new variable in `TestCase.php`:

```php
protected $addonsToLoad = [];
```

... and some new code in `CreatesApplication.php` which should be copied across to your own version of this file:

```php
$options['xf-addons'] = $this->addonsToLoad ?: [];
```

## 1. Introduction

Unit testing is a process by which individual components (units) of your software are tested. The objective is to 
isolate a section of code and validate its correctness - ensure that it behaves as expected for the given inputs.

There are multiple levels of testing typically used in software development, including Unit testing, Integration 
testing, System testing, Acceptance testing and so on. Unit testing is generally the lowest level of testing performed 
and the goal is to test a small section of code in isolation. Each test should run independently and should have no 
side effects on future tests. Ideally, unit tests should cover all potential code paths through the code being tested 
and verify that invalid inputs cause the expected failures.

Unit testing is typically executed using tools designed to run a suite of tests, reporting back to the developer on the 
success or failure of each test. The most popular and widely unit testing framework for PHP is 
[PHPUnit](https://phpunit.de/). Learning how to use PHPUnit when developing PHP applications will make your code more 
robust, help you identify issues early on in the development lifecycle and generally make your code cleaner and more 
maintainable.

The most useful thing I've found with unit testing - other than the obvious debugging process - is when making major 
changes to your codebase, such as refactoring or upgrading to a new version of a framework or library being used. 
Having a comprehensive set of unit tests working before you begin, allows you to quickly identify code that no longer 
works the way it used to or should.

There are lots of tutorials on how to use PHPUnit and unit testing in general - I'm not going to cover that here. The 
purpose of this tutorial is to explain the theory and process behind unit testing XenForo addons.

Testing within a monolithic application framework such as XenForo can be problematic - especially given that we are not 
building stand-alone applications, but instead we are extending or adding functionality to an existing application.

One of the most important functions of unit testing is isolating your code from the surrounding framework and swapping 
out other code with test stubs, mock objects, fakes and test harnesses - which allow you to inject expected behaviours 
or responses from external code for testing purposes and avoiding side effects.

Fortunately, XenForo v2 has been architected in a way that makes it much easier for us to isolate parts of the 
framework and inject mock objects.

## 2. XenForo Application Container

One of the most significant architectural decisions made by the XenForo developers when designing XenForo v2 was to 
implement an application container pattern. The application container gives us a central location from which to gain 
access to all of the subsystems that allow XenForo to run - whether that is to access the database, send an email, 
create an alert, and so on.

What makes this so significant is that the container pattern also allows us to simply swap out those subsystems with 
systems of our own that can mimic or mock the behaviour we want to see for our testing purposes.

So for example, rather than actually sending an email - we can swap out the mail handling classes with mock objects 
that pretend to do the same thing without actually causing emails to be sent. It would be rather annoying to be sending 
lots of emails every time you ran your test suite!

What's more - we can set expectations on those mock classes to ensure that specific methods were called, perhaps with 
a certain set of parameters - as part of validating that our code executed as expected for our tests.

To make unit testing of XenForo addons easier, I have developed a unit testing framework which relies on the ability to 
arbitrarily swap out the subsystems from the application container with mock objects and application stubs - both for 
setting expectations and for avoiding side effects from our testing.

## 3. XenForo Application Architecture

To understand how we execute unit tests against our addons, we first need to understand how XenForo executes.

There are two possible execution triggers that we interact with as users or administrators of XenForo. The first is 
obviously the web interface. When we visit a XenForo site in our browser, the web server redirects our request via 
`{forum_root}/index.php`

If you look at this file, it's pretty simple (comments are mine):

```php
<?php

// start by ensuring we are running the minimum required version of PHP
$phpVersion = phpversion();
if (version_compare($phpVersion, '5.6.0', '<'))
{
    die("PHP 5.6.0 or newer is required. $phpVersion does not meet this requirement. Please ask your host to upgrade PHP.");
}

// save the current directory from our index.php file - we'll use that later as our forum root
$dir = __DIR__;

// this is where we load in the main XenForo framework, but we aren't executing it yet
require($dir . '/src/XF.php');

// now let's boot the framework - this is what sets up some key variables, environment settings, runs our autoloader
// and registers our error handlers and shutdown functions
XF::start($dir);

// check whether we've got an API call
if (\XF::requestUrlMatchesApi())
{
    \XF::runApp('XF\Api\App');
}
else // ... or a regular web call
{
    \XF::runApp('XF\Pub\App');
}
```

You'll note that the API calls use the same entry point - making a HTTP call to `{forum_root}/api` gets redirected via 
the same index file.

Either way, `\XF::runApp()` is what actually starts execution of the XenForo framework. The first thing it does is to 
instantiate a new application container, store it in a static variable so we can access it globally, load the settings 
from `config.php`, start the autoloader for our addons, and then finally it starts handling the URL requested to work 
out whether we need to display a forum list, or a specific thread or take some other action.

The second entry point is via the CLI - the Command Line Interface. In this case, we instead execute 
`{forum_root}/cmd.php`, which looks remarkably similar to `index.php` (again, comments mine):

```php
<?php

// start by ensuring we are running the minimum required version of PHP
$phpVersion = phpversion();
if (version_compare($phpVersion, '5.6.0', '<'))
{
    die("PHP 5.6.0 or newer is required. $phpVersion does not meet this requirement. Please ask your host to upgrade PHP.");
}

// save the current directory from our cmd.php file - we'll use that later as our forum root
$dir = __DIR__;

// this is where we load in the main XenForo framework, but we aren't executing it yet
require ($dir . '/src/XF.php');

// now let's boot the framework - this is what sets up some key variables, environment settings, runs our autoloader
// and registers our error handlers and shutdown functions
XF::start($dir);

// this is the important bit. Rather than "running" our application - we instead instantiate a CLI runner (based on
// Symfony's Console Component) and have that work our what command we're asking for and executing that for us.
$runner = new \XF\Cli\Runner();
$runner->run();
```

The `$runner->run()` command sets up the console input and output classes, and then sets up and starts our application 
framework, just like the web based entry point.

The difference is that instead of interpreting URLs to work out what we're asking it to do, it uses the command line 
parameters. Rather than generating a HTML (or JSON or XML etc) response back to a web browser or API client, it waits 
for output from the CLI command and writes that to the console.

In both cases though, we have our application container instantiated with all of the subsystems we might need, ready 
for our application to call them.

## 4. PHPUnit Architecture

Now, the important thing to understand with PHPUnit is that it is a console application. It has no web interface and 
does not interact with browsers or web servers. Indeed, it is much closer in function to XenForo's CLI interface.

The difference between the XenForo CLI and running PHPUnit is that our unit tests aren't giving XenForo a command to 
execute, we are feeding PHPUnit a set of test scripts, which it will execute one after the other and then output the 
results of those tests to the console.

So where does XenForo fit into this? Easy - we can have PHPUnit instantiate our XenForo application framework for us so 
that the application container is there waiting for us to call as our code is executed!

What's more, as part of our tests, we can selectively swap out or mock some of those subsystems in the application 
container so that we can give predictable responses to calls our code makes without any side effects for other tests.

There's quite a bit that goes on behind the scenes to make that happen - but I've created a Composer package you can 
include in your addons that does all the heavy lifting for you. All you need to do is write your tests and call various 
helper methods from my package to swap out subsystems as required.

## 5. Mock Objects

An important adjunct to PHPUnit is the ability to mock classes that our code being tested will interact with. This 
allows us to both ensure that our code makes the expected calls with the expected parameters, plus return predictable 
responses for our code to execute a specific code path.

In unit tests, mock objects simulate the behaviour of real objects. They can behave just like real objects, including 
being passed to function calls that declare type expectations and providing concrete implementations of interfaces, 
without needing to provide any implementation details.

[Mockery](http://docs.mockery.io/) is a simple PHP mock object framework for use in unit testing with PHPUnit. Mockery is designed as a drop in 
alternative to PHPUnit's own mock objects library. We utilise Mockery heavily in our unit testing framework.

## 6. XenForo Unit Test Framework

See installation instructions later on in this tutorial to learn how to integrate the unit test framework into your 
addon. For now I'll give you a brief overview of how it works once installed.

At this point I should acknowledge the work of [Taylor Otwell](https://twitter.com/taylorotwell) and other contributors 
to the [Laravel PHP Framework](https://laravel.com/) - the XenForo Unit Test Framework was heavily inspired by the test 
framework developed for Laravel and some of the reflection classes are taken directly from the `Illuminate\Support` 
component.

If you're new to PHPUnit - this is the point where you should go away and read some tutorials. At least read the 
documentation: [Writing Tests for PHPUnit](https://phpunit.readthedocs.io/en/8.4/writing-tests-for-phpunit.html)

The test classes we write for PHPUnit typically inherit from `PHPUnit\Framework\TestCase`. What my XenForo unit test 
framework does is provide some additional layers between the base TestCase class and your own classes.

`Hampel\Testing\TestCase` extends `PHPUnit\Framework\TestCase` and provides most of the functionality for the unit test 
framework. This is provided by the Composer package.

You then copy two files into your unit testing directory `{addon_root}/tests/TestCase.php`:

```php
<?php namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * @var string $rootDir path to your XenForo root directory, relative to the addon path
     *
     * Set $rootDir to '../../../..' if you use a vendor in your addon id (ie <Vendor/AddonId>)
     * Otherwise, set this to '../../..' for no vendor
     *
     * No trailing slash!
     */
    protected $rootDir = '../../../..';

    /**
     * @var array $addonsToLoad an array of XenForo addon ids to load
     *
     * Specifying an array of addon ids will cause only those addons to be loaded - useful for isolating your addon for
     * testing purposes
     *
     * Leave empty to load all addons
     */
    protected $addonsToLoad = [];

    /**
     * Helper function to load mock data from a file (eg json)
     * To use, create a "mock" folder relative to the tests folder, eg:
     * 'src/addons/MyVendor/MyAddon/tests/mock'
     *
     * @param $file
     *
     * @return false|string
     */
    protected function getMockData($file)
    {
        return file_get_contents(__DIR__ . '/mock/' . $file);
    }
}
```

You might need to edit the `$rootDir` variable if you don't include a vendor folder in your addon installation path.

This class is what your unit tests will extend.

The other file contains the code we use to boot the XenForo framework `{addon_root}/tests/CreatesApplication.php`:

```php
<?php namespace Tests;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \XF\App
     */
    public function createApplication()
    {
        require_once("{$this->rootDir}/src/XF.php");

        \XF::start($this->rootDir);

        $options['xf-addons'] = $this->addonsToLoad ?: [];

        return \XF::setupApp('Hampel\Testing\App', $options);
    }
}
```

As you can see from our previous discussion about entry points into XenForo, we follow the same pattern - including 
`XF.php`, booting the framework and then setting up our application container. We don't need to do anything more - we 
just need the application container available for us to use in our tests.

The code in this trait is called during the setUp function for our base TestClass, so it will make the XenForo 
application framework available to any class which extends our TestClass.

We expose it here rather than simply including it in the Composer package in case you need to customise the XenForo 
boot process in any way - you can do so without changing the vendor files.

## 7. Swapping XenForo Subsystems

The core functionality of the XenForo Unit Testing Framework is provided by some fairly simple helper functions which 
make it easy for us to swap out subsystems from the application container.

It helps to understand that most of the subsystems in the application container are not fully instantiated classes - 
instead they use closures to configure and instantiate the object that will actually provide the subsystem the first 
time it is called by the application. This is much more efficient - since we don't execute or instantiate any of that 
code until we need to use it. It also makes it trivial to swap out - we can simply over-write those closures in the 
container with our own objects.

`swap()` simply replaces the code at a specific container key with an instance we supply.

So if we have written a test harness which replaces certain functionality, we can just swap in our class in place of 
the core one. We do exactly this in our `fakesMail()` helper - we replace the `mailer.transport` and `mailer.queue` 
container keys with our own classes that logs mails that were sent by the application and lets us run assertions 
against that log - but never actually sends mail.

```php
    protected function fakesMail()
    {
        $this->swap('mailer.transport', function (Container $c) {
            return new Transport(
                \Swift_DependencyContainer::getInstance()->lookup('transport.eventdispatcher')
            );
        });

        $this->swap('mailer.queue', function(Container $c)
        {
            return new Queue($c['db']);
        });
    }
```
    
In the above code, the `Transport` class we instantiate is actually a custom class I built which implements the
`\Swift_Transport` interface and so accepts all the same calls that a normal Swift Transport class would, but just 
stores them in an array rather than sending them.

`mock()` takes that one step further and lets us swap the closure function with a mock object that we can declare 
assertions on for testing purposes.

We supply an abstract class to use as the basis for the mock object and then optionally supply expectations on that 
mock.

For example, if our code queries the `XF\Http\Request` class to retrieve the IP address of the visitor - we can't test 
this from PHPUnit because there is no HTTP request when executing a console command! However, we can simply mock our 
request - it's stored in the `'request'` key in the application container and so we might do this:

```php
$this->mock('request', XF\Http\Request::class, function ($mock) {
   $mock->expects()->getIp(true)->once()->andReturns('10.0.0.1');
});
```

So we instruct XenForo to use our mock object when querying the Request object, and we tell PHPUnit that we are 
expecting our code to call `XF\Http\Request::getIp(true);` once, at which point our mock object will return the IP 
address `10.0.0.1`.

We have helpers to mock many of the key subsystems:

* `mockDatabase`
* `mockRepository`
* `mockFinder`
* `mockEntity`
* `mockFactory`
* `mockService`
* `mockRequest`
* `mockFs`

We also have fake systems which log interactions with the subsystem and then allow us to query that after the fact:

* `fakesErrors`
* `fakesJobs`
* `fakesLogger`
* `fakesMail`
* `fakesSimpleCache`
* `fakesRegistry`
* `fakesHttp`

Finally, we have some special helper functions for specific purposes:

* `assertBbCode` lets you test the expected output of some BbCode, useful for testing custom codes.
* `expectPhrase` mocks the phrase rendering process and allows us to return arbitrary strings for phrases.
* `setOption` & `setOptions` allow us to directly set the values we want for our options so we don't have to mock the 
options repository. It restores options after each test is executed - keeping to our goal of no side-effects.
* `setTestTime` lets us set the application execution time (`\XF::$time`) to a known specific time (optionally using the
Carbon library), so that we can test functions that rely on time intervals or comparisons.
* `swapFs` lets us swap the filesystem from _local_ to _memory_ so that we can make non-persistent changes to the 
filesystem and avoid side effects
* `isolateAddon` lets us force XenForo to only load class extensions and code event listeners for our addon, thus 
avoiding potential conflicts or unexpected code paths from other addons installed on our dev server

## 8. Installing the Framework

The unit testing framework is structured as a Composer package `hampel/xenforo-test-framework` - you'll need to have 
Composer installed on your dev server before you can use it. We use the `require-dev` directive to load the testing 
framework only in our development environment. We will later show the commands required to strip our test code from our 
addon during the build process - we don't want or need to deploy our unit tests to our production servers.

You can view the source code for the package here: 
[XenForo Test Framework](https://bitbucket.org/hampel/xenforo-test-framework)

If you need more guidance on using Composer packages in your XenForo addons - refer to my tutorial: [Using Composer 
Packages in XenForo 2.1+ Addons Tutorial](https://xenforo.com/community/resources/using-composer-packages-in-xenforo-2-1-addons-tutorial.7432/)

I'm going to assume that your XenForo forum root is at `/srv/www/xenforo` and that your addon 
(let's call it "Vendorly/Addonista") is installed in `/srv/www/xenforo/src/addons/Vendorly/Addonista`

If you already have a `composer.json` file, I'll presume that you know what you are doing and will simply instruct you 
to add the `require-dev` and `autoload-dev` directives below to your file. Otherwise, if your package does not already 
use Composer, you can simply create a `composer.json` file with the following in the root of your addon 
(`/srv/www/xenforo/src/addons/Vendorly/Addonista/composer.json`):

```json
{
    "require-dev": {
        "hampel/xenforo-test-framework": "^2.1",
        "nesbot/carbon": "^3.0"
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    }
}
```

Note that `nesbot/carbon` is optional, but really useful.

Change directory to your addon root, then run `composer update` to install the framework. We install PHPUnit and 
Mockery automatically for you.

```bash
$ cd /srv/www/xenforo/src/addons/Vendorly/Addonista/
$ composer update
```

Next, look in the `{addon_root}/vendor/hampel/xenforo-test-framework/` directory - copy the entire `tests` directory to
the root of your addon.

```bash
$ cd /srv/www/xenforo/src/addons/Vendorly/Addonista/
$ cp vendor/hampel/xenforo-test-framework/tests .
```

Inside the tests directory, you'll find the following directories and files:

* `/tests/Feature` this is a placeholder for future support for feature testing
* `/tests/Unit` this is where all of your unit tests should go
* `/tests/Unit/ExampleTest.php` this is a simple example test - edit or copy it as the basis for your own test classes
* `/tests/CreatesApplication.php` this is the trait that boots our XenForo test framework. If you need to adjust the way we boot things, you can change this - but for most cases you should leave it as is
* `/tests/TestCase.php` this is our base test class (`Tests\TestCase`) that all unit test classes should inherit from if you want to boot the XenForo application framework for use in your tests

Third step is to copy the `phpunit.xml` file from `{addon_root}/vendor/hampel/xenforo-test-framework/phpunit.xml` into the root of your addon:

```bash
$ cd /srv/www/xenforo/src/addons/Vendorly/Addonista/
$ cp vendor/hampel/xenforo-test-framework/phpunit.xml .
```

This file contains the configuration and directives for PHPUnit - importantly, look at the `testsuite` configuration 
options - they tell PHPUnit where to find our unit tests.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit backupGlobals="false"
         backupStaticAttributes="false"
         bootstrap="vendor/autoload.php"
         colors="true"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>

        <testsuite name="Feature">
            <directory suffix="Test.php">./tests/Feature</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Finally, update your `build.json` file to clean up unit test code when we build our addon releases. Assuming you only 
use Composer for unit testing:

```json
{
    "exec": [
        "rm -v _build/upload/src/addons/{addon_id}/composer.json",
        "rm -v _build/upload/src/addons/{addon_id}/composer.lock",
        "rm -v _build/upload/src/addons/{addon_id}/phpunit.xml",
        "rm -v -r _build/upload/src/addons/{addon_id}/tests",
        "rm -v -r _build/upload/src/addons/{addon_id}/vendor"
    ]
}
```

(you can literally use the string `{addon_id}` - you don't need to hard code your addon ID in the `build.json` file !!)

... these directives will delete all of the files relating to our unit testing, including the composer files and vendor
directory.

If you also use Composer for other non-development parts of your addon, then you don't want to remove everything - just 
add the following to your `build.json` instead:
```json
{
    "exec": [
        "rm -v _build/upload/src/addons/{addon_id}/phpunit.xml",
        "rm -v -r _build/upload/src/addons/{addon_id}/tests"
    ]
}
```

... and make sure you specify the `--no-dev` option when running `composer install` during your build process.

For example, one of my addons which uses Composer for both dev and non-dev purposes has the following `build.json` file:
```json
{
    "exec": [
        "composer install --working-dir=_build/upload/src/addons/{addon_id}/ --no-dev --optimize-autoloader",
        "composer install --no-dev --optimize-autoloader",
        "rm -v _build/upload/src/addons/{addon_id}/phpunit.xml",
        "rm -v -r _build/upload/src/addons/{addon_id}/tests"
    ]
}
```

## 9. Configuring the Framework

There are two options you may need to configure, both in `TestCase.php` which will be in the `tests` directory.

**Root Directory Path**

The `$rootDir` variable specifies the path to your forum root, relative to the addon directory.

```php
protected $rootDir = '../../../..';
```

Typically, addons will use the `Vendor/AddonId` structure, which makes the addon directory 
`<forum-root>/src/addons/Vendor/AddonId` - and thus we need to go back 4 levels to reach the forum root.

However, if you haven't used a Vendor in your addon id, you'll need to adjust this variable. If your addon directory is:
`<forum-root>/src/addons/AddonId`, then you'll need to change it to: `$rootDir = '../../..'`

**Addon Isolation**

The other option is the addon isolation system - allowing us to instruct the XenForo framework to only load addons we
need for testing, thus minimising any side effects or unintended interactions that may occur from other addons we have
installed in our development forum.

Simply list the full addon ids of each addon you want to be loaded when the unit tests run, as an array.

For example:

```php
protected $addonsToLoad = ['Vendor/AddonId', 'XFMG'];
```

Generally, you would simply list the addon id of the addon you are developing, thus preventing all other addons from
being loaded when unit tests execute. Note that this also restricts Composer autoloading, which is a good way of 
checking that you've specified all requirements in `composer.json` correctly and aren't picking up packages from other 
addons.

Leaving the array empty will load all addons as normal.

## 10. Running Unit Tests

Composer installed the PHPUnit executable at `{addon_root}/vendor/bin/phpunit`. To run our tests, we go to our addon 
root in the console and simply execute PHPUnit. The `phpunit.xml` file (which should also be in our addon root) tells 
PHPUnit where to find our tests.

```bash
$ cd /srv/www/xenforo/src/addons/Vendorly/Addonista/
$ ./vendor/bin/phpunit
PHPUnit 8.4.1 by Sebastian Bergmann and contributors.

..................                                                18 / 18 (100%)

Time: 544 ms, Memory: 32.00 MB

OK (18 tests, 70 assertions)

```

You could also set up a bash alias to make it easier to run. I have the following:

```bash
alias u="./vendor/bin/phpunit"
```

... so all I need to do is change directory to my addon root and then just run the `u` command to execute my unit tests.

## 11. Framework Documentation

See the file DOCS.md

## 12. Limitations

There are quite a few things we can't effectively test, or which are problematic to test:

### Controllers

Controllers may look simple on the surface, but there is a large amount of scaffolding involved to make them run.
Session data, routing data, request data, validators - all need to be configured before we could effectively unit test
a controller. 

If we had a library which made it easy to make simple HTTP requests against our XenForo test system and test the 
responses, we could use feature tests - but we don't have that yet.
 
### Database queries

While we can mock the database adapter or entities and finders, for anything more than simple queries it quickly becomes
cumbersome to unit test code which makes complex queries.

### Entity saving

While we can mock an entity, we cannot stop it from interacting with the database because the `save()` method on the
base Entity class is marked `final` - meaning that our mocks can't actually stop that method from executing by 
overriding it.

Basically, you cannot unit test code which calls `save()` on an entity - running your unit tests will cause side effects
from database updates.

### Functions which use `time()` rather than `\XF::$time`

We cannot override the return value of the PHP function `time()` with an arbitrary known value like we can with the 
`\XF::$time` variable. As such, we cannot know in advance what that value will return which can make some tests 
problematic.

Using `Carbon::now()` as a replacement for `time()` would solve this problem for us - since the Carbon library has the
ability to set arbitrary return values for testing. However, we cannot control external libraries and the XenForo core 
- if functions they use rely on `time()` and we need to call them and can't mock the entire class, then we could have 
difficulty testing in some circumstances.

### UI changes & template modifications

We cannot validate that certain code causes the UI to change, such as changes to views or template modifications. That's
more of a feature test level operation rather than unit testing anyway.

### Data in the database

Any code which relies on certain data being present in the database at a given point in time is problematic, since that
data could change from external sources - thus breaking our unit tests in future runs. 

This includes any code which wants to create and save entities to the database so it can then later manipulate them -
unless we have a way to clean up that data after each test executes and restore the database to the state it was prior
to the test being run, then we have side effects.

The ideal way around this would be to build a new database adapter which uses a system such as SQLite which offers an
in-memory database that can be seeded and then destroyed very quickly as each test is run. Unfortunately, this will not
be a trivial exercise - there are many MySQL-specific functions built into XenForo. Then there is the question of how to
effectively seed a newly created database quickly with all of the data required to have a functioning XenForo instance 
ready for testing. 

### Static classes

If we can't swap out a class with our own instance, because it relies on static variables or functions - then it will be
much more difficult or impossible to test. This is a general limitation on unit testing rather than something specific 
to XenForo.

## 13. Writing testable code and other unit testing tips

One of the main issues people face when trying to test their code is that they get all caught up trying to work around
the structure of their code as it stands. You end up trying to jump through hoops unnecessarily when you try to test 
code that wasn't written with testing in mind.

It takes a bit of experience to know when something is not easily testable - and to know how to effectively change your
code to make testing easier.

The following tips are not intended to be comprehensive or prescriptive - indeed some developers may disagree with a few
of my suggestions here and that's okay. At least I hope it makes you think about your testing and how you might improve 
your practices and perhaps even generate some useful discussion.

### Know what you are testing

The first thing to remember is that we aren't testing the XenForo framework - we'll simply assume it works, especially 
given that we can't arbitrarily change it.

Similarly, any external libraries we pull in via Composer or other means should be assumed to work and have their own 
unit tests.

We want to focus on our own code and minimise the number of external code paths we will use by mocking classes our code
uses.

### Know why you are testing it

There's no point writing tests for code that can't fail or always returns a specific value. Unless it could cause 
cascading errors or unexpected failures, why bother testing it?

We want to know that our logic holds and that for a given set of inputs, we get the expected outputs.

We want to know that if something external changes, that our code behaves consistently and as expected.

### Don't leave side effects

If your test code changes the system in such a way that subsequent execution of your unit tests returns a different 
outcome, you have side effects. Avoid this at all costs. 

Unit tests need to be repeatable and perhaps even automatable. You need to have confidence that your unit tests will run
in exactly the same environment every time without any manual intervention from you. This is why we mock systems that
would cause side effects if we allowed them to execute such as: database updates; email sending; filesystem updates; 
external API calls.

### Don't try and write feature or integration tests

We're unit testing. That is a different exercise to feature or integration testing.

If you're calling an external system such as an API, you should be mocking the responses (Guzzle has functions to 
help you do this for API calls).

Don't cause database updates. Don't send emails. Don't write to the filesystem. We should be testing our code in 
isolation in a repeatable and consistent manner.

Feature and integration tests are important too - but right now we are focused on unit testing.

### Keep your controllers thin

If you find yourself wondering why you can't unit test your controller - it's probably a good sign you're doing it 
wrong.

Controllers are just coordinators - they are invoked by the routing engine based on the URL requested, and are 
responsible for validating the request, causing the correct logic to be executed based on that request, and then 
returning a response.

You can't test logic in your controller. Logic and algorthims should be contained in repositories or services. 
Sub-containers are also useful places to hold related logic.

The same applies to console commands and jobs - keep them as simple as possible and place your logic in repositories, 
services or sub-containers.

### Avoid static functions

Okay, but what about my Cron task which has to be a static function - how do I test that?

Take a look at how the XenForo core structures the built in Cron tasks:

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

... the actual static part of the function is extremely short and simple - it grabs a repository and executes the logic
there! You'll find that most built in Cron tasks use either repositories or services to do all the work.

### Avoid mocking the database

Avoid mocking the database if possible - it will very quickly become cumbersome and painful to manage. Use repositories
for code which interacts with the database, you can then test the repository in isolation and mock that repository when 
testing other code.

You'll probably have to mock the database when testing the repository - but you can do that in isolation to the rest 
of your program logic.

### Don't mock everything

If you need to mock everything to get your code to work, it probably has too many dependencies. Try restructuring your 
code to split concerns into multiple classes.

If you're using core XenForo functionality which doesn't cause side effects, then by all means let your test code run 
through that functionality to ensure that your code behaves as expected. Mock the parts that you need more control over
or that you need to stop causing side effects.

This is especially true of utility functions from the core framework - there's no need to mock them unless they cause 
side-effects.

### Don't mock `XF\App`

You're doing it wrong. Use the helper functions provided by my XenForo Testing Framework.

### Don't mock anything to try and set or get options

Use `setOption()` or `setOptions()` instead.

### Don't mock `XF\Error`

Use `fakesErrors()` instead.

### Don't mock `XF\Job\Manager`

Use `fakesJobs()` instead.

### Don't mock `XF\Logger`

Use `fakesLogger()` instead.

### Don't mock `XF\Mail\Transport` or `XF\Mail\Queue`

Use `fakesMail()` instead.

### Don't mock `XF\SimpleCache`

Use `fakesSimpleCache()` instead.

### Don't mock `XF\DataRegistry`

Use `fakesRegistry()` instead.

### Don't mock `XF\Language` or `XF\Phrase`

When dealing with phrases, use `expectPhrase()` instead.

### If you aren't asserting, you aren't testing

Not asserting that things are as you expect in your test code is ignoring opportunities to find unexpected bugs. Use
asserts liberally.

However, watch out for the trap where lots of asserts in a single test function makes it difficult to identify exactly 
what failed. PHPUnit allows you to add custom error messages to most assert functions so you can make the errors more 
meaningful to help isolate problematic code in the middle of a large test suite.

There's also not much point asserting that we passed the value true to our method when we always pass the value true to
our method. Test for the unexpected.

### Don't use print or dump

While you are actively developing, it can be useful to inspect variables to see what they contain. Indeed, the 
`\XF::dump()` command will give you nicely formatted output on the console during unit tests as well - so it can be
very useful. Just don't leave them there once you are done! 

Output from PHPUnit for code you've already finished working on should be clean and only show you errors or success 
indicators generated by PHPUnit itself.

### Make test functions descriptive
 
When your unit test fails, PHPUnit will tell you the name of the test function it was executing. A function name of 
`testFoo()` isn't going to give you much of a clue as to what went wrong.
 
Try using function names like `test_foo_throws_an_exception_when_passed_null()` or 
`test_foo_returns_null_when_passed_a_banned_user()`
 
This will also help you keep your test functions focused on a single code path or a single use-case.
 
### Avoid logic in your unit tests
 
If you need branching in your unit tests, it most likely indicates that your test functions are too big or you are 
trying to test multiple code paths in the one function. Split your test into multiple test functions.

### Test boundary conditions

Don't just test the simple or expected cases. Code breaks when unexpected input gets passed to it. So pass unexpected 
data to your code to make sure it is robust enough to fail elegantly.

Of course, you can also go overboard with this - there's no point testing for failure when the wrong class is passed to
a function which type hints a different class. Of course that is going to cause a catastrophic failure - that's a bug
and it should make the system fail so that we correctly identify it as a bug and fix it.

But what happens when a function returns null unexpectedly? Trying to perform operations on a null value is one of the
most common sources of unexpected failure and many system calls return null (or false) in certain circumstances.

### How do I test private functions?

You don't. Unit tests should be testing the public interface of your code. If there is code that is private - it will be
called by a public method at some point. Test that public method. If your private code is never called by a public
method, then why does it exist?

If you find yourself wishing you could test that private method directly, then take a look at your code structure and 
see if you can instead encapsulate that method into a separate class that has a public interface that can be tested.

### Don't treat your test code as unimportant

Your test code is as important as your production code. It will be a huge source of frustration to have test code which
gives unexpected results because of a copy-and-paste error you allowed in due to carelessness. It's even worse if bugs
in your production code go undetected because of bugs in your test code.

If you are lucky enough to be developing as part of a team who can peer-review your code, take the opportunity to also
peer-review your test code.

Make sure you check your test code in to source control!
