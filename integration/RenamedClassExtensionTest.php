<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\TestCase as FrameworkTestCase;

/**
 * XenForo 2.3 renamed most services, finders, repositories and controllers with a suffix and
 * aliases the old names forward. An add-on that also supports 2.2 has to extend the old spelling,
 * and 2.3 then asks to extend the new one - so the isolated extension map must be keyed the way
 * 2.3's own ClassExtensionRepository keys it, or the extension is silently not applied and the
 * test runs XenForo's class instead of the add-on's.
 *
 * Control: key on the raw from_class and both tests fail.
 */
class RenamedClassExtensionTest extends TestCase
{
	public function test_an_extension_on_a_renamed_class_is_keyed_by_the_class_xenforo_asks_for()
	{
		$renamed = null;

		foreach ($this->activeClassExtensions() AS $extension)
		{
			if (\XF::getClassForAlias($extension['from_class']) !== $extension['from_class'])
			{
				$renamed = $extension;
				break;
			}
		}

		if (!$renamed)
		{
			$this->markTestSkipped(
				'No add-on on this forum extends a class by a pre-2.3 name, so nothing here could '
				. 'be dropped. Skipped rather than passed, because a pass would mean nothing.'
			);
		}

		$map = $this->invokePrivate('getExtensionData', [[$renamed['addon_id']]]);
		$askedFor = \XF::getClassForAlias($renamed['from_class']);

		$this->assertArrayHasKey($askedFor, $map, "{$renamed['from_class']} should be filed under {$askedFor}");
		$this->assertContains($renamed['to_class'], $map[$askedFor]);
		$this->assertArrayNotHasKey($renamed['from_class'], $map, 'nothing should be left under the old spelling');
	}

	public function test_both_spellings_of_one_class_share_a_bucket_without_duplicates()
	{
		$map = $this->invokePrivate('mapClassExtensions', [[
			['from_class' => 'XF\Service\User\Login', 'to_class' => 'Vendor\A\Login'],
			['from_class' => 'XF\Service\User\LoginService', 'to_class' => 'Vendor\A\Login'],
			['from_class' => 'XF\Service\User\LoginService', 'to_class' => 'Vendor\B\Login'],
		]]);

		$this->assertSame(
			['XF\Service\User\LoginService' => ['Vendor\A\Login', 'Vendor\B\Login']],
			$map
		);
	}

	/**
	 * @return array
	 */
	private function activeClassExtensions()
	{
		return $this->app()->db()->fetchAll("
			SELECT extension.addon_id, extension.from_class, extension.to_class
			FROM xf_class_extension AS extension
			LEFT JOIN xf_addon AS addon ON (extension.addon_id = addon.addon_id)
			WHERE extension.active = 1
				AND addon.active = 1
				AND addon.is_processing = 0
			ORDER BY extension.addon_id, extension.from_class
		");
	}

	/**
	 * @param string $method
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	private function invokePrivate($method, array $arguments)
	{
		$reflection = new \ReflectionMethod(FrameworkTestCase::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($this, $arguments);
	}
}
