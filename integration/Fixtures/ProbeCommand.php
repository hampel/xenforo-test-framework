<?php

namespace Hampel\Testing\Integration\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A console command for the runConsoleCommand() tests - what an add-on's own command looks like,
 * without depending on one being installed.
 */
class ProbeCommand extends Command
{
	protected function configure()
	{
		$this->setName('probe:echo')
			->addArgument('subject', InputArgument::OPTIONAL, '', 'nobody')
			->addOption('fail', null, InputOption::VALUE_NONE);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('hello ' . $input->getArgument('subject'));

		if ($output->isVerbose())
		{
			$output->writeln('and the forum is ' . \XF::options()->boardTitle);
		}

		return $input->getOption('fail') ? Command::FAILURE : Command::SUCCESS;
	}
}
