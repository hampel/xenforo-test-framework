<?php

namespace Hampel\Testing\Concerns;

use PHPUnit\Framework\Assert as PHPUnit;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

trait InteractsWithCommands
{
	/**
	 * Run a console command and return the tester, for asserting on its output and exit code.
	 *
	 * What dispatch() is for a route. An add-on's cron and cmd.php work is ordinary Symfony console
	 * commands, which XenForo instantiates and adds to an application of its own - so this does the
	 * same and runs the command through Symfony's tester.
	 *
	 * @param string|Command $command - the command class, or an instance if it needs constructing
	 * @param array $input - arguments and options, as CommandTester takes them
	 * @param array $options - passed to CommandTester::execute(), eg ['verbosity' => …]
	 *
	 * @return CommandTester
	 */
	protected function runConsoleCommand($command, array $input = [], array $options = [])
	{
		if (is_string($command) && !class_exists($command))
		{
			throw new \LogicException(
				"'$command' is not a class: runConsoleCommand() takes the command's class name, not"
				. " the name it is invoked by on the command line - eg"
				. " MyVendor\\MyAddOn\\Cli\\Command\\Thing::class."
			);
		}

		$instance = is_object($command) ? $command : new $command();

		if (!($instance instanceof Command))
		{
			throw new \LogicException(
				'Expected a ' . Command::class . ', got ' . get_debug_type($instance)
			);
		}

		if (!$instance->getName())
		{
			throw new \LogicException(
				get_class($instance) . ' has no name, so it cannot be run - a XenForo command sets'
				. ' one in configure().'
			);
		}

		$application = new Application('XenForo', \XF::$version);
		$application->setAutoExit(false);
		$application->add($instance);

		$tester = new CommandTester($instance);
		$tester->execute(['command' => $instance->getName()] + $input, $options);

		return $tester;
	}

	/**
	 * Assert that every console command class an add-on ships is one XenForo can load.
	 *
	 * XenForo loads every add-on's command classes just to list them, and it does so with
	 * class_exists(), which autoloads - so one command class that cannot load takes cmd.php down for
	 * the whole forum rather than for the add-on that owns it. The usual cause is a parent class that
	 * is not there, which a suite never notices because nothing else loads these classes.
	 *
	 * The walk mirrors XF\Cli\Runner: every .php file under the add-on's Cli/Command, recursively,
	 * turned into a class name by its path. It refuses when it finds nothing, since a walk over an
	 * empty directory otherwise reports every class as loadable.
	 *
	 * @param string|null $addOnId - defaults to the add-on the test class isolates, when it names one
	 *
	 * @return string[] the class names checked
	 */
	protected function assertConsoleCommandsLoad($addOnId = null)
	{
		$addOnId = $addOnId ?: $this->soleIsolatedAddOnId();
		$path = \XF::getAddOnDirectory() . \XF::$DS . str_replace('/', \XF::$DS, $addOnId) . \XF::$DS
			. 'Cli' . \XF::$DS . 'Command';

		if (!is_dir($path))
		{
			throw new \LogicException("'$addOnId' has no Cli/Command directory, at '$path'");
		}

		$classBase = str_replace('/', '\\', $addOnId) . '\Cli\Command';
		$classes = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

		foreach ($files AS $file)
		{
			if (!$file->isFile() || $file->getExtension() !== 'php')
			{
				continue;
			}

			$localPath = trim(str_replace([$path, '\\'], ['', '/'], $file->getPathname()), '/');
			$classes[] = $classBase . '\\' . preg_replace('/\.php$/', '', str_replace('/', '\\', $localPath));
		}

		if (!$classes)
		{
			throw new \LogicException(
				"No command classes found under '$path', so there is nothing to assert. Remove this"
				. ' assertion, or give it the add-on that owns the commands.'
			);
		}

		foreach ($classes AS $class)
		{
			$this->assertConsoleCommandLoads($class);
		}

		return $classes;
	}

	/**
	 * Assert that one console command class is one XenForo can load and run.
	 *
	 * Mirrors XF\Cli\Runner::isValidCommandClass(), which is protected, and adds the name - a
	 * command XenForo lists but cannot name is of no use to anybody.
	 *
	 * @param string $class
	 *
	 * @return void
	 */
	protected function assertConsoleCommandLoads($class)
	{
		try
		{
			$exists = class_exists($class);
		}
		catch (\Throwable $e)
		{
			// a missing parent class arrives here; XenForo's own listing would die on it
			throw new \LogicException(
				"'$class' cannot be loaded, so it would stop cmd.php for every add-on on the forum: "
				. $e->getMessage(),
				0,
				$e
			);
		}

		PHPUnit::assertTrue($exists, "'$class' does not exist, though its file is where XenForo looks");

		$reflection = new \ReflectionClass($class);

		PHPUnit::assertTrue(
			$reflection->isInstantiable(),
			"'$class' is not instantiable, so XenForo will not list it"
		);
		PHPUnit::assertTrue(
			$reflection->isSubclassOf(Command::class),
			"'$class' does not extend " . Command::class . ', so XenForo will not list it'
		);

		$instance = new $class();
		PHPUnit::assertNotEmpty(
			$instance->getName(),
			"'$class' has no name, so it cannot be run - a XenForo command sets one in configure()"
		);
	}

	/**
	 * @return string
	 */
	private function soleIsolatedAddOnId()
	{
		$ids = array_values(array_filter($this->app()->isolatedAddOnIds(), function ($id)
		{
			return $id !== 'None/None';
		}));

		if (count($ids) !== 1)
		{
			throw new \LogicException(
				'Name the add-on whose commands to check: $addonsToLoad names '
				. (count($ids) ? count($ids) . ' add-ons' : 'none') . ', so there is no single one to assume.'
			);
		}

		return $ids[0];
	}
}
