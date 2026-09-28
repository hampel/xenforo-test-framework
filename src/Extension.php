<?php

namespace Hampel\Testing;

use XF\Db\AbstractAdapter;
use XF\Extension as BaseExtension;

class Extension extends BaseExtension
{
	protected static $globalExtensionMap = [];
	protected static $globalInverseExtensionMap = [];

	protected static $globalClassAliasMap = [];

	/**
	 * Classes whose XFCP proxy has been aliased in this process. The alias cannot be undone, so a
	 * second extension can never be applied to one of these - see addClassExtension().
	 *
	 * @var array
	 */
	protected static $globalProxiedClasses = [];

	/**
	 * Classes a test extended at runtime, forgotten at the end of that test so the next one resolves
	 * the base class again.
	 *
	 * @var array
	 */
	protected static $classExtensionsAddedByTest = [];

	/**
	 * When faking, code events are recorded and listeners are not run - see
	 * Concerns\InteractsWithEvents.
	 *
	 * @var bool
	 */
	protected $fakeEvents = false;

	/** @var array */
	protected $firedEvents = [];

	/**
	 * Listeners the test framework installs itself, which run whether or not events are faked.
	 *
	 * fakesEvents() stops an add-on's listeners running, which is its purpose. A listener the
	 * framework relies on to install a fake is not one of those: dropping it would let the code
	 * under test reach the real thing.
	 *
	 * @var array
	 */
	protected $internalListeners = [];

	/**
	 * @param bool $enabled
	 *
	 * @return void
	 */
	public function setFakeEventMode($enabled = true)
	{
		$this->fakeEvents = $enabled;
	}

	/**
	 * @return bool
	 */
	public function isFakingEvents()
	{
		return $this->fakeEvents;
	}

	/**
	 * @return array - each entry is ['event' => string, 'args' => array, 'hint' => string|null]
	 */
	public function getFiredEvents()
	{
		return $this->firedEvents;
	}

	/**
	 * Add a listener belonging to the test framework itself.
	 *
	 * @param string $event
	 * @param callable $callback
	 *
	 * @return void
	 */
	public function addInternalListener($event, callable $callback)
	{
		$this->internalListeners[$event][] = $callback;
	}

	/**
	 * Record the event, and in fake mode stop it reaching any listener.
	 *
	 * @param string $event
	 * @param array $args
	 * @param string|null $hint
	 *
	 * @return bool
	 */
	public function fire($event, array $args = [], $hint = null)
	{
		if (!$this->fakeEvents)
		{
			$this->fireInternalListeners($event, $args);

			return parent::fire($event, $args, $hint);
		}

		$this->firedEvents[] = [
			'event' => $event,
			'args' => $this->snapshotEventArgs($args),
			'hint' => $hint,
		];

		$this->fireInternalListeners($event, $args);

		// no listener of the add-on's ran, so nothing vetoed the event
		return true;
	}

	/**
	 * @param string $event
	 * @param array $args - passed on as given, so a listener still receives them by reference
	 *
	 * @return void
	 */
	private function fireInternalListeners($event, array $args)
	{
		foreach ($this->internalListeners[$event] ?? [] AS $callback)
		{
			call_user_func_array($callback, $args);
		}
	}

	/**
	 * Record the arguments as they were at the moment the event fired.
	 *
	 * XenForo's convention for an extension point is to pass the argument by reference -
	 * `$app->fire('some_event', [&$map])` - and copying an array preserves the references inside
	 * it. Recorded as-is, an assertion would see whatever the caller left in the variable
	 * afterwards rather than what was fired, which can silently pass or fail. Reassigning each
	 * element breaks the reference while keeping objects identical, so assertions on an entity
	 * that was passed still compare the same instance.
	 *
	 * @param array $args
	 *
	 * @return array
	 */
	protected function snapshotEventArgs(array $args)
	{
		$snapshot = [];

		foreach ($args AS $key => $value)
		{
			$snapshot[$key] = $value;
		}

		return $snapshot;
	}

