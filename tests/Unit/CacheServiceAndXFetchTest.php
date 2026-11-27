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

final class CacheServiceAndXFetchTest extends TestCase {
	public function testBasicGetSetHasDeleteAndNullValues(): void {
		$now     = 1700000000.0;
		$clockI  = static function () use (&$now): int {
			return (int) floor($now);
		};
		$clockF  = static function () use (&$now): float {
			return $now;
		};
		$driver  = new MemoryDriver(100, $clockI);
		$service = new CacheService($driver, 60, 65536, 15, 1.0, 0, 1, $clockF);

		$this->assertSame('fallback', $service->get('missing', 'default', 'fallback'));
		$this->assertFalse($service->has('missing'));

		// Explicitly verify null and false values are cached unambiguously.
		$this->assertTrue($service->set('null_val', null, 60));
		$this->assertTrue($service->has('null_val'));
		$this->assertNull($service->get('null_val', 'default', 'not_used'));

		$this->assertTrue($service->set('false_val', false, 60));
		$this->assertFalse($service->get('false_val', 'default', true));

		$this->assertTrue($service->set('host:42', ['status' => 'up', 'rtt' => 4.25], 10, 'devices', ['device:42', 'plugin:redfish']));
		$this->assertSame(['status' => 'up', 'rtt' => 4.25], $service->get('host:42', 'devices'));
		$this->assertSame(['default' => 2, 'devices' => 1], $service->groups());

		// Advance clock past TTL.
		$now += 11.0;
		$this->assertNull($service->get('host:42', 'devices'));

		$this->assertTrue($service->delete('null_val'));
		$this->assertFalse($service->delete('null_val'));

		$this->assertSame(1, $service->flushGroup('default'));
		$this->assertSame(0, $service->flushAll());
	}

	public function testDrupalCacheTagInvalidation(): void {
		$now     = 1700000000.0;
		$driver  = new MemoryDriver(100, static function () use (&$now): int {
			return (int) floor($now);
		});
		$service = new CacheService($driver, 300, 65536, 15, 1.0, 0, 1, static function () use (&$now): float {
			return $now;
		});

		$service->set('summary:42', ['cpu' => 22], 300, 'metrics', ['device:42', 'plugin:redfish']);
		$service->set('summary:99', ['cpu' => 88], 300, 'metrics', ['device:99', 'plugin:redfish']);
		$service->set('untouched', ['ok' => true], 300, 'metrics', ['device:7']);

		$this->assertSame(1, $service->invalidateTags(['device:42']));
		$this->assertNull($service->get('summary:42', 'metrics'));
		$this->assertSame(['cpu' => 88], $service->get('summary:99', 'metrics'));
		$this->assertSame(['ok' => true], $service->get('untouched', 'metrics'));

		// Re-writing with device:42 captures the new checksum and becomes valid again.
		$service->set('summary:42', ['cpu' => 30], 300, 'metrics', ['device:42', 'plugin:redfish']);
		$this->assertSame(['cpu' => 30], $service->get('summary:42', 'metrics'));

		// Invalidating plugin:redfish invalidates both device:42 and device:99 entries in O(1).
		$this->assertSame(1, $service->invalidateTags(['plugin:redfish']));
		$this->assertNull($service->get('summary:42', 'metrics'));
		$this->assertNull($service->get('summary:99', 'metrics'));
		$this->assertSame(['ok' => true], $service->get('untouched', 'metrics'));
		$this->assertSame(0, $service->invalidateTags([]));

		$stats = $service->stats();
		$this->assertSame(2, $stats['tagInvalidations']);
		$this->assertGreaterThan(0, $stats['evictions']);
	}

