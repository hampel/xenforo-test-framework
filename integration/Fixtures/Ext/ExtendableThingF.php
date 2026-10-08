<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingF extends XFCP_ExtendableThingF
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
