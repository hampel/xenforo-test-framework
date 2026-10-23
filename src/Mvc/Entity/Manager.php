<?php

namespace Hampel\Testing\Mvc\Entity;

use Mockery;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Manager as BaseManager;

class Manager extends BaseManager
{
	/** @var array $mockedFinders */
	protected $mockedFinders = [];

	/** @var array $mockedFinders */
	protected $mockedEntities = [];

	/**
	 * @param $identifier string
	 * @param \Closure|null $mock
	 *
	 * @return Mockery\MockInterface
	 */
	public function mockRepository($identifier, ?\Closure $mock = null)
	{
		// getRepository() normalises the identifier before it looks in $this->repositories, so the
		// mock has to be stored under the normalised key. Without this, a spelling XenForo accepts
		// everywhere else - 'XF:UserRepository', or the full class name - registers a mock that
		// nothing ever looks up: the real repository runs and every expectation goes unmet in
		// silence. Mirror XF's own normalisation exactly, including its trailing-suffix trim.
		$identifier = \XF::classToString($identifier, '%s\Repository\%s');
		if (substr($identifier, -10) === 'Repository')
		{
			$identifier = substr($identifier, 0, -10);
		}

		$repositoryClass = \XF::stringToClass($identifier, '%s\Repository\%s');
		$repositoryClass = $this->extension->extendClass($repositoryClass, '\XF\Mvc\Entity\Repository');
		if (!$repositoryClass || !class_exists($repositoryClass))
		{
			throw new \LogicException("Could not find repository '$repositoryClass' for '$identifier'");
		}

		$args = [$repositoryClass, $mock];

		$repository = \Mockery::mock(...array_filter($args));
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
		if ($shortName && $this->mockedFinders)
		{
			$key = $this->finderShortName($shortName);
			if (isset($this->mockedFinders[$key]))
			{
				return $this->mockedFinders[$key];
			}
		}

		return parent::getFinder($shortName, $includeDefaultWith);
	}

	/**
	 * The entity short name a finder is known by, from any spelling XenForo's getFinder() accepts:
	 * the short name, the finder class, or the entity class.
	 *
	 * getFinder() normalises its argument before it does anything else, so a mock has to be stored
	 * and looked up under the same normalised key. Without this a mock registered as 'XF:User' is
	 * never consulted by code that asks for \XF\Finder\UserFinder::class - XenForo's own idiom -
	 * and the real finder runs with every expectation unmet in silence. Mirror XF's normalisation
	 * exactly, including its trailing-suffix trim.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	public function finderShortName($name)
	{
		$name = (string) $name;

		try
		{
			if (strpos($name, ':') === false && strpos($name, '\\Entity\\') !== false)
			{
				return \XF::classToString($name, '%s\Entity\%s');
			}

			$name = \XF::classToString($name, '%s\Finder\%s');
		}
		catch (\InvalidArgumentException $e)
		{
			// XenForo 2.3 refuses a name it cannot turn into a finder class, from a depth that names
			// neither the test nor the argument. A name that resolves to no class gets the base
			// finder from mockFinder(); a name of the wrong shape is a mistake in the test, refused
			throw new \LogicException(
				"mockFinder() needs an entity short name of the form 'Vendor:Name', an entity class"
				. " name or a finder class name - '" . $name . "' is none of them.",
				0,
				$e
			);
		}

		if (substr($name, -6) === 'Finder')
		{
			$name = substr($name, 0, -6);
		}

		return $name;
	}

	/**
	 * @param string $shortName - the entity short name, the finder class or the entity class
	 * @param \Closure|null $mock
	 *
	 * @return mixed|Mockery\MockInterface
	 */
	public function mockFinder($shortName, ?\Closure $mock = null)
	{
		$shortName = $this->finderShortName($shortName);

		if (isset($this->mockedFinders[$shortName]))
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

		$finder = \Mockery::mock(...array_filter($args));

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
	 * @return Entity
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
	 * @param \Closure|null $mock
	 *
	 * @return Mockery\MockInterface
	 */
	public function mockEntity($shortName, $inherit = true, ?\Closure $mock = null)
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

		$entity = \Mockery::mock(...array_filter($args));

		$this->mockedEntities[$shortName] = $entity;

		return $entity;
	}
}
