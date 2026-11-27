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

namespace Somethingwithproof\Cache\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cache\Pages;
use Somethingwithproof\Cache\Queue\QueueItem;
use Somethingwithproof\Cache\Schema;
use Somethingwithproof\Cache\Settings;

final class MariaDbIntegrationTest extends TestCase {
	public function testSchemaHooksCacheTagsQueueAndIntegrationsOnLiveMariaDb(): void {
		$schema = new Schema(false);
		$this->assertTrue($schema->isCurrent());
		$this->assertTrue($schema->hooksRegistered());

		$realmCount = (int) db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?', ['cache']);
		$this->assertSame(count(Pages::realms()), $realmCount);

		cache_reset_runtime();
		$cache = cache_service();
		$queue = cache_queue_service();
		$this->assertSame('mariadb', $cache->driver()->name());
		$this->assertTrue($cache->driver()->ping());

		// Procedural soft-coupling helpers + Drupal cache tags on live MariaDB.
		$this->assertTrue(cacti_cache_set('device:42:summary', ['hostname' => 'edge-01', 'up' => true], 120, 'devices', ['device:42', 'plugin:redfish']));
		$this->assertSame(['hostname' => 'edge-01', 'up' => true], cacti_cache_get('device:42:summary', 'devices'));

		$remembered = cacti_cache_remember('computed:1', 120, static fn (): array => ['v' => 99], 'reports', ['report:1']);
		$this->assertSame(['v' => 99], $remembered);
		$this->assertSame(['v' => 99], cacti_cache_get('computed:1', 'reports'));

		$this->assertSame(1, cacti_cache_invalidate_tags(['device:42']));
		$this->assertNull(cacti_cache_get('device:42:summary', 'devices'));
		$this->assertSame(['v' => 99], cacti_cache_get('computed:1', 'reports'));
		$this->assertTrue(cacti_cache_delete('computed:1', 'reports'));

		// Resumable batch queue on live MariaDB plugin_cache_queue.
		$id1 = $queue->enqueue('ci_fleet', ['batch' => 'nightly'], 'ci-idem-1', 2);
		$id2 = $queue->enqueue('ci_fleet', ['batch' => 'nightly'], 'ci-idem-1', 2);
		$this->assertSame($id1, $id2);

		$item = $queue->claim('ci_fleet', 30);
		$this->assertInstanceOf(QueueItem::class, $item);
		$this->assertSame($id1, $item->id);
		$this->assertTrue($queue->checkpoint($item->id, $item->leaseToken, ['offset' => 5000]));

		$resumed = $queue->claim('ci_fleet', 30);
		$this->assertNotNull($resumed);
		$this->assertSame(['offset' => 5000], $resumed->cursor);
		$this->assertTrue($queue->complete($resumed->id, $resumed->leaseToken));

		// Verify settings persistence and ecosystem hooks.
		$updated = Settings::fromRow(['cache_driver' => 'mariadb', 'cache_default_ttl' => 600, 'cache_xfetch_beta' => 1.5]);
		$this->assertTrue(cache_save_settings($updated));
		$this->assertSame(600, cache_settings()->defaultTtl);
		$this->assertSame(1.5, cache_settings()->xfetchBeta);

		$checks = cache_doctor_register_checks([]);
		$this->assertIsArray($checks);
		$this->assertCount(3, $checks);

		foreach ($checks as $check) {
			$result = ($check['run'])();
			$this->assertSame('ok', $result['status']);
		}

		$debug = cache_doctor_register_debug_info([]);
		$this->assertIsArray($debug);
		$this->assertArrayHasKey('cache', $debug);

		$resources = cache_api_register_resources([]);
		$this->assertIsArray($resources);
		$this->assertCount(2, $resources);

		// Clean up CI test rows.
		$cache->flushAll();
		$queue->purgeCompleted(0);
	}
}
