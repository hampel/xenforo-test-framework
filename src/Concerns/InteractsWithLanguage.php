<?php

namespace Hampel\Testing\Concerns;

use Mockery\MockInterface;
use XF\Language;
use XF\Phrase;

trait InteractsWithLanguage
{
	/**
	 * The mock installed by expectPhrase(), held here rather than fetched back through
	 * XF::language() - that returns the real Language type, which has no expectation methods.
	 *
	 * @var (Language&MockInterface)|null
	 */
	private $languageMock;

	protected function setUpLanguage()
	{
		$this->beforeApplicationDestroyed(function ()
		{
			$this->restoreLanguage();
		});
	}

	/**
	 * Allow us to easily mock the phrase/language system to avoid database lookups and rendering phrases. This is
	 * especially useful when dealing with error messages which include phrases that may be variable.
	 *
	 * @param string $key - the phrase_id
	 * @param array|null $parameters - optional - parameters that are expected to be passed to the phrase
	 * @param string|null $response - optional - the response that should be returned
	 *
	 * @return Phrase
	 */
	protected function expectPhrase($key, $parameters = null, $response = null)
	{
		if ($this->languageMock === null)
		{
			$this->languageMock = \Mockery::mock(Language::class);
			\XF::setLanguage($this->languageMock);
		}

		$phrase = \Mockery::mock(Phrase::class);
		$phrase->shouldReceive('__toString')->andReturn($response ?? $key);
		$phrase->shouldReceive('render')->andReturn($response ?? $key);

		$this->languageMock
		   ->shouldReceive('phrase')
		   ->once()
		   ->with($key, $parameters ?? \Mockery::any(), \Mockery::any(), \Mockery::any())
		   ->andReturn($phrase);

		return $phrase;
	}

	private function restoreLanguage()
	{
		if ($this->languageMock !== null)
		{
			\XF::setLanguage($this->app()->language());
			$this->languageMock = null;
		}
	}
}
