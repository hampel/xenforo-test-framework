<?php

namespace Hampel\Testing\Integration;

/**
 * createTempDirectory() exists so that a test needs no cleanup of its own. The ordered pair is
 * what proves the framework removes it: test_a creates one and test_b asserts it is gone.
 */
class TempDirectoryTest extends TestCase
{
	/** @var string|null */
	private static $created;

	public function test_a_a_temp_directory_is_created_empty_and_absolute()
	{
		$path = $this->createTempDirectory();
		self::$created = $path;

		$this->assertDirectoryExists($path);
		$this->assertSame($path, realpath($path), 'the path is absolute and already canonical');
		$this->assertStringStartsWith(
			realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR,
			$path,
			'inside the system temporary directory, so nothing done with it can reach the forum'
		);
		$this->assertSame([], glob($path . DIRECTORY_SEPARATOR . '*'), 'and empty');

		// usable for what a test would want it for
		file_put_contents($path . DIRECTORY_SEPARATOR . 'thing.txt', 'contents');
		$this->assertSame('contents', file_get_contents($path . DIRECTORY_SEPARATOR . 'thing.txt'));
	}

	public function test_b_the_temp_directory_was_removed_after_the_test()
	{
		if (self::$created === null)
		{
			$this->markTestSkipped(
				'runs after test_a, which creates the directory this asserts was removed'
			);
		}

		$this->assertDirectoryDoesNotExist(self::$created);
	}

	public function test_two_directories_are_both_created_and_distinct()
	{
		$first = $this->createTempDirectory();
		$second = $this->createTempDirectory('other');

		$this->assertNotSame($first, $second);
		$this->assertDirectoryExists($first);
		$this->assertDirectoryExists($second);
		$this->assertStringContainsString('other-', $second);
	}

	/**
	 * The guard is tested as a decision rather than by deleting anything: a test that removed the
	 * wrong directory to prove it would not is the mistake this helper exists to prevent.
	 */
	public function test_the_removal_refuses_any_path_outside_the_system_temporary_directory()
	{
		$decide = new \ReflectionMethod($this, 'isRemovableTempDirectory');
		$decide->setAccessible(true);

		$mine = $this->createTempDirectory();
		$this->assertTrue($decide->invoke($this, $mine), 'a directory this trait created');

		$base = realpath(sys_get_temp_dir());

		foreach ([
			'the XenForo root' => $this->rootDir,
			'the system temporary directory itself' => $base,
			'its parent' => dirname($base),
			'an empty path' => '',
			'a relative path' => 'some/relative/path',
			'null, as an unassigned property would be' => null,
			'a path that does not exist' => $base . DIRECTORY_SEPARATOR . 'zz-absent-' . uniqid(),
		] AS $describe => $path)
		{
			$this->assertFalse(
				$decide->invoke($this, $path),
				"must refuse $describe"
			);
		}
	}
}
