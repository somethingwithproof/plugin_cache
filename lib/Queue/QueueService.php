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

namespace Somethingwithproof\Cache\Queue;

use Somethingwithproof\Cache\Cache\CacheException;
use Somethingwithproof\Cache\Cache\CacheKeyValidator;
use Somethingwithproof\Cache\Cache\JsonCodec;

/**
 * Drupal-style resumable Queue and Batch execution engine backed by plugin_cache_queue.
 *
 * Supports idempotent enqueue, fenced atomic lease claim with expiration reclaim,
 * exponential backoff retries, dead-letter inspection/retry, and resumable cursor
 * checkpointing for large fleet jobs spanning 100k+ targets.
 */
final class QueueService {
	/**
	 * Underlying queue persistence backend.
	 */
	private readonly QueueStorage $storage;

	/**
	 * Default lease duration in seconds.
	 */
	private readonly int $defaultLeaseSeconds;

	/**
	 * Default maximum delivery attempts before moving an item to dead-letter status.
	 */
	private readonly int $defaultMaxAttempts;

	/**
	 * Maximum permitted JSON payload or cursor size in bytes.
	 */
	private readonly int $maxPayloadBytes;

	/**
	 * Base exponential backoff in seconds on first retry.
	 */
	private readonly int $baseBackoffSeconds;

	/**
	 * Clock callable returning current Unix timestamp in seconds.
	 *
	 * @var callable(): int
	 */
	private readonly mixed $clock;

	/**
	 * Construct a QueueService around a QueueStorage implementation.
	 *
	 * @param QueueStorage $storage Backing queue storage.
	 * @param int $defaultLeaseSeconds Default lease duration in seconds.
	 * @param int $defaultMaxAttempts Default max attempts before dead-lettering.
	 * @param int $maxPayloadBytes Maximum JSON payload size in bytes.
	 * @param int $baseBackoffSeconds Base exponential backoff in seconds.
	 * @param (callable(): int)|null $clock Optional clock returning current Unix seconds.
	 */
	public function __construct(
		QueueStorage $storage,
		int $defaultLeaseSeconds = 60,
		int $defaultMaxAttempts = 5,
		int $maxPayloadBytes = 1048576,
		int $baseBackoffSeconds = 10,
		?callable $clock = null,
	) {
		$this->storage             = $storage;
		$this->defaultLeaseSeconds = max(5, $defaultLeaseSeconds);
		$this->defaultMaxAttempts  = max(1, $defaultMaxAttempts);
		$this->maxPayloadBytes     = max(1024, $maxPayloadBytes);
		$this->baseBackoffSeconds  = max(1, $baseBackoffSeconds);
		$this->clock               = $clock ?? static fn (): int => time();
	}

	/**
	 * Enqueue a JSON job payload with optional idempotency key, delay, and initial cursor.
	 *
	 * @param string $queueName Validated queue name.
	 * @param array<string, mixed> $payload JSON-serializable job payload.
	 * @param string|null $idempotencyKey Optional per-queue deduplication key.
	 * @param int|null $maxAttempts Optional max attempts override.
	 * @param int $delaySeconds Optional delay in seconds before the item becomes claimable.
	 * @param array<string, mixed>|null $cursor Optional initial resumable batch cursor.
	 * @return int Item ID (newly inserted or existing deduplicated ID).
	 * @throws CacheException When queue name, idempotency key, delay, or payload fail validation.
	 */
	public function enqueue(
		string $queueName,
		array $payload,
		?string $idempotencyKey = null,
		?int $maxAttempts = null,
		int $delaySeconds = 0,
		?array $cursor = null,
	): int {
		$validQueue = CacheKeyValidator::validateQueueName($queueName);
		$validIdem  = CacheKeyValidator::validateIdempotencyKey($idempotencyKey);

		if ($delaySeconds < 0) {
			throw new CacheException('Queue delaySeconds cannot be negative.');
		}

		$attempts    = max(1, min(50, $maxAttempts ?? $this->defaultMaxAttempts));
		$payloadJson = JsonCodec::encode($payload, $this->maxPayloadBytes);
		$cursorJson  = $cursor !== null ? JsonCodec::encode($cursor, $this->maxPayloadBytes) : null;
		$now         = ($this->clock)();

		return $this->storage->enqueue(
			$validQueue,
			$payloadJson,
			$now + $delaySeconds,
			$attempts,
			$cursorJson,
			$validIdem,
			$now
		);
	}