	/**
	 * @param $class
	 *
	 * @param $fakeBaseClass
	 * @return mixed|string
	 * @throws \Exception
	 *
	 * Maintain a global extension map so we don't try to re-extend classes for every test that gets run
	 */
	public function extendClass($class, $fakeBaseClass = null)
	{
		$key = $this->extensionCacheKey($class);

		if (array_key_exists($key, self::$globalExtensionMap))
		{
			return self::$globalExtensionMap[$key];
		}

		$extended = parent::extendClass($class, $fakeBaseClass);

		if ($extended !== $key)
		{
			// XenForo aliased the proxy to reach this, and PHP cannot declare that name twice
			self::$globalProxiedClasses[$key] = true;
		}

		self::$globalExtensionMap[$key] = $extended;
		self::$globalInverseExtensionMap[$extended] = $key;

		return $extended;
	}

	/**
	 * Register a class extension, and let the next extendClass() see it.
	 *
	 * XenForo's own note on this method says the cache is not overridden when the class has already
	 * been loaded. That matters more here, because the cache above is static and outlives the test:
	 * without this, an extension a test adds is ignored whenever any earlier test resolved the base
	 * class, so the test passes alone and fails in the suite.
	 *
	 * @param string $class
	 * @param string $extension
	 *
	 * @return void
	 */
	public function addClassExtension($class, $extension)
	{
		$key = $this->extensionCacheKey($class);

		if (isset(self::$globalProxiedClasses[$key]))
		{
			// the proxy is a class_alias() made when the class was extended, and PHP cannot declare
			// that name twice - so the extension has to be registered before anything resolves the
			// class, in this process or another one
			throw new \LogicException(
				"'$key' has already been extended in this run, so another extension cannot be added"
				. ' to it: the class alias XenForo builds for the extension is already declared, and'
				. ' PHP cannot declare it twice. Register the extension before anything resolves the'
				. ' class, or extend a class no other test touches.'
			);
		}

		self::$classExtensionsAddedByTest[$key] = true;

		unset(self::$globalExtensionMap[$key], self::$globalInverseExtensionMap[$key]);

		// XF keeps its own map per instance, and it cached the unextended class just the same
		unset($this->extensionMap[$key], $this->extensionMap[ltrim((string) $class, '\\')]);

		parent::addClassExtension($class, $extension);
	}

	/**
	 * Forget the class extensions a test added, so the next test resolves those classes unextended.
	 *
	 * The cache is static and outlives the application, so without this an extension one test added
	 * applies for the rest of the run - every later test resolving that class gets the extended one,
	 * and which tests fail depends on the order they ran in. Called from TestCase::tearDown().
	 *
	 * The proxy XenForo aliased stays declared, which is harmless: nothing resolves to it once the
	 * cache entry is gone, and addClassExtension() refuses to extend that class a second time.
	 *
	 * @return void
	 */
	public static function forgetClassExtensionsAddedByTest()
	{
		foreach (array_keys(self::$classExtensionsAddedByTest) AS $key)
		{
			$extended = self::$globalExtensionMap[$key] ?? null;

			unset(self::$globalExtensionMap[$key]);

			if ($extended !== null)
			{
				unset(self::$globalInverseExtensionMap[$extended]);
			}
		}

		self::$classExtensionsAddedByTest = [];
	}

	/**
	 * The name XenForo will actually extend, which is what the cache has to be keyed by.
	 *
	 * XF\Extension::extendClass() trims a leading backslash and resolves the alias map before
	 * consulting its own cache, so one class arrives here under several spellings: as written, as
	 * \XF::stringToClass() returns it with the leading backslash, and as XenForo 2.3's own code
	 * writes it after the class was renamed. Keyed by the spelling as passed, each of those missed
	 * the cache and re-ran the extension, and the second one warned that the proxy class name was
	 * already in use.
	 *
	 * @param string $class
	 *
	 * @return string
	 */
	private function extensionCacheKey($class)
	{
		$class = ltrim((string) $class, '\\');

		return $class === '' ? $class : $this->getAliasedClass($class);
	}

	public function getAliasedClass(string $alias): string
	{
		if (isset(self::$globalClassAliasMap[$alias]))
		{
			return self::$globalClassAliasMap[$alias];
		}

		$aliased = parent::getAliasedClass($alias);

		self::$globalClassAliasMap[$alias] = $aliased;

		return $aliased;
	}

