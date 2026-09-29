<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Integration\Fixtures\ProbeCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * runConsoleCommand(), and that a static a test sets is put back afterwards.
 */
class ConsoleCommandTest extends TestCase
{
	public function test_a_command_runs_and_reports_its_output_and_status()
	{
		$tester = $this->runConsoleCommand(ProbeCommand::class, ['subject' => 'world']);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertStringContainsString('hello world', $tester->getDisplay());
	}

	public function test_a_failing_command_reports_its_exit_code()
	{
		$tester = $this->runConsoleCommand(ProbeCommand::class, ['--fail' => true]);

		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
	}

	public function test_the_command_reaches_the_application()
	{
		$this->setOption('boardTitle', 'A Test Forum');

		$tester = $this->runConsoleCommand(ProbeCommand::class, [], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

		$this->assertStringContainsString('the forum is A Test Forum', $tester->getDisplay());
	}

	public function test_an_instance_can_be_passed_where_the_command_needs_constructing()
	{
		$tester = $this->runConsoleCommand(new ProbeCommand(), ['subject' => 'instance']);

		$this->assertStringContainsString('hello instance', $tester->getDisplay());
	}

	public function test_something_that_is_not_a_command_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Expected a');

		$this->runConsoleCommand(\stdClass::class);
	}

	public function test_a_static_a_test_sets_is_restored_afterwards()
	{
		$this->setStaticProperty(\XF::class, 'versionId', 999999999);

		$this->assertSame(999999999, \XF::$versionId);
	}

	public function test_b_the_static_is_back_for_the_next_test()
	{
		$this->assertNotSame(999999999, \XF::$versionId);
	}

	public function test_a_command_name_is_refused_with_the_class_it_should_have_been_given()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage("takes the command's class name");

		$this->runConsoleCommand('probe:thing');
	}
}
