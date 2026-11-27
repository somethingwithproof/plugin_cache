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

declare(strict_types=1);

namespace Somethingwithproof\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Cache\MemoryDriver;
use Somethingwithproof\Cache\Cli\CliRunner;
use Somethingwithproof\Cache\Config\ConfigSchema;
use Somethingwithproof\Cache\Integration\ApiResources;
use Somethingwithproof\Cache\Integration\CascProvider;
use Somethingwithproof\Cache\Integration\DoctorChecks;
use Somethingwithproof\Cache\Integration\McpAbilities;
use Somethingwithproof\Cache\Log;
use Somethingwithproof\Cache\Pages;
use Somethingwithproof\Cache\Queue\MemoryQueueStorage;
use Somethingwithproof\Cache\Queue\QueueService;
use Somethingwithproof\Cache\Schema;
use Somethingwithproof\Cache\Settings;
use Somethingwithproof\Cache\Ui\CsrfGuard;
use Somethingwithproof\Cache\Ui\PagesRenderer;

final class SetupCliConfigAndIntegrationsTest extends TestCase {
	protected function tearDown(): void {
		cache_reset_runtime();
		$GLOBALS['__cache_options']        = [];
		$GLOBALS['__cache_allowed_realms'] = [];
		$GLOBALS['__cache_enabled']        = true;
		$GLOBALS['__cache_current_page']   = 'index.php';
	}

	public function testConfigSchemaSettingsPagesAndLog(): void {
		$defaults = Settings::fromRow([]);
		$this->assertSame('mariadb', $defaults->driver);
		$this->assertSame(300, $defaults->defaultTtl);
		$this->assertSame(1.0, $defaults->xfetchBeta);
		$this->assertSame(1000, $defaults->memoryCapacity);
		$this->assertSame([], ConfigSchema::validate($defaults->toConfigArray()));
		$this->assertCount(3, ConfigSchema::stateTables());
		$this->assertArrayHasKey('cache_driver', $defaults->toCactiSettingsMap());
		$this->assertArrayHasKey('cache_xfetch_beta', Settings::form());

		// Secret resolution supports env:VAR and ignores unconfigured refs.
		$withRef = Settings::fromRow(['cache_redis_secret_ref' => 'env:MY_REDIS_PASS', 'cache_redis_tls' => 'on']);
		$this->assertTrue($withRef->redisTls);
		$this->assertSame('top-secret', $withRef->resolveRedisPassword(static fn (string $k): ?string => $k === 'MY_REDIS_PASS' ? 'top-secret' : null));
		$this->assertNull($defaults->resolveRedisPassword());

		// Validation rejects invalid types, ranges, enums, patterns, and unknown keys.
		$errors = ConfigSchema::validate([
			'cache_driver'           => 'invalid_driver',
			'cache_default_ttl'      => 0,
			'cache_xfetch_beta'      => 99.5,
			'cache_redis_tls'        => 'not-bool',
			'cache_redis_secret_ref' => 'raw-plaintext-password',
			'plugin_cache_entries'   => [],
		]);
		$this->assertCount(6, $errors);

		$this->assertCount(3, Pages::realms());
		$this->assertCount(3, Pages::files());
		$this->assertCount(3, Pages::menu());
		$this->assertCount(4, Pages::upgradeTriggers());
		$this->assertCount(3, Pages::navigation());
		$this->assertCount(3, Schema::tables());

		$beforeLogs = count($GLOBALS['__cache_log']);
		Log::info('i');
		Log::warning('w');
		Log::error('e');
		$this->assertSame($beforeLogs + 3, count($GLOBALS['__cache_log']));
	}

