<?php

namespace Hampel\Testing\Integration;

use Hampel\Testing\Extension;
use Hampel\Testing\Integration\Fixtures\Unsuffixed\Lookup;

/**
 * XenForo 2.3.0 aliases a class in an aliasable namespace to its suffixed name whether or not
 * that class exists, and records the class's extension chain under the alias. An object's own
 * class name is then not the name the chain is recorded under, and the root class has to be
 * found through the alias - by every Extension in the run, not only the one that resolved it.
 *
 * The alias is planted here, since later XenForo releases only alias to a class that exists.
 */
class AliasedRootClassTest extends TestCase
{
	private const ALIAS = Lookup::class . 'Controller';

	protected function setUp(): void
	{
		parent::setUp();

		$this->editStaticMap('globalClassAliasMap', function (array $map)
		{
			$map[Lookup::class] = self::ALIAS;

			return $map;
		});
	}

	protected function tearDown(): void
	{
		$forget = function (array $map)
		{
			unset($map[Lookup::class], $map[self::ALIAS]);

			return $map;
		};

		foreach ([
			'globalClassAliasMap',
			'globalExtensionMap',
			'globalInverseExtensionMap',
			'globalExtensionsApplied',
		] AS $property)
		{
			$this->editStaticMap($property, $forget);
		}

		parent::tearDown();
	}

	public function test_the_class_is_recorded_under_its_alias()
	{
		$this->assertSame(self::ALIAS, $this->app()->extendClass(Lookup::class));
	}

	public function test_the_extension_that_resolved_it_answers_for_an_object()
	{
		$this->app()->extendClass(Lookup::class);

		$this->assertSame(
			self::ALIAS,
			$this->app()->extension()->resolveExtendedClassToRoot(new Lookup())
		);
	}

	/**
	 * What every test after the first meets: the resolution is kept for the run, so a new
	 * Extension never asks XenForo to resolve the class and has only the kept record.
	 */
	public function test_a_later_extension_answers_for_an_object_too()
	{
		$this->app()->extendClass(Lookup::class);

		$later = new Extension();

		$this->assertSame(self::ALIAS, $later->extendClass(Lookup::class));
		$this->assertSame(self::ALIAS, $later->resolveExtendedClassToRoot(new Lookup()));
		$this->assertSame(self::ALIAS, $later->resolveExtendedClassToRoot(Lookup::class));
	}

	private function editStaticMap(string $property, \Closure $edit): void
	{
		$reflection = new \ReflectionProperty(Extension::class, $property);
		$reflection->setValue(null, $edit($reflection->getValue()));
	}
}
