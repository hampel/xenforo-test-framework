<?php

namespace Hampel\Testing\Integration\Fixtures;

/**
 * Extended twice in one process, so the resolution is the most derived class and the add-on whose
 * extension sits lower in the chain is an ancestor of it.
 */
class ExtendableThingL
{
	public function describe()
	{
		return 'base';
	}
}
