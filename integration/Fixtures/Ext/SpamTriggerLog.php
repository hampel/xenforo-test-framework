<?php

namespace Hampel\Testing\Integration\Fixtures\Ext;

/**
 * An extension of a core entity, so the isolation-limit test can prove its short-name resolution
 * refuses a class that IS extended. XenForo derives the proxy name from this class's own namespace.
 */
class SpamTriggerLog extends XFCP_SpamTriggerLog
{
}
