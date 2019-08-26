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
		$dir = '../../../..';
		require($dir . '/src/XF.php');

		\XF::start($dir);

		return \XF::setupApp('Hampel\Testing\XF\App');
    }
}
