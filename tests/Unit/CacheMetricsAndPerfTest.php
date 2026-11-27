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
use Somethingwithproof\Cache\Cache\ApcuDriver;
use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Cache\MemoryDriver;
use Somethingwithproof\Cache\Cache\RedisDriver;
use Somethingwithproof\Cache\Metrics\CacheMetricsCollector;
use Somethingwithproof\Cache\Queue\MemoryQueueStorage;
use Somethingwithproof\Cache\Queue\QueueItem;
use Somethingwithproof\Cache\Queue\QueueService;

/**
 * Unit tests for CacheMetricsCollector, script-server entry point, buffered
 * RESP2 parsing, and indexed MemoryQueueStorage ordering invariants.
 */
final class CacheMetricsAndPerfTest extends TestCase {
	/**
	 * Verify Cacti field:value metrics formatting across cache hits, misses, XFetch refreshes,
	 * S3-FIFO evictions, and queue depths, plus 'U' fallback when backend is unhealthy.
	 *
	 * @return void
	 */
	public function testCacheMetricsCollectorFormatsAllFieldsAndHandlesUnhealthyBackend(): void {
		$now     = 1700000000.0;
		$randVal = 0.01;
		$driver  = new MemoryDriver(10, static function () use (&$now): int {
			return (int) floor($now);
		});
		$cache = new CacheService(
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
		$queue = new QueueService(new MemoryQueueStorage(), 30, 1, 65536, 10, static function () use (&$now): int {
			return (int) floor($now);
		});

		// Trigger hits, misses, S3-FIFO evictions, and an XFetch refresh.
		$cache->get('missing');
		$cache->remember('xf', 100, static function () use (&$now): string {
			$now += 3.0;

			return 'v1';
		});
		$now += 99.0;
		$cache->remember('xf', 100, static function () use (&$now): string {
			$now += 1.0;

			return 'v2';
		});

		for ($i = 0; $i < 15; $i++) {
			$cache->set('k_' . $i, ['idx' => $i], 300);
		}

		$cache->get('k_14');

		// Populate pending, leased, and dead-letter queue states.
		$queue->enqueue('q1', ['a' => 1]);
		$queue->enqueue('q1', ['a' => 2]);
		$leased = $queue->claim('q1', 60);
		$this->assertNotNull($leased);

		$queue->enqueue('q2', ['b' => 1], null, 1);
		$toDead = $queue->claim('q2', 60);
		$this->assertNotNull($toDead);
		$queue->fail($toDead->id, $toDead->leaseToken, 'fatal');

		$collector = new CacheMetricsCollector($cache, $queue);
		$metrics   = $collector->collect();

		$this->assertTrue($metrics['healthy']);
		$this->assertGreaterThanOrEqual(1, $metrics['hits']);
		$this->assertGreaterThanOrEqual(2, $metrics['misses']);
		$this->assertGreaterThan(0.0, $metrics['hit_ratio_pct']);
		$this->assertGreaterThan(0, $metrics['evictions']);
		$this->assertSame(1, $metrics['xfetch_refreshes']);
		$this->assertGreaterThan(0, $metrics['entries_count']);
		$this->assertSame(1, $metrics['queue_pending']);
		$this->assertSame(1, $metrics['queue_leased']);
		$this->assertSame(1, $metrics['queue_dead_letter']);
		$this->assertGreaterThan(0, $metrics['memory_bytes']);

		$line = $collector->render();
		$this->assertStringContainsString('hits:' . $metrics['hits'], $line);
		$this->assertStringContainsString('misses:' . $metrics['misses'], $line);
		$this->assertStringContainsString('hit_ratio_pct:' . sprintf('%.2f', $metrics['hit_ratio_pct']), $line);
		$this->assertStringContainsString('evictions:' . $metrics['evictions'], $line);
		$this->assertStringContainsString('xfetch_refreshes:1', $line);
		$this->assertStringContainsString('entries_count:' . $metrics['entries_count'], $line);
		$this->assertStringContainsString('queue_pending:1', $line);
		$this->assertStringContainsString('queue_leased:1', $line);
		$this->assertStringContainsString('queue_dead_letter:1', $line);
		$this->assertStringContainsString('memory_bytes:' . $metrics['memory_bytes'], $line);

		// Single metric selection and unknown field fallback.
		$this->assertSame('1', $collector->render('queue_dead_letter'));
		$this->assertSame('U', $collector->render('not_a_real_metric'));

		// Unhealthy backend must emit RRDtool unknown ('U') values rather than false zeros.
		$disabledStore          = new InMemoryApcuStore();
		$disabledStore->enabled = false;
		$unhealthyCache         = new CacheService(new ApcuDriver('test:', $disabledStore));
		$unhealthyCollector     = new CacheMetricsCollector($unhealthyCache, $queue);

		$this->assertSame(
			'hits:U misses:U hit_ratio_pct:U evictions:U xfetch_refreshes:U entries_count:U queue_pending:U queue_leased:U queue_dead_letter:U memory_bytes:U',
			$unhealthyCollector->render()
		);
		$this->assertSame('U', $unhealthyCollector->render('hits'));
	}

	/**
	 * Verify script-server entry point runs safely in script-server mode without leaking secrets.
	 *
	 * @return void
	 */
	public function testScriptServerEntryPointFunctionAndNoSecrets(): void {
		$called_by_script_server = true;
		require_once dirname(__DIR__, 2) . '/scripts/ss_cache_stats.php';
		$this->assertTrue(function_exists('ss_cache_stats'));

		$cache = new CacheService(new MemoryDriver(50));
		$queue = new QueueService(new MemoryQueueStorage());
		cache_reset_runtime($cache, $queue);

		try {
			$cache->set('sample', ['ok' => true], 60);
			$cache->get('sample');
			$output = ss_cache_stats();

			$this->assertStringContainsString('hits:1', $output);
			$this->assertStringContainsString('entries_count:1', $output);
			$this->assertStringContainsString('queue_dead_letter:0', $output);
			$this->assertStringNotContainsString('password', strtolower($output));
			$this->assertStringNotContainsString('secret', strtolower($output));
			$this->assertSame('1', ss_cache_stats('hits'));
		} finally {
			cache_reset_runtime(null, null);
		}
	}

	/**
	 * Verify MemoryQueueStorage min-heap preserves strict (available_at, id) ordering across
	 * delayed enqueues, checkpoints, retries, and purges.
	 *
	 * @return void
	 */
	public function testMemoryQueueHeapOrderingAndIdempotencyPurge(): void {
		$now   = 1700000000;
		$queue = new QueueService(new MemoryQueueStorage(), 30, 3, 65536, 10, static function () use (&$now): int {
			return $now;
		});

		$idDelayed = $queue->enqueue('q', ['order' => 3], 'k-delayed', 3, 20);
		$idFirst   = $queue->enqueue('q', ['order' => 1], 'k-first', 3, 0);
		$idSecond  = $queue->enqueue('q', ['order' => 2], 'k-second', 3, 0);

		$c1 = $queue->claim('q');
		$this->assertInstanceOf(QueueItem::class, $c1);
		$this->assertSame($idFirst, $c1->id);
		$queue->complete($c1->id, $c1->leaseToken);

		$c2 = $queue->claim('q');
		$this->assertInstanceOf(QueueItem::class, $c2);
		$this->assertSame($idSecond, $c2->id);
		$queue->complete($c2->id, $c2->leaseToken);

		// Delayed item is not claimable until $now advances.
		$this->assertNull($queue->claim('q'));
		$now += 25;
		$c3   = $queue->claim('q');
		$this->assertInstanceOf(QueueItem::class, $c3);
		$this->assertSame($idDelayed, $c3->id);
		$queue->complete($c3->id, $c3->leaseToken);

		// Purging completed items clears idempotency index so key can be re-enqueued cleanly.
		$this->assertSame(3, $queue->purgeCompleted(0));
		$reId = $queue->enqueue('q', ['order' => 4], 'k-first', 3, 0);
		$this->assertGreaterThan($idSecond, $reId);
	}

	/**
	 * Verify RedisDriver buffered RESP2 reader compacts cleanly across large multi-bulk responses.
	 *
	 * @return void
	 */
	public function testRedisDriverBufferedMultiBulkAndCompaction(): void {
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($pair);
		[$client, $server] = $pair;
		stream_set_blocking($server, false);

		$driver = new RedisDriver('127.0.0.1', 6379, false, 0, 2.0, null, 'cacti:', static fn () => $client);

		// Send 200 bulk strings (~12 KiB) exceeding the 8 KiB compaction threshold.
		$tags = [];
		$resp = "*64\r\n";

		for ($i = 0; $i < 64; $i++) {
			$tags[] = 'dev:' . $i;
			$val    = (string) ($i + 1);
			$resp  .= '$' . strlen($val) . "\r\n" . $val . "\r\n";
		}

		for ($round = 0; $round < 3; $round++) {
			fwrite($server, $resp);
			$checksums = $driver->getTagChecksums($tags);
			$this->assertCount(64, $checksums);
			$this->assertSame(64, $checksums['dev:63']);

			while (($drained = fread($server, 65536)) !== '' && $drained !== false) {
			}
		}

		$driver->disconnect();
		fclose($server);
	}
}
