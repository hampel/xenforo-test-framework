<?php namespace Hampel\Testing\Concerns;

use Mockery;
use XF\Phrase;
use XF\Language;

trait InteractsWithLanguage
{
	private $languageMocked = false;

	protected function setUpLanguage()
	{
        $this->beforeApplicationDestroyed(function () {
            $this->restoreLanguage();
        });
	}

	/**
	 * Allow us to easily mock the phrase/language system to avoid database lookups and rendering phrases. This is
	 * especially useful when dealing with error messages which include phrases that may be variable.
	 *
	 * @param $key - the phrase_id
	 * @param null $parameters - optional - parameters that are expected to be passed to the phrase
	 * @param null $response - optional - the response that should be returned
	 *
	 * @return Phrase
	 */
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

	private function restoreLanguage()
	{
		if ($this->languageMocked)
		{
			\XF::setLanguage($this->app()->language());
			$this->languageMocked = false;
		}
	}
}
