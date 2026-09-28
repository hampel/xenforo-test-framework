<?php

namespace Hampel\Testing\Integration\Fixtures\Ext2;

class ExtendableThingC extends XFCP_ExtendableThingC
{
	public function describe()
	{
		return 'second ' . parent::describe();
	}
}
