<?php

namespace Hampel\Testing\Integration\Fixtures\Widget;

use XF\Widget\AbstractWidget;

/**
 * A widget that decides not to show itself, which is XenForo's own idiom for "not applicable to
 * this visitor" - seven core widgets do the same. It returns a bare string rather than a renderer,
 * which is what renderWidgetHtml() reads to tell a deliberate empty render from a broken one.
 *
 * The namespace carries a `\Widget\` segment because \XF::stringToClass() validates a definition
 * class against the `%s\Widget\%s` formatter and refuses anything else before it is ever loaded.
 */
class ProbeDeclines extends AbstractWidget
{
	public function render()
	{
		return '';
	}
}
