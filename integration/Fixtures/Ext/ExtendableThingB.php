<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingB extends XFCP_ExtendableThingB
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
