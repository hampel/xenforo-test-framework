<?php namespace Hampel\Testing\Concerns;

use XF\Language;

trait InteractsWithLanguage
{
	protected function setUpLanguage()
	{
        $this->mock('language', Language::class, function () {

        });
	}
}
