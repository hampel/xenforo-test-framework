<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingD extends XFCP_ExtendableThingD
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
