<?php

namespace Hampel\Testing\Integration\Fixtures;

/**
 * A base class for the class-extension assertion to extend at runtime. Kept out of the tests
 * themselves because a class extending an XFCP proxy can only load after XenForo has aliased it.
 */
class ExtendableThingJ
{
	public function describe()
	{
		return 'base';
	}
}
