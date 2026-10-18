<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Hampel\Testing\Integration\Fixtures\Widget\ProbeDeclines;
use Hampel\Testing\Integration\Fixtures\Widget\ProbeTemplate;

/**
 * renderWidgetHtml(), and the property that is the whole point of it: an empty widget render has
 * four causes, one of which is correct and three of which are faults.
 *
 * Every test here owns its subject - its own widget definitions swapped into the definition cache,
 * and its own templates created under the transaction - rather than rendering a widget the forum
 * happens to have. A core widget would do for the showing case, but on this install a sibling's
 * template modification raises an error on one, so the subject would carry somebody else's state.
 */
class WidgetRenderTest extends TestCase
{
	use UsesDatabaseTransactions;

	private function registerProbeWidgets()
	{
		$this->swap(['widget', 'widgetDefinition'], [
			'zzProbeTemplate' => [
				'definition_id' => 'zzProbeTemplate',
				'definition_class' => ProbeTemplate::class,
			],
			'zzProbeDeclines' => [
				'definition_id' => 'zzProbeDeclines',
				'definition_class' => ProbeDeclines::class,
			],
		]);
	}

	private function createProbeTemplate($content)
	{
		$title = 'zz_widget_probe_' . substr(md5(uniqid('', true)), 0, 8);

		$this->createEntity('XF:Template', [
			'style_id' => 0,
			'type' => 'public',
			'title' => $title,
			'template' => $content,
			'addon_id' => '',
		]);

		return $title;
	}

	public function test_a_widget_renders_its_template_with_the_options_it_was_given()
	{
		$this->registerProbeWidgets();
		$title = $this->createProbeTemplate('[{$title}][{$zzValue}]');

		$html = $this->renderWidgetHtml(ProbeTemplate::class, [
			'zzTemplate' => $title,
			'zzValue' => 'from the options',
			'_title' => 'Zz Probe Title',
		]);

		$this->assertSame('[Zz Probe Title][from the options]', $html);
	}

	public function test_a_widget_that_declines_to_show_renders_nothing()
	{
		$this->registerProbeWidgets();
		$title = $this->createProbeTemplate('[{$zzValue}]');

		// the positive half, beside the negative: the same call shape against a widget that does
		// show produces markup, so the empty string below is the decline and not a broken subject
		$this->assertSame(
			'[unset]',
			$this->renderWidgetHtml(ProbeTemplate::class, ['zzTemplate' => $title])
		);

		$this->assertSame('', $this->renderWidgetHtml(ProbeDeclines::class));
	}

	public function test_a_widget_whose_template_does_not_exist_is_refused()
	{
		$this->registerProbeWidgets();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('does not exist in this forum');

		$this->renderWidgetHtml(ProbeTemplate::class, ['zzTemplate' => 'zz_widget_probe_absent']);
	}

	public function test_a_widget_that_names_no_template_is_refused()
	{
		$this->registerProbeWidgets();

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('names no title');

		$this->renderWidgetHtml(ProbeTemplate::class);
	}

	public function test_a_widget_whose_template_errors_is_refused()
	{
		$this->fakesErrors();
		$this->registerProbeWidgets();
		$title = $this->createProbeTemplate('{{ $nope.doesNotExist() }}');

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('produced nothing, because it raised');

		$this->renderWidgetHtml(ProbeTemplate::class, ['zzTemplate' => $title]);
	}

	public function test_a_configured_widget_renders_by_its_key_with_its_stored_options()
	{
		$this->registerProbeWidgets();
		$title = $this->createProbeTemplate('[{$zzValue}]');

		$this->swap(['widget', 'widgetCache'], [
			'zzProbePosition' => [
				'zz_probe_key' => [
					'widget_id' => 9001,
					'widget_key' => 'zz_probe_key',
					'definition_id' => 'zzProbeTemplate',
					'definition_class' => ProbeTemplate::class,
					'positions' => [],
					'options' => ['zzTemplate' => $title, 'zzValue' => 'from the stored options'],
				],
			],
		]);

		// the key form merges the caller's options over the ones the widget was configured with,
		// where the definition-class form discards them - so the stored template name survives
		// while the stored value is overridden
		$this->assertSame(
			'[from the call]',
			$this->renderWidgetHtml('zz_probe_key', ['zzValue' => 'from the call'])
		);

		$this->assertSame(
			'[from the stored options]',
			$this->renderWidgetHtml('zz_probe_key')
		);
	}

	public function test_the_style_a_render_needs_is_installed_for_the_caller()
	{
		// fakesErrors() because the control for this test - the style installation removed - renders
		// a template that reads a null $xf.style, and XenForo logs that to the forum's real error
		// log rather than raising it
		$this->fakesErrors();
		$this->registerProbeWidgets();
		$title = $this->createProbeTemplate('[{$xf.style.style_id}]');

		// the fallback style is id 0, and a template handed a null $xf.style renders '[]' here
		// instead - so this discriminates rather than merely passing
		$this->assertSame(
			'[0]',
			$this->renderWidgetHtml(ProbeTemplate::class, ['zzTemplate' => $title])
		);
	}

	public function test_the_short_class_name_form_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('carries a colon');

		$this->renderWidgetHtml('XF\Widget:MembersOnline');
	}

	public function test_an_empty_identifier_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('needs a widget definition class');

		$this->renderWidgetHtml('');
	}
}
