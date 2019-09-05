<?php namespace Tests;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * You should not need to modify this unless you have specific functionality you need to change before
     * setting up the XF App
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
