<?php namespace Hampel\Testing\XF\Mvc\Entity;

use Mockery;
use XF\Mvc\Entity\Manager as BaseManager;

class Manager extends BaseManager
{
	/** @var array $mockedFinders */
	protected $mockedFinders = [];

	/** @var array $mockedFinders */
	protected $mockedEntities = [];

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

	/**
	 * @param $shortName
	 *
	 * @return XF\Mvc\Entity\Finder
	 */
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

	/**
	 * Instantiates the named entity with the specified values and relations.
	 *
	 * @param string $shortName
	 * @param array $values Values for the columns in the entity, in source encoded form
	 * @param array $relations
	 * @param int $options Bit field of the INSTANTIATE_* options
	 *
	 * @return null|Entity
	 *
	 * @throws \LogicException
	 */
	public function instantiateEntity($shortName, array $values = [], array $relations = [], $options = 0)
	{
		if ($shortName && isset($this->mockedEntities[$shortName]))
		{
			return $this->mockedEntities[$shortName];
		}

		return parent::instantiateEntity($shortName, $values, $relations, $options);
	}

	/**
	 * @param $shortName
	 *
	 * @return XF\Mvc\Entity\Entity
	 */
	public function mockEntity($shortName, $inherit = true)
	{
		if ($inherit)
		{
			$className = $this->getEntityClassName($shortName);
		}
		else
		{
			// use the suffix of the short name as the entity name since we're not mocking a real class
			$parts = explode(':', $shortName, 3);
			$className = array_pop($parts);
		}

		$entity = Mockery::mock($className);

		$this->mockedEntities[$shortName] = $entity;

		return $entity;
	}
}
