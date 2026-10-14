<?php

namespace Hampel\Testing\Integration\Fixtures\Ext2;

class ExtendableThingL extends XFCP_ExtendableThingL
{
	public function describe()
	{
		return 'second ' . parent::describe();
	}
}
