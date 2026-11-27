<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cache, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cache
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

/*
 * Cacti includes setup.php on every request regardless of PHP version. Keep
 * this file PHP 8.0 parseable so sites running PHP 8.0 or 8.1 receive a clean
 * refusal instead of a fatal parse error.
 */

/**
 * Check whether the active PHP runtime meets the plugin's minimum PHP 8.2 floor.
 *
 * @return bool
 */
function cache_php_supported(): bool {
	return version_compare(PHP_VERSION, '8.2.0', '>=');
}

/**
 * Bootstrap the plugin's zero-dependency PSR-4 autoloader when PHP >= 8.2.
 *
 * @return bool
 */
function cache_boot(): bool {
	if (!cache_php_supported()) {
		return false;
	}

	require_once(__DIR__ . '/include/autoload.php');

	return true;
}

/**
 * Log and surface a clean refusal when running on PHP < 8.2.
 *
 * @return bool True when PHP is unsupported and execution should stop.
 */
function cache_refuse_unsupported_php(): bool {
	if (cache_php_supported()) {
		return false;
	}

	$message = __('The Cache plugin requires PHP %s or later. This server runs PHP %s, so the plugin was not installed or enabled.', '8.2.0', PHP_VERSION, 'cache');

	cacti_log('ERROR: ' . $message, false, 'CACHE');

	if (PHP_SAPI === 'cli') {
		fwrite(STDERR, $message . PHP_EOL);
	} elseif (function_exists('raise_message')) {
		raise_message('cache_php', $message, MESSAGE_LEVEL_ERROR);
	}

	return true;
}

/**
 * Parse plugin metadata from INFO for Cacti's Plugin Management registry.
 *
 * @return array<string, string>
 */
function plugin_cache_version(): array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	if (!is_array($info) || !isset($info['info']) || !is_array($info['info'])) {
		return array();
	}

	$version = array();

	foreach ($info['info'] as $key => $value) {
		$version[(string) $key] = is_scalar($value) ? (string) $value : '';
	}

	return $version;
}

/**
 * Cacti plugin install lifecycle callback.
 *
 * @return void
 */
function plugin_cache_install(): void {
	if (cache_refuse_unsupported_php()) {
		return;
	}

	plugin_cache_upgrade();
}

/**
 * Cacti plugin configuration check lifecycle callback.
 *
 * @return bool
 */
function plugin_cache_check_config(): bool {
	if (cache_refuse_unsupported_php()) {
		return false;
	}

	return plugin_cache_upgrade();
}

/**
 * Run idempotent schema migrations, hook registrations, and version recording.
 *
 * @return bool
 */
function plugin_cache_upgrade(): bool {
	if (!cache_boot()) {
		return false;
	}

	$info   = plugin_cache_version();
	$schema = new \Somethingwithproof\Cache\Schema(false);

	if ($schema->isCurrent() && $schema->recordedPluginVersion() === ($info['version'] ?? '') && $schema->hooksRegistered()) {
		return true;
	}

	if (!$schema->migrate()) {
		return false;
	}

	cache_setup_hooks();
	$schema->recordPluginVersion($info['version'] ?? '0.1.0', $info['author'] ?? '', $info['homepage'] ?? '');

	return true;
}

/**
 * Register Cacti hooks and 3-tier RBAC realms.
 *
 * Kept procedural with *_setup_* naming because Cacti's
 * api_plugin_register_hook() and api_plugin_register_realm() validate the
 * caller's function name.
 *
 * @return void
 */
function cache_setup_hooks(): void {
	global $config;

	if (!function_exists('replicate_out') && isset($config['library_path']) && is_string($config['library_path']) && is_file($config['library_path'] . '/poller.php')) {
		include_once($config['library_path'] . '/poller.php');
	}

	$enabled = (new \Somethingwithproof\Cache\Schema(false))->pluginEnabled();

	foreach (\Somethingwithproof\Cache\Schema::HOOKS as $hook => $function) {
		api_plugin_register_hook('cache', $hook, $function, 'setup.php', $enabled);
	}

	foreach (\Somethingwithproof\Cache\Pages::realms() as $realm) {
		api_plugin_register_realm('cache', implode(',', $realm['files']), $realm['name'], $realm['default']);
	}
}

