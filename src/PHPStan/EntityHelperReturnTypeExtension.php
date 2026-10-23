<?php

namespace Hampel\Testing\PHPStan;

use Hampel\Testing\TestCase;
use Hampel\XenForo\PHPStan\Type\Shortcut;
use Hampel\XenForo\PHPStan\Type\ShortcutTypes;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Types what makeEntity() and createEntity() return from the entity they are asked for.
 *
 * Both are declared to return XF\Mvc\Entity\Entity, because a docblock cannot carry the type: the
 * argument is a short name such as 'XF:User', and a template bound through class-string<T>|string
 * collapses to string. The entity manager's own create() is typed for the short name by
 * hampel/xenforo-phpstan, so the type is known on the way in and this puts it back on the way out,
 * through that package's resolution - which also answers with the analysed add-on's own extension
 * of the class where it has one, as create() does at runtime.
 *
 * Loaded only by PHPStan, through the extension.neon this package ships, so the package it imports
 * from is a suggestion rather than a requirement.
 */
final class EntityHelperReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
	public function __construct(
		private readonly ShortcutTypes $types,
		private readonly ReflectionProvider $reflectionProvider
	)
	{
	}

	public function getClass(): string
	{
		return TestCase::class;
	}

	public function isMethodSupported(MethodReflection $methodReflection): bool
	{
		return in_array($methodReflection->getName(), ['makeEntity', 'createEntity'], true);
	}

	public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
	{
		$argument = $methodCall->getArgs()[0] ?? null;
		if ($argument === null)
		{
			return null;
		}

		$argumentType = $scope->getType($argument->value);

		$type = $this->types->typeFor(Shortcut::ENTITY, false, $argumentType, $scope->getFile());
		if ($type !== null)
		{
			return $type;
		}

		// A class name rather than a short name, as ::class gives. The resolution above leaves that
		// to XenForo's own annotation on create(), which the helper's declared return type hides -
		// so the class is taken as given. Anything else, a variable or a name the forum does not
		// have, leaves the declared type standing.
		$classes = [];
		foreach ($argumentType->getConstantStrings() AS $string)
		{
			$class = ltrim($string->getValue(), '\\');
			if (!$this->reflectionProvider->hasClass($class))
			{
				return null;
			}

			$classes[] = new ObjectType($class);
		}

		return $classes === [] ? null : TypeCombinator::union(...$classes);
	}
}
