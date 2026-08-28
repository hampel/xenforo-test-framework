<?php

namespace Hampel\Testing\Integration;

trait CreatesApplication
{
	public function createApplication()
	{
		require_once "{$this->rootDir}/src/XF.php";

		\XF::start($this->rootDir);

		$options['xf-addons'] = $this->addonsToLoad ?: [];

		return \XF::setupApp('Hampel\Testing\App', $options);
	}
}