	/**
	 * Atomically reclaim expired leases and claim the next ready item in $queueName.
	 *
	 * @param string $queueName Validated queue name.
	 * @param int|null $leaseSeconds Optional lease duration override in seconds.
	 * @return QueueItem|null Claimed item or null if none ready.
	 * @throws CacheException When queue name is invalid.
	 */
	public function claim(string $queueName, ?int $leaseSeconds = null): ?QueueItem {
		$validQueue = CacheKeyValidator::validateQueueName($queueName);
		$lease      = max(1, $leaseSeconds ?? $this->defaultLeaseSeconds);
		$token      = bin2hex(random_bytes(16));

		return $this->storage->claim($validQueue, $token, $lease, ($this->clock)());
	}

	/**
	 * Extend an active lease and optionally persist a mid-chunk cursor checkpoint.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token from claim().
	 * @param int|null $extendSeconds Additional lease duration from now.
	 * @param array<string, mixed>|null $cursor Optional cursor update (pass null to leave unchanged).
	 * @return bool
	 * @throws CacheException When cursor encoding exceeds bounds.
	 */
	public function heartbeat(
		int $itemId,
		string $leaseToken,
		?int $extendSeconds = null,
		?array $cursor = null,
	): bool {
		$now          = ($this->clock)();
		$lease        = max(1, $extendSeconds ?? $this->defaultLeaseSeconds);
		$updateCursor = $cursor !== null;
		$cursorJson   = $updateCursor ? JsonCodec::encode($cursor, $this->maxPayloadBytes) : null;

		return $this->storage->heartbeat($itemId, $leaseToken, $now + $lease, $cursorJson, $updateCursor, $now);
	}

	/**
	 * Save resumable cursor progress and release the item back to pending for the next batch chunk.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token from claim().
	 * @param array<string, mixed> $cursor Resumable cursor state (e.g. offset, lastId, processedCount).
	 * @param int $delaySeconds Optional delay in seconds before resuming.
	 * @return bool
	 * @throws CacheException When cursor encoding fails or delaySeconds is negative.
	 */
	public function checkpoint(
		int $itemId,
		string $leaseToken,
		array $cursor,
		int $delaySeconds = 0,
	): bool {
		if ($delaySeconds < 0) {
			throw new CacheException('Queue checkpoint delaySeconds cannot be negative.');
		}

		$now        = ($this->clock)();
		$cursorJson = JsonCodec::encode($cursor, $this->maxPayloadBytes);

		return $this->storage->checkpoint($itemId, $leaseToken, $cursorJson, $now + $delaySeconds, $now);
	}

	/**
	 * Mark a leased queue item as completed.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @return bool
	 */
	public function complete(int $itemId, string $leaseToken): bool {
		return $this->storage->complete($itemId, $leaseToken, ($this->clock)());
	}

	/**
	 * Fail a leased queue item, applying exponential backoff or dead-lettering when attempts are exhausted.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param string $error Error summary (sanitized and truncated to 512 bytes).
	 * @param int|null $baseBackoffSeconds Optional base backoff override.
	 * @return string Resulting status ('pending', 'dead', or '' on lease mismatch).
	 */
	public function fail(
		int $itemId,
		string $leaseToken,
		string $error,
		?int $baseBackoffSeconds = null,
	): string {
		$cleanError = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error) ?? $error;
		$backoff    = max(1, $baseBackoffSeconds ?? $this->baseBackoffSeconds);

