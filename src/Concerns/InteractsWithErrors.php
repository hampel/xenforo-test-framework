<?php namespace Hampel\Testing\Concerns;

use XF\Error;

trait InteractsWithErrors
{
	protected function setUpErrors()
	{
        $this->mock('error', Error::class);
	}
}
