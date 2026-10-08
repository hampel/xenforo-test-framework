<?php

namespace Hampel\Testing\Integration\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

/**
 * A command that confirms before acting, as a destructive XenForo command does - so the tests can
 * reach both the stopped-at-the-confirmation path and the confirmed one.
 */
class ConfirmingCommand extends Command
{
	protected function configure()
	{
		$this->setName('probe:confirm');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$helper = $this->getHelper('question');

		$bare = $helper->ask($input, $output, new Question('Type something: '));
		$output->writeln('bare question gave: ' . var_export($bare, true));

		$confirmed = $helper->ask($input, $output, new ConfirmationQuestion('Really? ', false));
		$output->writeln('confirmation gave: ' . var_export($confirmed, true));

		$output->writeln($confirmed ? 'DID THE THING' : 'stopped at the confirmation');

		return self::SUCCESS;
	}
}
