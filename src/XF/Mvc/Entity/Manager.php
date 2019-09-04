<?php namespace Hampel\Testing\XF\Mvc\Entity;

use Closure;
use Mockery;
use XF\Mvc\Entity\Manager as BaseManager;

class Manager extends BaseManager
{
	/** @var array $mockedFinders */
	protected $mockedFinders = [];

	/** @var array $mockedFinders */
	protected $mockedEntities = [];

	/**
	 * @param $identifier string
	 * @param Closure|null $mock
	 *
	 * @return Mockery\MockInterface
	 */
	public function mockRepository($identifier, Closure $mock = null)
	{
		$repositoryClass = \XF::stringToClass($identifier, '%s\Repository\%s');
		$repositoryClass = $this->extension->extendClass($repositoryClass, '\XF\Mvc\Entity\Repository');
		if (!$repositoryClass || !class_exists($repositoryClass))
		{
			throw new \LogicException("Could not find repository '$repositoryClass' for '$identifier'");
		}

		$args = [$repositoryClass, $mock];

		$repository = Mockery::mock(...array_filter($args));
		$this->repositories[$identifier] = $repository;

		return $repository;
	}

	/**
	 * @param string $shortName
	 * @param bool $includeDefaultWith
	 *
	 * @return \XF\Mvc\Entity\Finder
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
	 * @param $shortName string
	 * @param Closure|null $mock
	 *
	 * @return mixed|Mockery\MockInterface
	 */
	public function mockFinder($shortName, Closure $mock = null)
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

		$args = [$finderClass, $mock];

		$finder = Mockery::mock(...array_filter($args));

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
	 * @return \XF\Mvc\Entity\Entity
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
	 * @param $shortName string
	 * @param bool $inherit - set to true (default) to inherit from the mocked entity, or false to mock a standalone class
	 * @param Closure|null $mock
	 *
	 * @return Mockery\MockInterface
	 */
	public function mockEntity($shortName, $inherit = true, Closure $mock = null)
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

		$args = [$className, $mock];

		$entity = Mockery::mock(...array_filter($args));

		$this->mockedEntities[$shortName] = $entity;

		return $entity;
	}
}