	public function testXFetchProbabilisticEarlyExpirationAndStampedeProtection(): void {
		$now     = 1700000000.0;
		$randVal = 0.5;
		$driver  = new MemoryDriver(100, static function () use (&$now): int {
			return (int) floor($now);
		});
		$service = new CacheService(
			$driver,
			100,
			65536,
			15,
			1.0,
			0,
			1,
			static function () use (&$now): float {
				return $now;
			},
			static function () use (&$randVal): float {
				return $randVal;
			}
		);

		$calls = 0;

		// First remember() takes 4.0 seconds of simulated compute time (delta = 4.0), TTL = 100s -> expiresAt = 1700000104.
		$val1 = $service->remember('heavy_query', 100, static function () use (&$now, &$calls): array {
			$calls++;
			$now += 4.0;

			return ['version' => $calls];
		}, 'reports', ['report:daily']);

		$this->assertSame(['version' => 1], $val1);
		$this->assertSame(1, $calls);

		// At t = +10s with rand = 0.5: -4.0 * 1.0 * ln(0.5) ~= 2.77s; 10 + 2.77 < 100 -> NO early recompute.
		$now += 10.0;
		$val2 = $service->remember('heavy_query', 100, static function () use (&$calls): array {
			$calls++;

			return ['version' => $calls];
		}, 'reports', ['report:daily']);

		$this->assertSame(['version' => 1], $val2);
		$this->assertSame(1, $calls);

		// With beta = 0.0 explicitly disabled, even tiny rand near expiry does NOT early recompute.
		$now     = 1700000102.0;
		$randVal = 0.01;
		$val3    = $service->remember('heavy_query', 100, static function () use (&$calls): array {
			$calls++;

			return ['version' => $calls];
		}, 'reports', ['report:daily'], 0.0);

		$this->assertSame(['version' => 1], $val3);
		$this->assertSame(1, $calls);

		// Now with default beta = 1.0 at t = 1700000102 (2s left) and rand = 0.01:
		// -4.0 * 1.0 * ln(0.01) ~= 18.42s -> 1700000102 + 18.42 >= 1700000104 -> TRIGGERS XFetch early recompute!
		$val4 = $service->remember('heavy_query', 100, static function () use (&$now, &$calls): array {
			$calls++;
			$now += 2.0;

			return ['version' => $calls];
		}, 'reports', ['report:daily']);

		$this->assertSame(['version' => 2], $val4);
		$this->assertSame(2, $calls);
		$this->assertSame(1, $service->stats()['earlyRecomputes']);

		// Simulate lock contention during XFetch window: another worker holds the single-flight lock,
		// so this caller immediately returns the still-fresh cached value without recomputing!
		$now     = 1700000203.0;
		$randVal = 0.01;
		$lockKey = \Somethingwithproof\Cache\Cache\CacheKeyValidator::entryId('reports', 'heavy_query');
		$this->assertTrue($driver->acquireLock($lockKey, 'other-worker-token', 15));

		$val5 = $service->remember('heavy_query', 100, static function () use (&$calls): array {
			$calls++;

			return ['version' => $calls];
		}, 'reports', ['report:daily']);

		$this->assertSame(['version' => 2], $val5);
		$this->assertSame(2, $calls);
		$this->assertGreaterThanOrEqual(1, $service->stats()['lockContentions']);
		$driver->releaseLock($lockKey, 'other-worker-token');
	}

	public function testRememberSingleFlightContentionOnMissAndCorruptEnvelopeEviction(): void {
		$now     = 1700000000.0;
		$driver  = new MemoryDriver(100, static function () use (&$now): int {
			return (int) floor($now);
		});
		$service = new CacheService($driver, 60, 65536, 15, 1.0, 0, 2, static function () use (&$now): float {
			return $now;
		});

		// Corrupt raw payload in driver must be evicted cleanly on get().
		$driver->setRaw('default', 'corrupt', '{not-valid-json', 60);
		$this->assertNull($service->get('corrupt'));

		// Miss while lock is already held by another worker falls back after retries without deadlock.
		$lockKey = \Somethingwithproof\Cache\Cache\CacheKeyValidator::entryId('default', 'contended');
		$this->assertTrue($driver->acquireLock($lockKey, 'peer', 15));
		$computed = $service->remember('contended', 60, static fn (): string => 'computed');
		$this->assertSame('computed', $computed);
		$this->assertSame(1, $service->stats()['lockContentions']);
		$driver->releaseLock($lockKey, 'peer');

		// Prune expired entries through CacheService.
		$service->set('short', 'val', 5);
		$now += 6.0;
		$this->assertSame(1, $service->pruneExpired());
	}
}
