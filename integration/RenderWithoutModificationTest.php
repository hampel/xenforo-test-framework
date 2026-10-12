<?php

namespace Hampel\Testing\Integration;

/**
 * A test that asserts a template modification put something on the page needs the other half:
 * the same render without it. These read what the forum has, so the happy path skips on an
 * install with no applying modification rather than passing vacuously.
 */
class RenderWithoutModificationTest extends TestCase
{
	/**
	 * An enabled modification that is actually applying, with the template it modifies.
	 *
	 * @return array|null - [key, type, template]
	 */
	private function applyingModification()
	{
		$row = $this->app()->db()->fetchRow(
			'SELECT m.modification_key, m.type, m.template
				FROM xf_template_modification AS m
				INNER JOIN xf_template_modification_log AS l
					ON (l.modification_id = m.modification_id AND l.apply_count > 0)
				INNER JOIN xf_template AS t
					ON (t.type = m.type AND t.title = m.template AND t.style_id = 0)
				WHERE m.enabled = 1 AND m.type = \'public\'
				ORDER BY m.modification_key
				LIMIT 1'
		);

		return $row ?: null;
	}

	public function test_leaving_a_modification_out_changes_the_render()
	{
		$modification = $this->applyingModification();

		if (!$modification)
		{
			$this->markTestSkipped(
				'This forum has no enabled public template modification that applies, so there is'
				. ' nothing to leave out.'
			);
		}

		$this->fakesErrors();

		$name = $modification['type'] . ':' . $modification['template'];

		$with = $this->renderTemplate($name);
		$without = $this->renderTemplateWithoutModification($modification['modification_key']);

		$this->assertNotSame(
			$with,
			$without,
			"leaving {$modification['modification_key']} out of {$name} changed nothing"
		);

		// and the pair DOCS recommends, on markup derived from the two renders rather than
		// hard-coded, so it holds on any forum: something the modification put there
		$needle = $this->longestLineOnlyIn($with, $without);

		if ($needle !== null)
		{
			$this->assertSee($with, $needle, false);
			$this->assertDontSee($without, $needle, false);
		}
	}

	/**
	 * The longest worthwhile line present in one render and absent from the other.
	 *
	 * @param string $present
	 * @param string $absent
	 *
	 * @return string|null
	 */
	private function longestLineOnlyIn($present, $absent)
	{
		$candidates = [];

		foreach (explode("\n", $present) AS $line)
		{
			$line = trim($line);

			if (strlen($line) >= 12 && strpos($absent, $line) === false)
			{
				$candidates[] = $line;
			}
		}

		if (!$candidates)
		{
			return null;
		}

		usort($candidates, function ($a, $b)
		{
			return strlen($b) <=> strlen($a);
		});

		return $candidates[0];
	}

	/**
	 * The injected template is removed afterwards, so the next render of the same template in the
	 * same test is the forum's own again. Without that the helper would quietly replace the
	 * template for the rest of the test.
	 */
	public function test_the_real_template_renders_again_afterwards()
	{
		$modification = $this->applyingModification();

		if (!$modification)
		{
			$this->markTestSkipped('This forum has no applying public template modification.');
		}

		$this->fakesErrors();

		$name = $modification['type'] . ':' . $modification['template'];

		$before = $this->renderTemplate($name);
		$this->renderTemplateWithoutModification($modification['modification_key']);
		$after = $this->renderTemplate($name);

		$this->assertSame($before, $after, 'the modification-free template was left in the cache');
	}

	public function test_an_unknown_modification_key_is_refused()
	{
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('No template modification with the key \'zzNotAModification\'');

		$this->renderTemplateWithoutModification('zzNotAModification');
	}

	public function test_a_disabled_modification_is_refused()
	{
		$key = $this->app()->db()->fetchOne(
			'SELECT modification_key FROM xf_template_modification WHERE enabled = 0 LIMIT 1'
		);

		if (!$key)
		{
			$this->markTestSkipped('This forum has no disabled template modification.');
		}

		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('is disabled');

		$this->renderTemplateWithoutModification($key);
	}
}