	public function testCliRunnerAllCommandsAndMaintenanceWorker(): void {
		$cache  = new CacheService(new MemoryDriver(50), 300, 65536, 15, 1.0, 0, 1);
		$queue  = new QueueService(new MemoryQueueStorage());
		$runner = new CliRunner($cache, $queue);

		$help = $runner->run(['--help']);
		$this->assertSame(0, $help['exitCode']);
		$this->assertStringContainsString('queue-run', $help['stdout']);

		$cache->set('k1', ['a' => 1], 300, 'devices', ['device:10']);
		$statsText = $runner->run(['stats']);
		$this->assertSame(0, $statsText['exitCode']);
		$this->assertStringContainsString('Driver: memory', $statsText['stdout']);

		$statsJson = $runner->run(['stats', '--format=json']);
		$this->assertSame(0, $statsJson['exitCode']);
		$decodedStats = json_decode($statsJson['stdout'], true, 16, JSON_THROW_ON_ERROR);
		$this->assertSame('memory', $decodedStats['driver']);

		$inv = $runner->run(['invalidate-tag', 'device:10', '--format=json']);
		$this->assertSame(0, $inv['exitCode']);
		$this->assertNull($cache->get('k1', 'devices'));
		$this->assertSame(1, $runner->run(['invalidate-tag'])['exitCode']);

		$cache->set('k2', 'v2', 300, 'devices');
		$this->assertSame(0, $runner->run(['flush', '--group=devices'])['exitCode']);
		$this->assertSame(0, $runner->run(['flush', '--all', '--format=json'])['exitCode']);
		$this->assertSame(1, $runner->run(['flush'])['exitCode']);

		$this->assertSame(0, $runner->run(['prune'])['exitCode']);
		$this->assertSame(0, $runner->run(['prune', '--format=json'])['exitCode']);
		$this->assertSame(0, $runner->run(['queue-status'])['exitCode']);

		// Enqueue maintenance actions and execute through built-in cache_maintenance handler.
		$queue->enqueue('cache_maintenance', ['action' => 'flush_group', 'group' => 'devices']);
		$queue->enqueue('cache_maintenance', ['action' => 'invalidate_tags', 'tags' => ['device:1']]);
		$queue->enqueue('cache_maintenance', ['action' => 'prune']);
		$queue->enqueue('cache_maintenance', ['action' => 'unknown_op'], null, 1);

		$qStatus = $runner->run(['queue-status', '--queue=cache_maintenance', '--format=json']);
		$this->assertSame(0, $qStatus['exitCode']);
		$this->assertStringContainsString('cache_maintenance', $qStatus['stdout']);

		$qRun = $runner->run(['queue-run', '--queue=cache_maintenance', '--limit=10', '--format=json']);
		$this->assertSame(0, $qRun['exitCode']);
		$runData = json_decode($qRun['stdout'], true, 16, JSON_THROW_ON_ERROR);
		$this->assertSame(3, $runData['completed']);
		$this->assertSame(1, $runData['deadLettered']);

		// Queue without custom handler reclaims expired leases cleanly.
		$this->assertSame(0, $runner->run(['queue-run', '--queue=custom_q'])['exitCode']);
		$this->assertSame(1, $runner->run(['queue-run'])['exitCode']);
		$this->assertSame(1, $runner->run(['stats', '--format=xml'])['exitCode']);
		$this->assertSame(1, $runner->run(['nonexistent'])['exitCode']);
	}

