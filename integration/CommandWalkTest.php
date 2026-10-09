<?php

namespace Hampel\Testing\Integration;

/**
 * The walk behind assertConsoleCommandsLoad(), pointed at fixtures rather than at an installed
 * add-on, so every case XenForo's own runner meets is covered: a command, an abstract base beside
 * it, a class that is not a command, and one that cannot be loaded at all.
 */
class CommandWalkTest extends TestCase
{
	/** @var string */
	private $fixtureDirectory = 'CommandDir';

	protected function addOnCommandLocation($addOnId)
	{
		return [
			__DIR__ . '/Fixtures/' . $this->fixtureDirectory,
			'Hampel\Testing\Integration\Fixtures\\' . $this->fixtureDirectory,
		];
	}

	public function test_the_walk_returns_the_commands_and_skips_what_xenforo_skips()
	{
		$classes = $this->assertConsoleCommandsLoad('Probe/Fixtures');

		$this->assertSame([Fixtures\CommandDir\GoodCommand::class], $classes);
	}

	public function test_a_class_that_cannot_be_loaded_is_never_skipped()
	{
		$this->fixtureDirectory = 'CommandDirBroken';

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('stop cmd.php for every add-on on the forum');

		$this->assertConsoleCommandsLoad('Probe/Fixtures');
	}

	public function test_php_files_that_declare_no_command_are_refused_as_nothing_to_assert()
	{
		// the skips must not add up to a pass: a directory of helpers and abstract bases has no
		// command in it, and a walk that returned an empty list would assert nothing
		$this->fixtureDirectory = 'CommandDirNoCommands';

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('none declares a class XenForo would list');

		$this->assertConsoleCommandsLoad('Probe/Fixtures');
	}

	public function test_a_directory_with_no_php_files_is_refused()
	{
		$this->fixtureDirectory = 'CommandDirNoPhp';

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('No PHP files found');

		$this->assertConsoleCommandsLoad('Probe/Fixtures');
	}
}
