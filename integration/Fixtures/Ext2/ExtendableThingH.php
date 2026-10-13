<?php

namespace Hampel\Testing\Integration\Fixtures\Ext2;

class ExtendableThingH extends XFCP_ExtendableThingH
{
	public function describe()
	{
		return 'second ' . parent::describe();
	}
}
