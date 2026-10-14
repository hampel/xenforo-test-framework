<?php

namespace Hampel\Testing\Concerns;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;
use XF\SubContainer\AbstractSubContainer;

trait InteractsWithContainer
{
	/**
	 * Register an instance of an object in the container.
	 *
	 * @param  mixed  $key - the container key to be swapped
	 * @param  mixed  $instance - the value to swap in: an object, a closure the container will
	 *                            resolve, or a plain value such as the config array
	 *
	 * @return mixed - the value that was swapped in
	 */
	protected function swap($key, $instance)
	{
		if (is_array($key))
		{
			if (is_subclass_of($key[0], AbstractSubContainer::class))
			{
				// [$subcontainer (object), $key (string)]
				$subContainer = $key[0];
			}
			else
			{
				// [$subcontainer (string), $key (string)]
				$subContainer = $this->app()->container($key[0]);
			}

			$subContainer->container()->set($key[1], $instance);
		}
		else
		{
			$this->app()->container()->set($key, $instance);
		}

		return $instance;
	}

	/**
	 * Set a value in the application config - the values from config.php, which are NOT options.
	 *
	 * A config value is usually read once, when the container builds whatever uses it. Call this
	 * before the code under test resolves anything, and decache any container key already built
	 * from it, as fakesMail() does with `mailer`.
	 *
	 * @param string $key - the config key to set
	 * @param mixed $value - the value to set it to
	 *
	 * @return array - the config array as swapped in
	 */
	protected function setConfig($key, $value)
	{
		$config = $this->app()->config();
		$config[$key] = $value;

		$this->swap('config', $config);

		return $config;
	}

	/**
	 * Mock an instance of an object in the container.
	 *
	 * @param  mixed  $key - the container key to be swapped with a mock
	 * @param  string $abstract - the base class or interface to use for the mock
	 * @param  \Closure|null  $mock - (optional) the mock closure to define expectations on
	 * @return object - the mock object
	 */
	protected function mock($key, $abstract, ?\Closure $mock = null)
	{
		$args = func_get_args();
		array_shift($args);

		return $this->swap($key, \Mockery::mock(...array_filter($args)));
	}

	/**
	 * Mock a factory builder in the container.
	 *
	 * @param  mixed  $key - the container key to be swapped with a mock
	 * @param  string $abstract - the base class or interface to use for the mock
	 * @param  \Closure|null  $mock - (optional) the mock closure to define expectations on
	 * @return object
	 */
	protected function mockFactory($key, $abstract, ?\Closure $mock = null)
	{
		return $this->app()->container()->factory($key, function () use ($abstract, $mock)
		{
			$args = [$abstract, $mock];

			return \Mockery::mock(...array_filter($args));
		});
	}

	/**
	 * Mock a Service class
	 *
	 * @param string $shortName - shortname for the class in Addon_Id:Class format
	 * @param \Closure|null $mock - (optional) the mock closure to define expectations on
	 *
	 * @return object
	 */
	protected function mockService($shortName, ?\Closure $mock = null)
	{
		$class = \XF::stringToClass($shortName, '\%s\Service\%s');

		// Resolve the class the way XF's own service factory does, then insist it exists. Mockery
		// will happily build an untyped double of a class name that resolves to nothing, so a
		// misspelled short name would otherwise produce a test that passes while asserting against
		// nothing at all. Resolving first also means the mock is typed as the class XF would really
		// have built, so an add-on's own extension of the service is honoured - the same thing
		// mockRepository() does.
		$serviceClass = $this->app()->extendClass($class);
		if (!$serviceClass || !class_exists($serviceClass))
		{
			throw new \LogicException("Could not find service '$class' for '$shortName'");
		}

		return $this->mockFactory('service', $serviceClass, $mock);
	}

	/**
	 * Spy an instance of an object in the container.
	 *
	 * @param  mixed  $key
	 * @param  string $abstract
	 * @param  \Closure|null  $mock
	 * @return object
	 */
	protected function spy($key, $abstract, ?\Closure $mock = null)
	{
		$args = func_get_args();
		array_shift($args);

		return $this->swap($key, \Mockery::spy(...array_filter($args)));
	}

	/**
	 * Skip the test unless the given class resolves unextended, for a test whose point is that an
	 * add-on's extension is NOT present.
	 *
	 * $addonsToLoad filters which extensions are registered; it cannot remove one that has already
	 * been resolved in this process. XenForo caches the resolution and aliases an XFCP proxy, and a
	 * proxy cannot be undeclared - so an application built later with a narrower list still gets the
	 * extended class, and with it any column the extension added to an entity structure. Such a test
	 * passes alone and tests the opposite of what it says in a full run, which is why this refuses to
	 * run it rather than letting it pass.
	 *
	 * @param string $identifier - an entity short name, `XF:UserProfile`, or any class name
	 *
	 * @return void
	 */
	protected function requireClassNotExtended($identifier)
	{
		// stringToClass() is string manipulation, so it gives the class as written. The entity
		// manager's getEntityClassName() resolves extensions itself and would hand back the
		// extended class, leaving this comparing a class with itself and never refusing anything
		$class = strpos($identifier, ':') !== false
			? \XF::stringToClass($identifier, '%s\Entity\%s')
			: ltrim((string) $identifier, '\\');

		$resolved = $this->app()->extendClass($class);

		if ($resolved !== $class)
		{
			$shortName = (new \ReflectionClass($this))->getShortName();

			$this->markTestSkipped(
				"'$class' is already extended in this process, as '$resolved', so"
				. ' $addonsToLoad cannot isolate it here: XenForo caches the resolution and the XFCP'
				. ' proxy it aliases cannot be undeclared. Run this class on its own to execute the'
				. " test - vendor/bin/phpunit --filter $shortName"
			);
		}
	}

