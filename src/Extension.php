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
		if (array_key_exists($class, self::$globalExtensionMap))
		{
			return self::$globalExtensionMap[$class];
		}

		$extended = parent::extendClass($class, $fakeBaseClass);

		self::$globalExtensionMap[$class] = $extended;
		self::$globalInverseExtensionMap[$extended] = $class;

		return $extended;
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
