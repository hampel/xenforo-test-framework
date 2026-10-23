<?php

namespace Hampel\Testing\Integration\Fixtures\PHPStan;

use Hampel\Testing\Integration\TestCase;
use XF\Entity\User;

/**
 * Analysed by PhpStanEntityHelpersTest, never run: each marked line is one shape of argument to
 * makeEntity() or createEntity(), and the marker says whether PHPStan should report it.
 */
class EntityHelpersFixture extends TestCase
{
	public function shapes()
	{
		$token = $this->makeEntity('XF:OAuthToken');
		\XF::setAccessToken($token); // expect: clean

		$user = $this->createEntity('XF:User', ['username' => 'probe']);
		$user->isAwaitingEmailConfirmation(); // expect: clean

		$byClass = $this->makeEntity(User::class);
		$byClass->isAwaitingEmailConfirmation(); // expect: clean

		$name = 'XF:User';
		$held = $this->makeEntity($name);
		$held->isAwaitingEmailConfirmation(); // expect: clean

		$unknown = $this->makeEntity('XF:NoSuchEntity');
		$unknown->isAwaitingEmailConfirmation(); // expect: reported

		$token->noSuchMethod(); // expect: reported as XF\Entity\OAuthToken::noSuchMethod()
	}
}
