<?php

namespace Hampel\Testing\Concerns;

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
				'Expected a ' . Command::class . ', got '
				. (is_object($command) ? get_class($command) : (string) $command)
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
}
