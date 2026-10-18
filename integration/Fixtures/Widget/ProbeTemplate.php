<?php

namespace Hampel\Testing\Integration\Fixtures\Widget;

use XF\Widget\AbstractWidget;

/**
 * A widget that renders whichever template its options name, so one fixture covers every way a
 * widget render can come back empty: a template that exists, one that does not, one that errors,
 * and - with no `zzTemplate` at all - a renderer carrying no template name.
 */
class ProbeTemplate extends AbstractWidget
{
	public function render()
	{
		return $this->renderer((string) ($this->options['zzTemplate'] ?? ''), [
			'zzValue' => $this->options['zzValue'] ?? 'unset',
		]);
	}
}
