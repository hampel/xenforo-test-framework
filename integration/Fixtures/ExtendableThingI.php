<?php

namespace Hampel\Testing\Integration\Fixtures;

/**
 * A base class for the widening-refusal tests to resolve at runtime. Each of these can be resolved
 * only once per process - the resolution is cached and its XFCP proxy aliased - so one test owns
 * each of them.
 */
class ExtendableThingI
{
	public function describe()
	{
		return 'base';
	}
}
