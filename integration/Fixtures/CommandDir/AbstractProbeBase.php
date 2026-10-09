<?php

namespace Hampel\Testing\Integration\Fixtures\CommandDir;

use Symfony\Component\Console\Command\Command;

/**
 * An abstract base beside the commands, which is XenForo's own layout - core has
 * XF\Cli\Command\AbstractCommand. The runner skips it, so the walk must too.
 */
abstract class AbstractProbeBase extends Command
{
}