	public function testEcosystemHooksCascDoctorMcpAndApi(): void {
		$settings = Settings::fromRow(['cache_driver' => 'memory', 'cache_redis_secret_ref' => 'env:REDIS_PW']);
		$cache    = new CacheService(new MemoryDriver(50), 300, 65536, 15, 1.0, 0, 1);
		$queue    = new QueueService(new MemoryQueueStorage());

		$cache->set('secret_entry', ['token' => 'never-export-this'], 300, 'devices');
		$queue->enqueue('sync_jobs', ['payload_secret' => 'never-leak']);

		// 1. CasC provider (config-only export, validate, plan, apply).
		$captured = null;
		$registrar = new class ($captured) {
			/** @var mixed */
			public mixed $registered;

			/** @param mixed $ref */
			public function __construct(mixed &$ref) {
				$this->registered = &$ref;
			}

			/** @param mixed $descriptor */
			public function register(mixed $descriptor): void {
				$this->registered = $descriptor;
			}
		};

		$saved = null;
		$this->assertTrue(CascProvider::register(
			$registrar,
			static fn (): Settings => $settings,
			static function (Settings $next) use (&$saved): bool {
				$saved = $next;

				return true;
			}
		));
		$this->assertIsArray($captured);
		$this->assertSame('cache', $captured['slug']);

		$exported = ($captured['export'])([]);
		$this->assertSame('memory', $exported['cache_driver']);
		$this->assertSame('env:REDIS_PW', $exported['cache_redis_secret_ref']);
		$this->assertArrayNotHasKey('plugin_cache_entries', $exported);

		$this->assertSame([], ($captured['validate'])(['cache_default_ttl' => 600]));
		$plan = ($captured['plan'])(['cache_default_ttl' => 600], []);
		$this->assertCount(1, $plan);
		$this->assertSame('update', $plan[0]['operation']);

		$applied = ($captured['apply'])(['cache_default_ttl' => 600], 'hash123', []);
		$this->assertTrue($applied['ok']);
		$this->assertInstanceOf(Settings::class, $saved);
		$this->assertSame(600, $saved->defaultTtl);

		// 2. Doctor checks (mode=list and mode=run) & debug info.
		$factoryCalls = 0;
		$listChecks   = DoctorChecks::buildChecks(
			static function () use ($cache, &$factoryCalls): CacheService {
				$factoryCalls++;

				return $cache;
			},
			static function () use ($queue, &$factoryCalls): QueueService {
				$factoryCalls++;

				return $queue;
			},
			['mode' => 'list', 'plugin' => 'cache']
		);
		$this->assertSame(0, $factoryCalls);
		$this->assertCount(3, $listChecks);

		foreach ($listChecks as $check) {
			$this->assertStringStartsWith('cache.', $check['id']);
			$this->assertSame('cache', $check['plugin']);
			$this->assertSame('info', $check['status']);
		}

		$checks = DoctorChecks::buildChecks(
			static fn (): CacheService => $cache,
			static fn (): QueueService => $queue,
			['mode' => 'run', 'plugin' => 'cache']
		);
		$this->assertCount(3, $checks);

		foreach ($checks as $check) {
			$this->assertStringStartsWith('cache.', $check['id']);
			$this->assertSame('cache', $check['plugin']);
			$this->assertSame('Cache', $check['category']);
			$this->assertNotSame('', $check['title']);
			$this->assertSame('ok', $check['status'], $check['id']);
			$this->assertNotSame('', $check['message']);
			$this->assertIsArray($check['details']);
			$this->assertIsString($check['remediation']);
		}

		$debug = DoctorChecks::buildDebugInfo(
			static fn (): CacheService => $cache,
			static fn (): QueueService => $queue,
			static fn (): Settings => $settings,
			'0.1.0'
		);
		$this->assertCount(1, $debug);
		$this->assertSame('cache.overview', $debug[0]['id']);
		$this->assertSame('cache', $debug[0]['plugin']);
		$this->assertSame('configured', $debug[0]['fields']['redis_secret_ref']['value']);

		foreach ($debug[0]['fields'] as $fieldKey => $field) {
			$this->assertIsString($field['label'], 'Field label must be string: ' . $fieldKey);
			$this->assertTrue(is_scalar($field['value']), 'Field value must be scalar: ' . $fieldKey);
		}

		$this->assertStringNotContainsString('never-export-this', json_encode($debug, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('never-leak', json_encode($debug, JSON_THROW_ON_ERROR));

		// 3. MCP abilities.
		$abilities    = [];
		$mcpRegistrar = new class ($abilities) {
			/** @var array<string, array<string, mixed>> */
			public array $items;

			/** @param array<string, array<string, mixed>> $ref */
			public function __construct(array &$ref) {
				$this->items = &$ref;
			}

			/** @param string $name @param array<string, mixed> $def */
			public function register(string $name, array $def): void {
				$this->items[$name] = $def;
			}
		};

		McpAbilities::register(
			$mcpRegistrar,
			static fn (): CacheService => $cache,
			static fn (): QueueService => $queue,
			static fn (int $uid): bool => $uid > 0
		);
		$this->assertArrayHasKey('cache-get-stats', $abilities);
		$this->assertArrayHasKey('cache-list-groups', $abilities);
		$this->assertArrayHasKey('cache-queue-status', $abilities);

		// Every type: array in input_schema/output_schema must define both items and maxItems.
		foreach ($abilities as $abilityId => $spec) {
			foreach (['input_schema', 'output_schema'] as $schemaKey) {
				$schema = $spec[$schemaKey] ?? [];

				if (is_array($schema) && is_array($schema['properties'] ?? null)) {
					foreach ($schema['properties'] as $propName => $propSchema) {
						if (is_array($propSchema) && ($propSchema['type'] ?? null) === 'array') {
							$this->assertArrayHasKey('items', $propSchema, sprintf('%s.%s.%s missing items', $abilityId, $schemaKey, $propName));
							$this->assertIsInt($propSchema['maxItems'] ?? null, sprintf('%s.%s.%s missing integer maxItems', $abilityId, $schemaKey, $propName));
						}
					}
				}
			}
		}

		$this->assertSame(256, $abilities['cache-list-groups']['output_schema']['properties']['groups']['maxItems']);
		$this->assertSame(256, $abilities['cache-queue-status']['output_schema']['properties']['queues']['maxItems']);

		$mcpStats  = ($abilities['cache-get-stats']['execute'])(1, new \stdClass());
		$mcpGroups = ($abilities['cache-list-groups']['execute'])(1, new \stdClass());
		$mcpQueues = ($abilities['cache-queue-status']['execute'])(1, new \stdClass());
		$this->assertSame('memory', $mcpStats['driver']);
		$this->assertSame('devices', $mcpGroups['groups'][0]['group']);
		$this->assertSame('sync_jobs', $mcpQueues['queues'][0]['queue']);
		$this->assertFalse(McpAbilities::userHasViewRealm(0));

		// 4. API resources (canonical plugin_api descriptors).
		$resources = ApiResources::build(static fn (): CacheService => $cache, static fn (): QueueService => $queue);
		$this->assertCount(2, $resources);
		$this->assertSame('cache', $resources[0]['plugin']);
		$this->assertSame('cacheGroups', $resources[0]['collection']);
		$this->assertSame('cache_view.php', $resources[0]['realm_read']);
		$this->assertCount(1, ($resources[0]['handlers']['list'])(['user_id' => 1]));
		$this->assertSame('devices', ($resources[0]['handlers']['get'])('devices', ['user_id' => 1])['group']);
		$this->assertNull(($resources[0]['handlers']['get'])('missing', ['user_id' => 1]));

		$this->assertSame('cache', $resources[1]['plugin']);
		$this->assertSame('cacheQueues', $resources[1]['collection']);
		$this->assertSame('cache_view.php', $resources[1]['realm_read']);
		$this->assertCount(1, ($resources[1]['handlers']['list'])(['user_id' => 1]));
		$this->assertSame('sync_jobs', ($resources[1]['handlers']['get'])('sync_jobs', ['user_id' => 1])['queue']);
		$this->assertNull(($resources[1]['handlers']['get'])('missing', ['user_id' => 1]));
	}

	public function testProceduralSetupHelpersHooksAndUiRenderers(): void {
		$this->assertTrue(cache_php_supported());
		$this->assertTrue(cache_boot());
		$this->assertFalse(cache_refuse_unsupported_php());

		$info = plugin_cache_version();
		$this->assertSame('cache', $info['name']);
		$this->assertSame('Object Cache & Batch Queue', $info['longname']);
		$this->assertSame('1.2.31', $info['compat']);

		$cache = new CacheService(new MemoryDriver(50), 300, 65536, 15, 1.0, 0, 1);
		$queue = new QueueService(new MemoryQueueStorage());
		cache_reset_runtime($cache, $queue);

		$this->assertTrue(cacti_cache_set('k', ['hello' => 'world'], 60, 'default', ['tag:1']));
		$this->assertSame(['hello' => 'world'], cacti_cache_get('k'));
		$this->assertSame('rem', cacti_cache_remember('r1', 60, static fn (): string => 'rem'));
		$this->assertSame(1, cacti_cache_invalidate_tags(['tag:1']));
		$this->assertNull(cacti_cache_get('k'));
		$this->assertTrue(cacti_cache_delete('r1'));

		// Exercise setup.php hooks.
		$GLOBALS['menu']     = [];
		$GLOBALS['tabs']     = [];
		$GLOBALS['settings'] = [];
		cache_config_arrays();
		cache_config_settings();
		$this->assertArrayHasKey('cache', $GLOBALS['tabs']);
		$nav = cache_draw_navigation_text([]);
		$this->assertArrayHasKey('cache_view.php:', $nav);
		cache_poller_bottom();

		$checks = cache_doctor_register_checks(['mode' => 'run', 'plugin' => 'cache']);
		$this->assertCount(3, $checks);
		$this->assertArrayNotHasKey('mode', $checks);
		$this->assertSame('cache.driver_connectivity', $checks[0]['id']);

		$debug = cache_doctor_register_debug_info(['plugin' => 'cache']);
		$this->assertCount(1, $debug);
		$this->assertArrayNotHasKey('plugin', $debug);
		$this->assertSame('cache.overview', $debug[0]['id']);
		$this->assertSame('cache', $debug[0]['plugin']);

		$resources = cache_api_register_resources([]);
		$this->assertCount(2, $resources);
		$this->assertSame('cacheGroups', $resources[0]['collection']);
		$this->assertSame('cacheQueues', $resources[1]['collection']);
		$this->assertSame('cache_view.php', $resources[0]['realm_read']);

		// Exercise PagesRenderer for all 3 console views.
		$session = [];
		$csrf    = CsrfGuard::hiddenField($session);
		$view    = PagesRenderer::renderViewDashboard($cache->stats(), $queue->status());
		$oper    = PagesRenderer::renderOperatorConsole($csrf, [], ['ok'], ['err']);
		$admin   = PagesRenderer::renderAdminConsole($csrf, Settings::fromRow([]), $cache->driver()->metrics(), ['ok'], []);

		$this->assertStringContainsString('Object Cache Overview', $view);
		$this->assertStringContainsString(CsrfGuard::FIELD_NAME, $oper);
		$this->assertStringContainsString('Driver &amp; Policy Configuration', $admin);
	}
}
