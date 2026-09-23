<?php

namespace Hampel\Testing\Integration;

use XF\Mvc\Reply\View;

/**
 * callAction() with an uploaded file, and rendering a raw view.
 *
 * test_a_ / test_b_ ordering is load-bearing: the second proves the temporary file the first
 * created was removed.
 */
class UploadAndRawViewTest extends TestCase
{
	private static $tempFile;

	public function test_a_an_uploaded_file_reaches_the_action()
	{
		$file = $this->uploadedFile('probe contents', 'probe.txt');
		self::$tempFile = $file['tmp_name'];

		$this->callAction('XF:Notice', 'save', 'admin', [], [], 'POST', [], ['probe' => $file]);

		$upload = $this->app()->request()->getFile('probe');

		$this->assertNotNull($upload, 'the action should see the file');
		$this->assertSame('probe.txt', $upload->getFileName());
		$this->assertSame('probe contents', file_get_contents($upload->getTempFile()));
	}

	public function test_b_the_temporary_file_is_removed_after_the_test()
	{
		$this->assertFileDoesNotExist(self::$tempFile);
	}

	public function test_an_action_with_no_files_still_sees_none()
	{
		$this->callAction('XF:Notice', 'save', 'admin');

		$this->assertNull($this->app()->request()->getFile('probe'));
	}

	public function test_uploading_a_file_from_disk_reports_its_name()
	{
		$file = $this->uploadedFileFromPath(__FILE__);

		$this->assertSame(basename(__FILE__), $file['name']);
		$this->assertSame(filesize(__FILE__), $file['size']);
	}

	public function test_a_path_that_cannot_be_read_is_refused()
	{
		$this->expectException(\LogicException::class);

		$this->uploadedFileFromPath('/no/such/fixture/anywhere');
	}

	public function test_a_raw_view_renders_its_body_and_headers()
	{
		$this->swap('app.classType', 'Admin');

		$reply = new View('XF:Log\EmailBounce\View', '', [
			'bounce' => $this->makeEntity('XF:EmailBounceLog', ['raw_message' => 'probe body']),
		]);

		$response = $this->renderRawReply($reply);

		$this->assertSame('probe body', $response->body());
		$this->assertStringContainsString('text/plain', $response->contentType());
	}
}
