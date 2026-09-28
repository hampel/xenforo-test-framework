<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingE extends XFCP_ExtendableThingE
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
