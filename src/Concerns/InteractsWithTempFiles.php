<?php

namespace Hampel\Testing\Concerns;

use XF\Util\File;

trait InteractsWithTempFiles
{
	/**
	 * Absolute paths to the temporary directories this test created.
	 *
	 * @var string[]
	 */
	private $tempDirectories = [];

	/**
	 * Create an empty temporary directory, and remove it when the test finishes.
	 *
	 * Somewhere to write files without touching the forum. The path is absolute and inside the
	 * system temporary directory, and the framework removes it whether the test passes, fails or
	 * errors - so a test needs no tearDown() of its own, which is the point.
	 *
	 * **Reach for this rather than pairing File::getTempDir() with File::deleteDirectory()
	 * yourself.** deleteDirectory() resolves a relative or empty path against the XenForo root, so
	 * a cleanup holding a path that was never assigned deletes the installation. DOCS.md has the
	 * mechanism under UsesDatabaseTransactions.
	 *
	 * @param string $prefix - so the directory is recognisable while a test is running
	 *
	 * @return string - the absolute path, with no trailing separator
	 */
	protected function createTempDirectory($prefix = 'xftest')
	{
		$base = realpath(sys_get_temp_dir());

		if ($base === false)
		{
			throw new \LogicException(
				'The system temporary directory does not exist, so there is nowhere to put one'
			);
		}

		$path = $base . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(8));

		if (!@mkdir($path, 0700))
		{
			throw new \LogicException("Could not create the temporary directory '$path'");
		}

		$this->tempDirectories[] = $path;

		return $path;
	}

	/**
	 * Remove the temporary directories this test created.
	 *
	 * Called from tearDown() before anything else and outside the check for an application, so a
	 * test that failed during boot still has its directories removed.
	 *
	 * @return void
	 */
	protected function removeTempDirectories()
	{
		foreach ($this->tempDirectories AS $path)
		{
			if ($this->isRemovableTempDirectory($path))
			{
				// safe to hand to XenForo here precisely because of the guard above: an absolute
				// path is returned by canonicalizePath() unchanged, so the resolution against the
				// XenForo root that makes this function dangerous cannot happen
				File::deleteDirectory($path);
			}
		}

		$this->tempDirectories = [];
	}

	/**
	 * Whether a path is one this trait is allowed to delete.
	 *
	 * The guard that matters is the last one: however the path was arrived at, it has to resolve
	 * inside the system temporary directory. A value that is empty, relative, or simply not what
	 * was expected cannot name the forum or anything else outside there.
	 *
	 * @param mixed $path - a string by contract, anything at all in practice. This runs in
	 *                      teardown over values a test may have interfered with, and the whole
	 *                      point of the guard is to refuse one that is not a path
	 *
	 * @return bool
	 */
	private function isRemovableTempDirectory($path)
	{
		if (!is_string($path) || $path === '')
		{
			return false;
		}

		$real = realpath($path);
		$base = realpath(sys_get_temp_dir());

		if ($real === false || $base === false || !is_dir($real))
		{
			return false;
		}

		return strpos($real, $base . DIRECTORY_SEPARATOR) === 0;
	}
}
