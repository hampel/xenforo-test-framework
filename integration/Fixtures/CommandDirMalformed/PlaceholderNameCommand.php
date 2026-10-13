<?php

namespace Hampel\Testing\Integration\Fixtures\CommandDirMalformed;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A command whose name carries an argument placeholder, which is the mistake the name guard
 * exists for. Symfony accepts the name, so this command runs - what it breaks is `cmd.php list`
 * and, once a sibling shares the prefix, reaching either of them at all.
 */
class PlaceholderNameCommand extends Command
{
	protected function configure()
	{
		$this->setName('probe:placeholder {subject}');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$output->writeln('it ran');

		return self::SUCCESS;
	}
}
