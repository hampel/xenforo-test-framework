<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

class ExtendableThingH extends XFCP_ExtendableThingH
{
	public function describe()
	{
		return 'extended ' . parent::describe();
	}
}
