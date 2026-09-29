<?php

namespace Hampel\Testing\Integration\Fixtures\Admin\Controller;

/**
 * A class extension whose XFCP proxy has not been declared - which is the state every extension is
 * in until XenForo resolves the class it extends. Loading this raises an Error, the way naming an
 * extension belonging to an add-on the test did not load does. Named as a controller because
 * \XF::stringToClass() refuses anything else.
 */
class ExtendsMissingProxy extends XFCP_ExtendsMissingProxy
{
}
