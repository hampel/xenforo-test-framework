<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;

/**
 * runDeferred() is the supported way to run work queued with \XF::runOnce() outside a dispatch
 * or a job - the case being a test that saves or deletes an entity directly, where nothing else
 * runs the queue and teardown discards it.
 *
 * The passkey test is the documented example: XF:Passkey queues the update of the user's passkey
 * provider on save and on delete, and the row it writes is the thing a test of either wants.
 */
class RunDeferredTest extends TestCase
{
	use UsesDatabaseTransactions;

	public function test_queued_work_runs_and_its_keys_come_back_in_queue_order()
	{
		$ran = [];

		\XF::runOnce('zzSecond', function () use (&$ran)
		{
			$ran[] = 'second';
		});
		\XF::runOnce('zzFirst', function () use (&$ran)
		{
			$ran[] = 'first';
		});

		$this->assertSame(['zzSecond', 'zzFirst'], $this->runDeferred());
		$this->assertSame(['second', 'first'], $ran);
	}

	public function test_nothing_queued_runs_nothing_and_returns_an_empty_list()
	{
		$this->assertSame([], $this->runDeferred());
	}

	public function test_work_queued_by_deferred_work_runs_too_and_is_not_among_the_keys()
	{
		$ran = false;

		\XF::runOnce('zzOuter', function () use (&$ran)
		{
			\XF::runOnce('zzInner', function () use (&$ran)
			{
				$ran = true;
			});
		});

		$this->assertSame(['zzOuter'], $this->runDeferred());
		$this->assertTrue($ran, 'work queued by deferred work should run in the same call');
	}

	public function test_an_exception_in_deferred_work_reaches_the_test()
	{
		// XenForo's own loop logs the exception and carries on; the fake keeps that log off the forum
		$this->fakesErrors();

		\XF::runOnce('zzThrows', function ()
		{
			throw new \RuntimeException('deferred work failed');
		});

		try
		{
			$this->runDeferred();
			$this->fail('the exception should have reached the test');
		}
		catch (\RuntimeException $e)
		{
			$this->assertSame('deferred work failed', $e->getMessage());
		}

		$this->assertExceptionLogged(\RuntimeException::class);
	}

	public function test_a_passkey_enables_the_provider_only_once_the_deferred_work_runs()
	{
		$user = $this->createUserAccount('zzpasskey');
		$other = $this->createUserAccount('zzpasskeyother');

		foreach ([$user, $other] AS $owner)
		{
			$this->createEntity('XF:Passkey', [
				'user_id' => $owner->user_id,
				'name' => 'Probe key',
				'credential_id' => 'zz-credential-' . $owner->user_id,
				'credential_public_key' => 'zz-public-key',
				// nullable on the entity, NOT NULL in the table: core always sets it
				'aaguid' => str_repeat('0', 32),
			]);
		}

		$row = ['user_id' => $user->user_id, 'provider_id' => 'passkey'];
		$otherRow = ['user_id' => $other->user_id, 'provider_id' => 'passkey'];

		$this->assertDatabaseMissing('xf_user_tfa', $row);

		$this->assertSame(
			['updateUserPasskeyProvider-' . $user->user_id, 'updateUserPasskeyProvider-' . $other->user_id],
			$this->runDeferred()
		);

		$this->assertDatabaseHas('xf_user_tfa', $row);
		$this->assertDatabaseHas('xf_user_tfa', $otherRow);

		// the save and the delete are two requests in production: the provider entity cached by the
		// first still holds its user entries as they were before the row was written
		$this->app()->em()->clearEntityCache();

		$passkey = $this->app()->finder('XF:Passkey')->where('user_id', $user->user_id)->fetchOne();
		$passkey->delete();

		$this->assertDatabaseHas('xf_user_tfa', $row, 'the provider is disabled by deferred work, not by the delete');

		$this->runDeferred();

		$this->assertDatabaseMissing('xf_user_tfa', $row);
		$this->assertDatabaseHas('xf_user_tfa', $otherRow, 'the other user keeps theirs - the control');
	}
}
