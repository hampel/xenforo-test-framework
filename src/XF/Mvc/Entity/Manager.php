<?php namespace Hampel\XF\Mvc\Entity;

use Mockery;
use XF\Mvc\Entity\Manager as BaseManager;

class Manager extends BaseManager
{
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

		$repository = Mockery::mock($repositoryClass, [$this, $identifier]);
		$this->repositories[$identifier] = $repository;

		return $repository;
	}
}
