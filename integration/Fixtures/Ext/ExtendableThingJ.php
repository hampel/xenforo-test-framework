<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingJ extends XFCP_ExtendableThingJ
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
