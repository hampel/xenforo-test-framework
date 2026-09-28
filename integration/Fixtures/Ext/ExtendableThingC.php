<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingC extends XFCP_ExtendableThingC
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