	/**
	 * Assert that every class extension the add-on registers actually applies.
	 *
	 * An extension that does not apply is silent: XenForo's own class runs, the add-on's code
	 * never executes, and a suite with no test on the extended class has nothing to go red. A
	 * suite can pass identically with the extension present and absent, so a green run says
	 * nothing either way - which is the whole reason to assert it.
	 *
	 * **This passes on a current release, and that is the point.** The resolution is keyed the way
	 * XenForo keys it, so an extension registered against a pre-2.3 class name still applies. What
	 * this guards is the future: a XenForo release that renames or removes a class an extension
	 * targets takes that extension out silently, and so would a regression in how the framework
	 * keys them. Either turns into a failure here rather than into code that quietly stops running.
	 *
	 * The list comes from the database, which is what XenForo itself resolves from, so this works
	 * on an install with no development output as well as on a development one.
	 *
	 * **A subclass counts as applied.** Where another add-on extends the same class, the
	 * resolution is the most derived class, which descends from this add-on's.
	 *
	 * It refuses when the add-on registers no active extension, because an assertion over an empty
	 * list passes while checking nothing - and says separately when the id matches no installed
	 * add-on, which is the likelier of the two and reads as the other one otherwise.
	 *
	 * **It reads the database, so it cannot see an artifact in `_output/class_extensions/` that was
	 * never imported.** A test that reads that directory instead catches the drift a commit without
	 * an import leaves, which this does not - so the two are complementary rather than one
	 * replacing the other.
	 *
	 * @param string|null $addOnId - defaults to the add-on $addonsToLoad isolates, when it names one
	 *
	 * @return array<string, string> - the class each extension was registered against, mapped to
	 *                                 what it resolved to
	 */
	protected function assertAddOnClassExtensionsApply($addOnId = null)
	{
		$addOnId = $addOnId ?: $this->soleIsolatedAddOnId('whose class extensions to check');

		$rows = $this->app()->db()->fetchAll(
			'SELECT from_class, to_class
				FROM xf_class_extension
				WHERE addon_id = ? AND active = 1
				ORDER BY from_class',
			$addOnId
		);

		if (!$rows)
		{
			// an id that matches no installed add-on is the likelier of the two, and saying
			// 'registers no active class extension' of one is true and reads as though it exists
			$installed = $this->app()->db()->fetchOne(
				'SELECT addon_id FROM xf_addon WHERE addon_id = ?',
				$addOnId
			);

			if (!$installed)
			{
				throw new \LogicException(
					"'$addOnId' is not among the installed add-ons on this forum, so it registers no"
					. ' class extension and this would pass having checked nothing. Check the id.'
				);
			}

			throw new \LogicException(
				"'$addOnId' is installed and registers no active class extension, so there is"
				. ' nothing to assert and this would pass having checked nothing. Remove the'
				. ' assertion, or name the add-on that owns the extensions. An add-on whose'
				. ' extensions are all inactive is the other way to reach this.'
			);
		}

		$applied = [];
		$absent = [];

		foreach ($rows AS $row)
		{
			// extendClass() is the resolution path XenForo uses. Never class_exists() the
			// to_class to check it: that autoloads the extension outside the resolution path,
			// where the XFCP_ proxy it extends has not been declared, and is a fatal
			$resolved = $this->app()->extendClass($row['from_class']);

			if ($resolved === $row['to_class'] || is_subclass_of($resolved, $row['to_class']))
			{
				$applied[$row['from_class']] = $resolved;
			}
			else
			{
				$absent[] = $row['from_class'] . ' resolves to ' . $resolved
					. ', so ' . $row['to_class'] . ' never runs';
			}
		}

		PHPUnit::assertSame(
			[],
			$absent,
			"These class extensions of '$addOnId' are registered and do not apply, so XenForo's own"
			. ' class runs instead and the add-on code they hold never executes'
		);

		return $applied;
	}

	/**
	 * The one add-on $addonsToLoad names, for a helper that acts on the add-on under test.
	 *
	 * Lives here rather than beside either caller because both the console-command walk and the
	 * class-extension assertion need it, and a suite isolating exactly one add-on is the usual
	 * shape.
	 *
	 * @param string $purpose - completes 'Name the add-on ...', so each caller's refusal says what
	 *                          it wanted the id for
	 *
	 * @return string
	 */
	private function soleIsolatedAddOnId($purpose)
	{
		$ids = array_values(array_filter($this->app()->isolatedAddOnIds(), function ($id)
		{
			return $id !== 'None/None';
		}));

		if (count($ids) !== 1)
		{
			throw new \LogicException(
				"Name the add-on $purpose: \$addonsToLoad names "
				. (count($ids) ? count($ids) . ' add-ons' : 'none') . ', so there is no single one to assume.'
			);
		}

		return $ids[0];
	}
}
