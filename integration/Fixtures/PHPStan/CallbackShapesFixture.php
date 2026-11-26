<?php

namespace Hampel\Testing\Integration\Fixtures\PHPStan;

use Hampel\Testing\Integration\TestCase;

/**
 * Analysed by PhpStanCallbackShapesTest, never run: each marked line is a truth-test callback
 * whose parameter is left untyped, and the marker says whether PHPStan should report it. A key
 * the entry does not have is reported, which shows the parameter is typed rather than ignored.
 */
class CallbackShapesFixture extends TestCase
{
	public function shapes(): void
	{
		$this->assertJobQueued('XF:User', fn ($job) => $job['execute_data']['id'] === 1); // expect: clean
		$this->assertJobQueued('XF:User', fn (array $job) => $job['execute_class'] === 'x'); // expect: clean
		$this->assertJobNotQueued('XF:User', fn ($job) => $job['unique_key'] === 'x'); // expect: clean
		$this->assertJobQueued('XF:User', 2); // expect: clean
		$this->assertJobQueued('XF:User', fn ($job) => $job['no_such_key'] === 1); // expect: reported

		$this->assertMailSent(fn ($mail) => $mail->getSubject() === 'x'); // expect: clean
		$this->assertMailNotSent(fn ($mail) => $mail->getTo() === []); // expect: clean
		$this->assertMailSent(fn ($mail) => $mail->noSuchMethod()); // expect: reported

		$this->assertEventFired('app_setup', fn ($args, $hint) => $hint === null && $args === []); // expect: clean
		$this->assertEventNotFired('app_setup', fn ($args) => count($args) === 1); // expect: clean
		$this->assertEventFired('app_setup', fn ($args, $hint) => $hint->noSuchMethod()); // expect: reported

		$this->assertHttpRequestSent(fn ($request) => $request->getUri()->getHost() === 'x'); // expect: clean
		$this->assertHttpRequestNotSent(fn ($request) => $request->getMethod() === 'GET'); // expect: clean
		$this->assertHttpRequestSent(fn ($request) => $request->noSuchMethod()); // expect: reported

		$this->assertActionLogged('post', fn ($log) => $log['action'] === 'x' && $log['params'] === []); // expect: clean
		$this->assertActionNotLogged('post', fn ($log) => $log['type'] === 'post'); // expect: clean
		$this->assertActionLogged('post', fn ($log) => $log['field'] === 'x'); // expect: reported
		$this->assertChangeLogged('post', fn ($log) => $log['field'] === 'x'); // expect: clean
		$this->assertChangeNotLogged('post', fn ($log) => $log['type'] === 'post'); // expect: clean
		$this->assertChangeLogged('post', fn ($log) => $log['action'] === 'x'); // expect: reported

		$this->assertExceptionLogged(\Exception::class, fn ($entry) => $entry['raw_exception']->getCode() === 1); // expect: clean
		$this->assertExceptionNotLogged(\Exception::class, fn ($entry) => strlen($entry['message']) > 1); // expect: clean
		$this->assertExceptionLogged(\Exception::class, 'prefix'); // expect: clean
		$this->assertExceptionLogged(\Exception::class, fn ($entry) => $entry['no_such_key'] === 1); // expect: reported

		$this->assertLogged('error', fn ($record) => $record['context'] === [] && strlen($record['message']) > 1); // expect: clean
		$this->assertNotLogged('error', fn ($record) => $record['level'] === 'error'); // expect: clean
		$this->assertLogged('error', fn ($record) => $record['no_such_key'] === 1); // expect: reported

		$this->assertNoTemplateErrors(fn ($error) => strpos($error['error'], 'x') !== false); // expect: clean
		$this->assertNoTemplateErrors(fn ($error) => $error['no_such_key'] === 1); // expect: reported

		// what the reading helpers return is typed as well, so an element needs no wrapper
		$this->getHttpRequests()[0]->getHeaderLine('X-Test'); // expect: clean
		$this->getHttpRequests()[0]->noSuchMethod(); // expect: reported
		$this->getHttpHistory()[0]['request']->getMethod(); // expect: clean
		$this->getHttpHistory()[0]['response']?->getStatusCode(); // expect: clean
		$this->getHttpHistory()[0]['no_such_key']; // expect: reported
		$this->assertNotSame(0, strlen($this->getErrors()[0]['message'])); // expect: clean
		$this->getErrors()[0]['no_such_key']; // expect: reported
		$this->getExceptions()[0]['raw_exception']->getCode(); // expect: clean
		$this->getExceptions()[0]['no_such_key']; // expect: reported
	}
}
