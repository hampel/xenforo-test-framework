<?php

namespace Hampel\Testing\Integration\Fixtures\CommandDirBroken;

/**
 * Its parent does not exist, so loading it is a fatal - which is what takes cmd.php down for every
 * add-on on the forum, and the one case the walk must never skip.
 */
class BrokenCommand extends ThisParentDoesNotExist
{
}
