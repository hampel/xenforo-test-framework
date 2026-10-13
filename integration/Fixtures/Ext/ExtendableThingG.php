<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingG extends XFCP_ExtendableThingG
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
