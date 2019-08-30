<?php namespace Hampel\Testing\XF\Mvc\Entity;

use Mockery;
use XF\Mvc\Entity\Manager as BaseManager;

class Manager extends BaseManager
{
	/** @var array $mockedFinders */
	protected $mockedFinders = [];

	/**
	 * @param string $identifier
	 *
	 * @return Repository
	 */
	public function mockRepository($identifier)
	{
		$repositoryClass = \XF::stringToClass($identifier, '%s\Repository\%s');
		$repositoryClass = $this->extension->extendClass($repositoryClass, '\XF\Mvc\Entity\Repository');
		if (!$repositoryClass || !class_exists($repositoryClass))
		{
			throw new \LogicException("Could not find repository '$repositoryClass' for '$identifier'");
		}

		$repository = Mockery::mock($repositoryClass);
		$this->repositories[$identifier] = $repository;

		return $repository;
	}

	/**
	 * @param string $shortName
	 * @param bool $includeDefaultWith
	 *
	 * @return Finder
	 */
	public function getFinder($shortName, $includeDefaultWith = true)
	{
		if ($shortName && isset($this->mockedFinders[$shortName]))
		{
			return $this->mockedFinders[$shortName];
		}

		return parent::getFinder($shortName, $includeDefaultWith);
	}

	public function mockFinder($shortName)
	{
		if ($shortName && isset($this->mockedFinders[$shortName]))
		{
			return $this->mockedFinders[$shortName];
		}

		$finderClass = \XF::stringToClass($shortName, '%s\Finder\%s');
		$finderClass = $this->extension->extendClass($finderClass, '\XF\Mvc\Entity\Finder');
		if (!$finderClass || !class_exists($finderClass))
		{
			$finderClass = '\XF\Mvc\Entity\Finder';
		}

		$finder = Mockery::mock($finderClass);

		$this->mockedFinders[$shortName] = $finder;

		return $finder;
	}
}
