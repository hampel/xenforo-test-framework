<?php

namespace Hampel\Testing\Integration\Fixtures\CommandDir;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GoodCommand extends Command
{
	protected function configure()
	{
		$this->setName('probe:good');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		return self::SUCCESS;
	}
}