		return $this->storage->fail($itemId, $leaseToken, trim($cleanError), $backoff, ($this->clock)());
	}

	/**
	 * Reclaim expired leases across one or all queues.
	 *
	 * @param string|null $queueName Optional queue name filter.
	 * @return int Number of expired leases reclaimed.
	 * @throws CacheException When a non-null queue name is invalid.
	 */
	public function reclaimExpiredLeases(?string $queueName = null): int {
		$validQueue = $queueName !== null ? CacheKeyValidator::validateQueueName($queueName) : null;

		return $this->storage->reclaimExpiredLeases($validQueue, ($this->clock)());
	}

	/**
	 * Inspect dead-letter queue items.
	 *
	 * @param string|null $queueName Optional queue name filter.
	 * @param int $limit Maximum items to return (1..500).
	 * @return list<QueueItem>
	 * @throws CacheException When a non-null queue name is invalid.
	 */
	public function deadLetters(?string $queueName = null, int $limit = 50): array {
		$validQueue = $queueName !== null ? CacheKeyValidator::validateQueueName($queueName) : null;

		return $this->storage->deadLetters($validQueue, $limit);
	}

	/**
	 * Reset a single dead-letter item to pending for retry.
	 *
	 * @param int $itemId Dead-letter item ID.
	 * @return bool
	 */
	public function retryDeadLetter(int $itemId): bool {
		if ($itemId <= 0) {
			return false;
		}

		return $this->storage->retryDeadLetter($itemId, ($this->clock)());
	}

	/**
	 * Purge completed items older than $olderThanSeconds.
	 *
	 * @param int $olderThanSeconds Age threshold in seconds (0 purges all completed items).
	 * @return int Number of purged rows.
	 */
	public function purgeCompleted(int $olderThanSeconds = 86400): int {
		$cutoff = ($this->clock)() - max(0, $olderThanSeconds);

		return $this->storage->purgeCompleted($cutoff);
	}

	/**
	 * Purge dead-letter items for one or all queues.
	 *
	 * @param string|null $queueName Optional queue name filter.
	 * @return int Number of purged rows.
	 * @throws CacheException When a non-null queue name is invalid.
	 */
	public function purgeDeadLetters(?string $queueName = null): int {
		$validQueue = $queueName !== null ? CacheKeyValidator::validateQueueName($queueName) : null;

		return $this->storage->purgeDeadLetters($validQueue);
	}

	/**
	 * Look up a single queue item by ID.
	 *
	 * @param int $itemId Queue item ID.
	 * @return QueueItem|null
	 */
	public function find(int $itemId): ?QueueItem {
		return $this->storage->find($itemId);
	}

	/**
	 * Return queue depth metrics grouped by queue name.
	 *
	 * @param string|null $queueName Optional queue name filter.
	 * @return array<string, array{pending: int, leased: int, completed: int, dead: int, expiredLeases: int, total: int}>
	 * @throws CacheException When a non-null queue name is invalid.
	 */
	public function status(?string $queueName = null): array {
		$validQueue = $queueName !== null ? CacheKeyValidator::validateQueueName($queueName) : null;

		return $this->storage->status($validQueue, ($this->clock)());
	}

	/**
	 * Run a bounded Drupal QueueWorker-style batch loop over $queueName.
	 *
	 * The handler receives each claimed QueueItem and returns either:
	 * - ['status' => 'completed'] (or null/void) to complete the item,
	 * - ['status' => 'continue', 'cursor' => [...], 'delay' => 0] to checkpoint
	 *   resumable progress and yield for subsequent chunks, or
	 * - throws any Throwable to trigger exponential backoff or dead-lettering.
	 *
	 * @param string $queueName Validated queue name.
	 * @param callable(QueueItem): (array<string, mixed>|null) $handler Worker callback.
	 * @param int $maxItems Maximum claimed chunks to process in this run.
	 * @param int $timeBudgetSeconds Wall-clock budget in seconds.
	 * @param int|null $leaseSeconds Optional lease duration override.
	 * @return array{queue: string, claimed: int, completed: int, checkpointed: int, retried: int, deadLettered: int}
	 * @throws CacheException When queue name is invalid.
	 */
	public function runBatch(
		string $queueName,
		callable $handler,
		int $maxItems = 50,
		int $timeBudgetSeconds = 25,
		?int $leaseSeconds = null,
	): array {
		$validQueue   = CacheKeyValidator::validateQueueName($queueName);
		$limit        = max(1, $maxItems);
		$deadline     = ($this->clock)() + max(1, $timeBudgetSeconds);
		$claimed      = 0;
		$completed    = 0;
		$checkpointed = 0;
		$retried      = 0;
		$deadLettered = 0;

		while ($claimed < $limit && ($this->clock)() <= $deadline) {
			$item = $this->claim($validQueue, $leaseSeconds);

			if ($item === null) {
				break;
			}

			$claimed++;

			try {
				$result = $handler($item);

				if (is_array($result) && ($result['status'] ?? '') === 'continue') {
					$cursor = isset($result['cursor']) && is_array($result['cursor']) ? $result['cursor'] : [];
					$delay  = isset($result['delay']) && is_int($result['delay']) ? max(0, $result['delay']) : 0;

					$normalizedCursor = [];

					foreach ($cursor as $k => $v) {
						$normalizedCursor[(string) $k] = $v;
					}

					$this->checkpoint($item->id, $item->leaseToken, $normalizedCursor, $delay);
					$checkpointed++;

					continue;
				}

				$this->complete($item->id, $item->leaseToken);
				$completed++;
			} catch (\Throwable $error) {
				$outcome = $this->fail($item->id, $item->leaseToken, $error->getMessage());

				if ($outcome === QueueItem::STATUS_DEAD) {
					$deadLettered++;
				} else {
					$retried++;
				}
			}
		}

		return [
			'queue'        => $validQueue,
			'claimed'      => $claimed,
			'completed'    => $completed,
			'checkpointed' => $checkpointed,
			'retried'      => $retried,
			'deadLettered' => $deadLettered,
		];
	}
}