/**
 * Cacti plugin uninstall lifecycle callback honoring table retention settings.
 *
 * @return void
 */
function plugin_cache_uninstall(): void {
	if (!cache_boot()) {
		return;
	}

	$schema = new \Somethingwithproof\Cache\Schema(false);

	if (read_config_option('cache_uninstall_keep_tables') == 'on') {
		$schema->untrackTables();
		cacti_log('NOTE: Uninstall kept plugin_cache tables as configured', false, 'CACHE');
	} else {
		$schema->forgetVersion();
	}
}

/**
 * Load current plugin configuration from Cacti's settings table.
 *
 * @return \Somethingwithproof\Cache\Settings
 */
function cache_settings(): \Somethingwithproof\Cache\Settings {
	$row = array();

	foreach (\Somethingwithproof\Cache\Settings::NAMES as $name) {
		$row[$name] = read_config_option($name);
	}

	return \Somethingwithproof\Cache\Settings::fromRow($row);
}

/**
 * Persist validated plugin configuration into Cacti's settings table.
 *
 * @param \Somethingwithproof\Cache\Settings $settings Validated settings snapshot.
 * @return bool
 */
function cache_save_settings(\Somethingwithproof\Cache\Settings $settings): bool {
	if (!function_exists('db_execute_prepared')) {
		return false;
	}

	foreach ($settings->toCactiSettingsMap() as $name => $value) {
		$ok = db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			array($name, $value)
		);

		if (!$ok) {
			return false;
		}
	}

	cache_reset_runtime();

	return true;
}

/**
 * Reset cached in-process service singletons after configuration updates or tests.
 *
 * @param \Somethingwithproof\Cache\Cache\CacheService|null $cacheOverride Optional test CacheService override.
 * @param \Somethingwithproof\Cache\Queue\QueueService|null $queueOverride Optional test QueueService override.
 * @return void
 */
function cache_reset_runtime(
	?\Somethingwithproof\Cache\Cache\CacheService $cacheOverride = null,
	?\Somethingwithproof\Cache\Queue\QueueService $queueOverride = null
): void {
	$GLOBALS['__plugin_cache_service'] = $cacheOverride;
	$GLOBALS['__plugin_cache_queue']   = $queueOverride;
}

/**
 * Instantiate the configured CacheDriver for the active Settings snapshot.
 *
 * @param \Somethingwithproof\Cache\Settings $settings Active plugin settings.
 * @return \Somethingwithproof\Cache\Cache\CacheDriver
 */
function cache_driver(\Somethingwithproof\Cache\Settings $settings): \Somethingwithproof\Cache\Cache\CacheDriver {
	if ($settings->driver === 'memory') {
		return new \Somethingwithproof\Cache\Cache\MemoryDriver($settings->memoryCapacity);
	}

	if ($settings->driver === 'apcu') {
		return new \Somethingwithproof\Cache\Cache\ApcuDriver($settings->keyPrefix);
	}

	if ($settings->driver === 'redis') {
		return new \Somethingwithproof\Cache\Cache\RedisDriver(
			$settings->redisHost,
			$settings->redisPort,
			$settings->redisTls,
			$settings->redisDb,
			$settings->redisTimeout,
			$settings->resolveRedisPassword(),
			$settings->keyPrefix
		);
	}

	if (function_exists('db_execute_prepared')) {
		return new \Somethingwithproof\Cache\Cache\MariaDbDriver(
			new \Somethingwithproof\Cache\Db\CactiDbAdapter(false)
		);
	}

	return new \Somethingwithproof\Cache\Cache\MemoryDriver($settings->memoryCapacity);
}

/**
 * Return the singleton CacheService instance for the current request or CLI run.
 *
 * @return \Somethingwithproof\Cache\Cache\CacheService
 */
function cache_service(): \Somethingwithproof\Cache\Cache\CacheService {
	if (isset($GLOBALS['__plugin_cache_service']) && $GLOBALS['__plugin_cache_service'] instanceof \Somethingwithproof\Cache\Cache\CacheService) {
		return $GLOBALS['__plugin_cache_service'];
	}

	$settings = cache_settings();
	$service  = new \Somethingwithproof\Cache\Cache\CacheService(
		cache_driver($settings),
		$settings->defaultTtl,
		$settings->maxPayloadBytes,
		$settings->lockTtl,
		$settings->xfetchBeta
	);

	$GLOBALS['__plugin_cache_service'] = $service;

	return $service;
}

