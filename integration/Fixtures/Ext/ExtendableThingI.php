<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingI extends XFCP_ExtendableThingI
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
