<?php namespace Tests;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \XF\App
     */
    public function createApplication()
    {
		require_once("{$this->rootDir}/src/XF.php");

		\XF::start($this->rootDir);

		return \XF::setupApp('Hampel\Testing\XF\App');
    }
}