	/**
	 * Build an Extension carrying only the listeners and class extensions that belong to the
	 * given add-ons.
	 *
	 * The container's own `extension.listeners` and `extension.classExtensions` come from the
	 * registry, where every add-on's entries are already merged together with nothing left to
	 * filter them by - so this reads the tables directly. It is also called before the registry
	 * is necessarily populated, during App::setup().
	 *
	 * @param string[] $addOnIds
	 * @param AbstractAdapter $db
	 *
	 * @return self
	 */
	public static function forAddOns(array $addOnIds, AbstractAdapter $db)
	{
		// self rather than static: the constructor is XenForo's, so a subclass is not
		// guaranteed to accept these arguments, and nothing here wants a subclass back
		return new self(
			self::listenersForAddOns($addOnIds, $db),
			self::classExtensionsForAddOns($addOnIds, $db)
		);
	}

	/**
	 * @param string[] $addOnIds
	 * @param AbstractAdapter $db
	 *
	 * @return array
	 */
	private static function listenersForAddOns(array $addOnIds, AbstractAdapter $db)
	{
		$listeners = $db->fetchAll("
			SELECT listener.*
			FROM xf_code_event_listener AS listener
			LEFT JOIN xf_addon AS addon ON (listener.addon_id = addon.addon_id)
			WHERE listener.active = 1
				AND addon.active = 1
				AND addon.is_processing = 0
				AND listener.addon_id IN (" . $db->quote($addOnIds) . ")
			ORDER BY listener.event_id, listener.execute_order, addon.addon_id
		");

		$cache = [];

		foreach ($listeners AS $listener)
		{
			$hint = $listener['hint'] !== '' ? $listener['hint'] : '_';
			$cache[$listener['event_id']][$hint][] = [
				$listener['callback_class'],
				$listener['callback_method'],
			];
		}

		return $cache;
	}

	/**
	 * Read with the database rather than a finder, because a finder needs the extension we are
	 * in the middle of building.
	 *
	 * @param string[] $addOnIds
	 * @param AbstractAdapter $db
	 *
	 * @return array
	 */
	private static function classExtensionsForAddOns(array $addOnIds, AbstractAdapter $db)
	{
		$extensions = $db->fetchAll("
			SELECT extension.*
			FROM xf_class_extension AS extension
			LEFT JOIN xf_addon AS addon ON (extension.addon_id = addon.addon_id)
			WHERE extension.active = 1
				AND addon.active = 1
				AND addon.is_processing = 0
				AND extension.addon_id IN (" . $db->quote($addOnIds) . ")
			ORDER BY extension.execute_order, extension.to_class
		");

		return self::mapClassExtensions($extensions);
	}

	/**
	 * Key each extension by the class XenForo will actually ask to extend.
	 *
	 * This matches XF 2.3's own ClassExtensionRepository::buildExtensionCacheData(). 2.3 renamed
	 * many classes with a suffix and aliases the old names forward, so an add-on supporting 2.2
	 * registers its extension on the old spelling - XF\Service\User\Login - while 2.3 asks to
	 * extend LoginService. Keyed on the raw spelling, the extension would never be applied.
	 *
	 * Both spellings share one entry, deduplicated, so a class is not extended twice.
	 *
	 * @param array $extensions - rows with from_class and to_class
	 *
	 * @return array<string, string[]>
	 */
	private static function mapClassExtensions(array $extensions)
	{
		$cache = [];

		foreach ($extensions AS $extension)
		{
			$cache[\XF::getClassForAlias($extension['from_class'])][] = $extension['to_class'];
		}

		foreach ($cache AS $fromClass => $toClasses)
		{
			$cache[$fromClass] = array_values(array_unique($toClasses));
		}

		return $cache;
	}

	public function resolveExtendedClassToRoot($class)
	{
		$originalClass = $class;

		if (is_object($class))
		{
			$class = get_class($class);
		}
		else if (($class[0] ?? null) === '\\')
		{
			$class = substr($class, 1);
		}

		if (isset(self::$globalInverseExtensionMap[$class]))
		{
			$this->inverseExtensionMap[$class] = self::$globalInverseExtensionMap[$class];
		}

		return parent::resolveExtendedClassToRoot($originalClass);
	}
}