/**
 * Return the singleton QueueService instance backed by plugin_cache_queue.
 *
 * @return \Somethingwithproof\Cache\Queue\QueueService
 */
function cache_queue_service(): \Somethingwithproof\Cache\Queue\QueueService {
	if (isset($GLOBALS['__plugin_cache_queue']) && $GLOBALS['__plugin_cache_queue'] instanceof \Somethingwithproof\Cache\Queue\QueueService) {
		return $GLOBALS['__plugin_cache_queue'];
	}

	$settings = cache_settings();
	$storage  = function_exists('db_execute_prepared')
		? new \Somethingwithproof\Cache\Queue\MariaDbQueueStorage(new \Somethingwithproof\Cache\Db\CactiDbAdapter(false))
		: new \Somethingwithproof\Cache\Queue\MemoryQueueStorage();

	$service = new \Somethingwithproof\Cache\Queue\QueueService(
		$storage,
		$settings->queueDefaultLease,
		$settings->queueDefaultMaxAttempts,
		$settings->maxPayloadBytes
	);

	$GLOBALS['__plugin_cache_queue'] = $service;

	return $service;
}

/**
 * Procedural soft-coupling helper: read a cached value or return $default on miss.
 *
 * Sibling plugins may guard calls with function_exists('cacti_cache_get') to
 * stay standalone when plugin_cache is not installed.
 *
 * @param string $key Validated cache key.
 * @param string $group Validated cache group name.
 * @param mixed $default Fallback returned on miss or when plugin_cache cannot boot.
 * @return mixed
 */
function cacti_cache_get(string $key, string $group = 'default', mixed $default = null): mixed {
	if (!cache_boot()) {
		return $default;
	}

	return cache_service()->get($key, $group, $default);
}

/**
 * Procedural soft-coupling helper: store a JSON-serializable value with optional TTL and tags.
 *
 * @param string $key Validated cache key.
 * @param mixed $value JSON-encodable payload (never PHP-serialized).
 * @param int|null $ttl Expiration in seconds (null = configured default, 0 = no expiry).
 * @param string $group Validated cache group name.
 * @param list<string> $tags Optional Drupal-style cache tags.
 * @return bool
 */
function cacti_cache_set(string $key, mixed $value, ?int $ttl = null, string $group = 'default', array $tags = array()): bool {
	if (!cache_boot()) {
		return false;
	}

	return cache_service()->set($key, $value, $ttl, $group, $tags);
}

/**
 * Procedural soft-coupling helper: delete a single cache entry.
 *
 * @param string $key Validated cache key.
 * @param string $group Validated cache group name.
 * @return bool
 */
function cacti_cache_delete(string $key, string $group = 'default'): bool {
	if (!cache_boot()) {
		return false;
	}

	return cache_service()->delete($key, $group);
}

/**
 * Procedural soft-coupling helper: invalidate all entries carrying any of the given cache tags.
 *
 * @param list<string> $tags Drupal-style cache tags (e.g. ['device:42', 'plugin:redfish']).
 * @return int Number of invalidated tags.
 */
function cacti_cache_invalidate_tags(array $tags): int {
	if (!cache_boot()) {
		return 0;
	}

	return cache_service()->invalidateTags($tags);
}

/**
 * Procedural soft-coupling helper: stampede-safe read-through cache with XFetch early recompute.
 *
 * @param string $key Validated cache key.
 * @param int|null $ttl Expiration in seconds (null = configured default, 0 = no expiry).
 * @param callable(): mixed $callback Producer callback on miss or XFetch early recompute.
 * @param string $group Validated cache group name.
 * @param list<string> $tags Optional Drupal-style cache tags.
 * @return mixed
 */
function cacti_cache_remember(string $key, ?int $ttl, callable $callback, string $group = 'default', array $tags = array()): mixed {
	if (!cache_boot()) {
		return $callback();
	}

	return cache_service()->remember($key, $ttl, $callback, $group, $tags);
}

/**
 * Register Console menu entries under Utilities and run automatic upgrade checks.
 *
 * @return void
 */
