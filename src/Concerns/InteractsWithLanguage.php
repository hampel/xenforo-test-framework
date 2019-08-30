<?php namespace Hampel\Testing\Concerns;

use Mockery;
use XF\Phrase;
use XF\Language;

trait InteractsWithLanguage
{
	protected $languageMocked = false;

	protected function setUpLanguage()
	{
        $this->beforeApplicationDestroyed(function () {
            $this->restoreLanguage();
        });
	}

	protected function expectPhrase($key, $parameters = null, $response = null)
	{
		if (!$this->languageMocked)
		{
			\XF::setLanguage(Mockery::mock(Language::class));
	        $this->languageMocked = true;
		}

		$phrase = Mockery::mock(Phrase::class);
		$phrase->shouldReceive('__toString')->andReturn($response ?? $key);
		$phrase->shouldReceive('render')->andReturn($response ?? $key);

		\XF::language()
		   ->shouldReceive('phrase')
		   ->once()
		   ->with($key, $parameters ?? Mockery::any(), Mockery::any(), Mockery::any())
		   ->andReturn($phrase);

		return $phrase;
	}

	protected function restoreLanguage()
	{
		if ($this->languageMocked)
		{
			\XF::setLanguage($this->app()->language());
			$this->languageMocked = false;
		}
	}
}
