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
use Somethingwithproof\Cache\Queue\MariaDbQueueStorage;
use Somethingwithproof\Cache\Queue\MemoryQueueStorage;
use Somethingwithproof\Cache\Queue\QueueItem;
use Somethingwithproof\Cache\Queue\QueueService;
use Somethingwithproof\Cache\Queue\QueueStorage;

final class QueueServiceTest extends TestCase {
	public function testMemoryQueueFullLifecycleResumableBatchAndDeadLetter(): void {
		$this->exerciseQueueLifecycle(new MemoryQueueStorage());
	}

	public function testMariaDbQueueStorageFullLifecycleAgainstSqlite(): void {
		$this->exerciseQueueLifecycle(new MariaDbQueueStorage(new SqliteMariaDbCompatAdapter()));
	}

	/**
	 * Exercise idempotency, fenced leases, resumable cursors, exponential backoff,
	 * lease expiry reclaim, dead-letter triage, and bounded runBatch() execution.
	 *
	 * @param QueueStorage $storage Backing queue storage under test.
	 * @return void
	 */
	private function exerciseQueueLifecycle(QueueStorage $storage): void {
		$now   = 1700000000;
		$queue = new QueueService($storage, 30, 3, 65536, 10, static function () use (&$now): int {
			return $now;
		});

		// Idempotent enqueue deduplicates on (queue_name, idempotency_key).
		$id1 = $queue->enqueue('fleet_sync', ['totalTargets' => 100000], 'fleet-2026-11-27');
		$id2 = $queue->enqueue('fleet_sync', ['totalTargets' => 100000], 'fleet-2026-11-27');
		$this->assertSame($id1, $id2);

		// Claim and verify fenced lease token + heartbeat + resumable checkpoint.
		$claimed = $queue->claim('fleet_sync', 30);
		$this->assertInstanceOf(QueueItem::class, $claimed);
		$this->assertSame($id1, $claimed->id);
		$this->assertSame(1, $claimed->attempts);
		$this->assertSame(QueueItem::STATUS_LEASED, $claimed->status);
		$this->assertSame($id1, $claimed->toArray(true)['id']);

		// Wrong token is rejected by fencing check.
		$this->assertFalse($queue->heartbeat($claimed->id, 'wrong-token', 60));
		$this->assertTrue($queue->heartbeat($claimed->id, $claimed->leaseToken, 60, ['offset' => 25000]));
		$this->assertTrue($queue->heartbeat($claimed->id, $claimed->leaseToken, 60));

		// Resumable batch runner processes remaining chunks via cursor checkpointing.
		$this->assertTrue($queue->checkpoint($claimed->id, $claimed->leaseToken, ['offset' => 50000]));

		$summary = $queue->runBatch('fleet_sync', static function (QueueItem $item): array {
			$offset = is_int($item->cursor['offset'] ?? null) ? $item->cursor['offset'] : 0;
			$next   = $offset + 25000;

			if ($next < 100000) {
				return ['status' => 'continue', 'cursor' => ['offset' => $next]];
			}

			return ['status' => 'completed'];
		}, 10, 10);

		$this->assertSame(2, $summary['claimed']);
		$this->assertSame(1, $summary['checkpointed']);
		$this->assertSame(1, $summary['completed']);
		$this->assertSame(0, $summary['deadLettered']);

		$finished = $queue->find($id1);
		$this->assertNotNull($finished);
		$this->assertSame(QueueItem::STATUS_COMPLETED, $finished->status);

		// Exponential backoff retries and dead-letter transition.
		$failId = $queue->enqueue('redfish_poll', ['host' => 'bmc-01'], null, 2);

		$attempt1 = $queue->claim('redfish_poll', 20);
		$this->assertNotNull($attempt1);
		$this->assertSame(QueueItem::STATUS_PENDING, $queue->fail($attempt1->id, $attempt1->leaseToken, "timeout\nline2"));
		$this->assertNull($queue->claim('redfish_poll', 20)); // Respects 10s backoff.

		$now += 11;
		$attempt2 = $queue->claim('redfish_poll', 20);
		$this->assertNotNull($attempt2);
		$this->assertSame(QueueItem::STATUS_DEAD, $queue->fail($attempt2->id, $attempt2->leaseToken, 'permanent error'));

		$dead = $queue->deadLetters('redfish_poll');
		$this->assertCount(1, $dead);
		$this->assertSame($failId, $dead[0]->id);
		$this->assertCount(1, $queue->deadLetters());

		// Retry dead-letter item, let its lease expire, and reclaim it.
		$this->assertTrue($queue->retryDeadLetter($failId));
		$this->assertFalse($queue->retryDeadLetter(0));
		$this->assertFalse($queue->retryDeadLetter($failId)); // Already pending.

		$leased = $queue->claim('redfish_poll', 10);
		$this->assertNotNull($leased);
		$now += 15;

		$statusBeforeReclaim = $queue->status('redfish_poll');
		$this->assertSame(1, $statusBeforeReclaim['redfish_poll']['expiredLeases']);
		$this->assertSame(1, $queue->reclaimExpiredLeases('redfish_poll'));

		// Exhaust remaining attempts via lease expiration reclaim -> moves to dead.
		$leased2 = $queue->claim('redfish_poll', 10);
		$this->assertNotNull($leased2);
		$now += 15;
		$this->assertSame(1, $queue->reclaimExpiredLeases());
		$this->assertSame(1, $queue->status()['redfish_poll']['dead']);

		// RunBatch exception handling path.
		$queue->enqueue('boom_q', ['x' => 1], null, 1);
		$boomSummary = $queue->runBatch('boom_q', static function (QueueItem $item): array {
			throw new \RuntimeException('simulated worker crash');
		}, 5, 10);
		$this->assertSame(1, $boomSummary['deadLettered']);

		// Purge dead letters and completed items.
		$this->assertSame(1, $queue->purgeDeadLetters('boom_q'));
		$this->assertSame(1, $queue->purgeDeadLetters());
		$now += 100;
		$this->assertSame(1, $queue->purgeCompleted(50));
	}
}