function cache_config_arrays(): void {
	global $menu;

	if (!cache_boot()) {
		return;
	}

	foreach (\Somethingwithproof\Cache\Pages::menu() as $file => $title) {
		$menu[__('Utilities')]['plugins/cache/' . $file] = $title;
	}

	if (in_array(get_current_page(), \Somethingwithproof\Cache\Pages::upgradeTriggers(), true)) {
		plugin_cache_upgrade();
	}
}

/**
 * Register the Cache tab under Console -> Configuration -> Settings for Admin users.
 *
 * @return void
 */
function cache_config_settings(): void {
	global $tabs, $settings;

	if (PHP_SAPI !== 'cli' && !api_plugin_user_realm_auth(\Somethingwithproof\Cache\Pages::ADMIN)) {
		return;
	}

	if (!cache_boot()) {
		return;
	}

	$tabs['cache']     = __('Cache', 'cache');
	$settings['cache'] = \Somethingwithproof\Cache\Settings::form();
}

/**
 * Register navigation breadcrumbs for plugin_cache Console pages.
 *
 * @param array<string, mixed> $nav Existing Cacti navigation breadcrumb map.
 * @return array<string, mixed>
 */
function cache_draw_navigation_text(array $nav): array {
	global $current_user;

	if (!cache_boot()) {
		return $nav;
	}

	if (get_current_page() === 'settings.php' && (PHP_SAPI === 'cli' || ($current_user['enabled'] ?? '') === 'on')) {
		cache_config_settings();
	}

	return array_merge($nav, \Somethingwithproof\Cache\Pages::navigation());
}

/**
 * Resolve 3-tier realm inheritance: Cache Admin > Cache Operator > Cache View.
 *
 * @return void
 */
function cache_auth_alternate_realms(): void {
	global $user_auth_realm_filenames;

	static $deferred = array();

	foreach ($deferred as $file => $realm) {
		$user_auth_realm_filenames[$file] = $realm;
	}
	$deferred = array();

	if (!api_plugin_is_enabled('cache')) {
		return;
	}

	if (empty($_SESSION['sess_user_id'])) {
		$page = get_current_page();

		if (in_array($page, array('cache_view.php', 'cache_operator.php'), true) && isset($user_auth_realm_filenames[$page])) {
			$deferred[$page]                  = $user_auth_realm_filenames[$page];
			$user_auth_realm_filenames[$page] = -1;
		}

		return;
	}

	$adminRealm    = is_numeric($user_auth_realm_filenames['cache_admin.php'] ?? null) ? (int) $user_auth_realm_filenames['cache_admin.php'] : 0;
	$operatorRealm = is_numeric($user_auth_realm_filenames['cache_operator.php'] ?? null) ? (int) $user_auth_realm_filenames['cache_operator.php'] : 0;

	if ($adminRealm > 0 && is_realm_allowed($adminRealm)) {
		$user_auth_realm_filenames['cache_operator.php'] = $adminRealm;
		$user_auth_realm_filenames['cache_view.php']     = $adminRealm;
	} elseif ($operatorRealm > 0 && is_realm_allowed($operatorRealm)) {
		$user_auth_realm_filenames['cache_view.php'] = $operatorRealm;
	}
}

/**
 * Determine whether the current authenticated user is authorized for the active plugin page.
 *
 * @return bool
 */
function cache_page_allowed(): bool {
	global $user_auth_realm_filenames;

	cache_auth_alternate_realms();
	$page  = get_current_page();
	$realm = is_numeric($user_auth_realm_filenames[$page] ?? null) ? (int) $user_auth_realm_filenames[$page] : 0;

	return $realm > 0 && is_realm_allowed($realm);
}

/**
 * Guard plugin Console page execution (boot check, enabled check, and 3-tier RBAC check).
 *
 * @return bool True when page execution may continue; false after rendering notice or HTTP 403.
 */
function cache_page_start(): bool {
	if (cache_boot() && api_plugin_is_enabled('cache')) {
		if (cache_page_allowed()) {
			return true;
		}

		http_response_code(403);

		return false;
	}

	top_header();
	html_start_box(__('Object Cache & Batch Queue', 'cache'), '100%', false, 3, 'center', '');
	print '<tr><td>' . html_escape(cache_php_supported() ? __('The Cache plugin is not enabled.', 'cache') : __('The Cache plugin requires PHP %s or later.', '8.2.0', 'cache')) . '</td></tr>';
	html_end_box();
	bottom_footer();

	return false;
}

