<?php

namespace Hampel\Testing\Integration\Fixtures;

/**
 * Registered as extended in the database by one test and never extended in the process, which is
 * the state the assertion exists to catch. There is deliberately no Ext\ExtendableThingK.
 */
class ExtendableThingK
{
	public function describe()
	{
		return 'base';
	}
}
