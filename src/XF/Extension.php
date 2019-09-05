<?php namespace Hampel\Testing\XF;

class Extension extends \XF\Extension
{
	public function extendClass($class, $fakeBaseClass = null)
	{
		return parent::extendClass($class, $fakeBaseClass);
	}
}
