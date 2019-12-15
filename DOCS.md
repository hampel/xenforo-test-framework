# XenForo Addon Unit Test Framework

Unit testing framework for XenForo

## Framework Documentation

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

### swap
Register an instance of an object in the container.

##### Parameters:

* `key` - the container key to be swapped
* `instance` - the object or closure to swap in

##### Example:

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

### mockDatabase
Mock the database adapter.

##### Parameters

* `mock` - optional - the mock closure to define expectations on

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
		$repo = $this->app()->repository('MyVendor/MyAddon:MyRepo');
		$result = $repo->myRepoFunction('foo');	
		
		// check we got the expected response
		$this->assertEquals('bar', $result);
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

_Warning:_ while we can mock an entity, we cannot stop it from interacting with the database because the `save()` method
on the base Entity class is marked `final` - meaning that our mocks can't actually stop that method from executing by 
overriding it. Basically, you cannot unit test code which calls `save()` on an entity - running your unit tests will cause side effects
from database updates.

_Solution:_ provided that we don't set type expectations for our entities, we can bypass this issue by creating a fake
mock class that does not inherit from our base entity class - thus avoiding the final save method. The 2nd parameter to
`mockEntity` can be set to `false` to disable inheritance.

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
* `assertErrorLogged`
* `assertErrorNotLogged`
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

### isolateAddon
Allow us to prevent class extensions and code event listeners from other addons from being loaded during tests to avoid
side effects and unexpected code-paths.

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
		$this->assertJobQueued('foo');
		
		// alternatively, assert our job was queued with specific attributes - return a truth test
		$this->assertJobQueued('foo', function ($job) {
			$data = $job->getData();
			return $data['key1'] == 'value1' && $data['key2'] == 'value2';
		});
	}
}	
```

Refer to the `Hampel\Testing\Concerns\InteractsWithJobs` trait for full details of available job validation 
functions.

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
Allow us to assert that emails were (or were not) sent or queued as a result of executing our test code, 
without side-effects (ie no emails actually get sent).

##### Parameters:

none

##### Assertions available:

* `assertMailSent`
* `assertMailSentTimes`
* `assertMailNotSent`
* `assertNoMailSent`
* `assertMailQueued`
* `assertMailQueuedTimes`
* `assertMailNotQueued`
* `assertNoMailQueued`

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
				   && array_key_exists($email, $mail->getTo());
		});		
	}
}	
```

Refer to the `Hampel\Testing\Concerns\InteractsWithMail` trait for full details of available mail validation 
functions.

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
