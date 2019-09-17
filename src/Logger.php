<?php namespace Hampel\Testing;

use XF\Logger as BaseLogger;

class Logger extends BaseLogger
{
	protected $actions = [];
	protected $changes = [];

	public function logModeratorAction($type, $content, $action, array $params = [], $throw = true)
	{
		$this->actions[] = compact('type', 'content', 'action', 'params');
	}

	public function logModeratorChange($type, $content, $field, $throw = true)
	{
		$this->changes[] = compact('type', 'content', 'field');
	}

	public function logModeratorChanges($type, \XF\Mvc\Entity\Entity $content, $throw = true)
	{
		$field = null;
		$this->changes[] = compact('type', 'content', 'field');
	}

	public function getActions()
	{
		return $this->actions;
	}

	public function getChanges()
	{
		return $this->changes;
	}
}