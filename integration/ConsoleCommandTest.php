<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Integration\Fixtures\ProbeCommand;
use PHPUnit\Framework\ExpectationFailedException;
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

	public function test_a_command_class_that_loads_and_names_itself_is_accepted()
	{
		$this->assertConsoleCommandLoads(ProbeCommand::class);
	}

	public function test_a_class_that_cannot_load_is_refused_by_naming_the_consequence()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('stop cmd.php for every add-on on the forum');

		// its parent does not exist, which is what XenForo's own listing would die on
		$this->assertConsoleCommandLoads(Fixtures\Admin\Controller\ExtendsMissingProxy::class);
	}

	public function test_a_class_that_is_not_a_command_fails()
	{
		$this->expectException(ExpectationFailedException::class);

		$this->assertConsoleCommandLoads(\stdClass::class);
	}

	public function test_an_addon_with_no_command_directory_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('has no Cli/Command directory');

		$this->assertConsoleCommandsLoad('Nope/NotThere');
	}

	public function test_it_refuses_to_guess_the_addon_when_the_suite_isolates_none()
	{
		// integration/TestCase.php isolates ['None/None'], so there is no add-on to assume
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('Name the add-on whose commands to check');

		$this->assertConsoleCommandsLoad();
	}

	public function test_answers_reach_a_command_that_asks()
	{
		$tester = $this->runConsoleCommand(Fixtures\ConfirmingCommand::class, [], [
			'inputs' => ['something', 'yes'],
		]);

		$this->assertStringContainsString('DID THE THING', $tester->getDisplay());
	}

	public function test_a_non_interactive_run_stops_at_the_confirmation()
	{
		// the safe default for a destructive command: every question answers with its default, so
		// a bare question is null and a confirmation is false
		$tester = $this->runConsoleCommand(Fixtures\ConfirmingCommand::class, [], ['interactive' => false]);

		$this->assertStringContainsString('stopped at the confirmation', $tester->getDisplay());
		$this->assertStringNotContainsString('DID THE THING', $tester->getDisplay());
	}

	public function test_answering_no_stops_it_too()
	{
		$tester = $this->runConsoleCommand(Fixtures\ConfirmingCommand::class, [], [
			'inputs' => ['something', 'no'],
		]);

		$this->assertStringContainsString('stopped at the confirmation', $tester->getDisplay());
	}

	public function test_answers_with_interactive_false_are_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('ignore them');

		$this->runConsoleCommand(Fixtures\ConfirmingCommand::class, [], [
			'inputs' => ['yes'],
			'interactive' => false,
		]);
	}
}
