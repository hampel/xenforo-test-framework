<?php

namespace Hampel\Testing\Integration\Fixtures;

/**
 * A base class for the isolation-limit test to extend at runtime. A class can only be extended once
 * in a run, so each test that extends something needs a pair of its own.
 */
class ExtendableThingF
{
	public function describe()
	{
		return 'base';
	}
}
