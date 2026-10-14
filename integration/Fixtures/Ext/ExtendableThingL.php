<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingL extends XFCP_ExtendableThingL
{
	public function describe()
	{
		return 'first ' . parent::describe();
	}
}