/**
 * Poller bottom hook: prune expired cache entries and reclaim expired queue leases.
 *
 * @return void
 */
function cache_poller_bottom(): void {
	if (!cache_boot()) {
		return;
	}

	try {
		cache_service()->pruneExpired();
		cache_queue_service()->reclaimExpiredLeases();
	} catch (\Throwable $error) {
		\Somethingwithproof\Cache\Log::warning('Poller maintenance skipped: ' . $error->getMessage());
	}
}

/**
 * plugin_casc casc_register_providers hook: register config-only provider for slug "cache".
 *
 * @param mixed $registrar ProviderRegistrar passed by plugin_casc.
 * @return void
 */
function cache_casc_register_providers(mixed $registrar): void {
	if (!cache_boot() || !is_object($registrar)) {
		return;
	}

	\Somethingwithproof\Cache\Integration\CascProvider::register(
		$registrar,
		static function (): \Somethingwithproof\Cache\Settings {
			return cache_settings();
		},
		static function (\Somethingwithproof\Cache\Settings $settings): bool {
			return cache_save_settings($settings);
		}
	);
}

/**
 * plugin_doctor doctor_register_checks hook: register cache and queue diagnostic checks.
 *
 * @param mixed $checks Existing checks array from plugin_doctor.
 * @return mixed
 */
function cache_doctor_register_checks(mixed $checks): mixed {
	if (!cache_boot()) {
		return $checks;
	}

	$built = \Somethingwithproof\Cache\Integration\DoctorChecks::buildChecks(
		static function (): \Somethingwithproof\Cache\Cache\CacheService {
			return cache_service();
		},
		static function (): \Somethingwithproof\Cache\Queue\QueueService {
			return cache_queue_service();
		}
	);

	if (is_array($checks)) {
		return array_merge($checks, $built);
	}

	return $built;
}

/**
 * plugin_doctor doctor_register_debug_info hook: contribute non-secret cache debug info.
 *
 * @param mixed $info Existing debug info map from plugin_doctor.
 * @return mixed
 */
function cache_doctor_register_debug_info(mixed $info): mixed {
	if (!cache_boot()) {
		return $info;
	}

	$section = \Somethingwithproof\Cache\Integration\DoctorChecks::buildDebugInfo(
		static function (): \Somethingwithproof\Cache\Cache\CacheService {
			return cache_service();
		},
		static function (): \Somethingwithproof\Cache\Queue\QueueService {
			return cache_queue_service();
		},
		static function (): \Somethingwithproof\Cache\Settings {
			return cache_settings();
		}
	);

	if (is_array($info)) {
		$info['cache'] = $section;

		return $info;
	}

	return array('cache' => $section);
}

/**
 * plugin_mcp abilities_register hook: register read-only cache and queue abilities.
 *
 * @param mixed $registrar AbilitiesRegistrar passed by plugin_mcp.
 * @return void
 */
function cache_abilities_register(mixed $registrar): void {
	if (!cache_boot() || !is_object($registrar)) {
		return;
	}

	\Somethingwithproof\Cache\Integration\McpAbilities::register(
		$registrar,
		static function (): \Somethingwithproof\Cache\Cache\CacheService {
			return cache_service();
		},
		static function (): \Somethingwithproof\Cache\Queue\QueueService {
			return cache_queue_service();
		},
		static function (int $userId): bool {
			return \Somethingwithproof\Cache\Integration\McpAbilities::userHasViewRealm($userId);
		}
	);
}

/**
 * plugin_api api_register_resources hook: return plain-array descriptors for cacheGroups and cacheQueues.
 *
 * @param mixed $resources Existing resource list from plugin_api.
 * @return mixed
 */
function cache_api_register_resources(mixed $resources): mixed {
	if (!cache_boot()) {
		return $resources;
	}

	$built = \Somethingwithproof\Cache\Integration\ApiResources::build(
		static function (): \Somethingwithproof\Cache\Cache\CacheService {
			return cache_service();
		},
		static function (): \Somethingwithproof\Cache\Queue\QueueService {
			return cache_queue_service();
		}
	);

	if (is_array($resources)) {
		return array_merge($resources, $built);
	}

	return $built;
}
