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

/**
 * Storage contract backing QueueService (MariaDB production table or in-memory test store).
 */
interface QueueStorage {
	/**
	 * Enqueue a new item or return the existing item ID when (queueName, idempotencyKey) already exists.
	 *
	 * @param string $queueName Validated queue name.
	 * @param string $payloadJson Validated JSON payload string.
	 * @param int $availableAt Unix timestamp when the item becomes claimable.
	 * @param int $maxAttempts Maximum delivery attempts before dead-lettering.
	 * @param string|null $cursorJson Optional JSON cursor string.
	 * @param string|null $idempotencyKey Optional per-queue deduplication key.
	 * @param int $now Current Unix timestamp.
	 * @return int Newly inserted or existing deduplicated item ID.
	 */
	public function enqueue(
		string $queueName,
		string $payloadJson,
		int $availableAt,
		int $maxAttempts,
		?string $cursorJson,
		?string $idempotencyKey,
		int $now,
	): int;

	/**
	 * Atomically reclaim expired leases and claim the next pending item in $queueName.
	 *
	 * @param string $queueName Validated queue name.
	 * @param string $leaseToken Newly generated 32-char hex lease token.
	 * @param int $leaseSeconds Lease duration in seconds.
	 * @param int $now Current Unix timestamp.
	 * @return QueueItem|null Claimed item or null when no item is ready.
	 */
	public function claim(string $queueName, string $leaseToken, int $leaseSeconds, int $now): ?QueueItem;

	/**
	 * Extend an active lease and optionally persist an updated cursor_json checkpoint.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param int $newLeaseUntil New lease expiration timestamp.
	 * @param string|null $cursorJson Optional updated cursor JSON (null leaves cursor unchanged).
	 * @param bool $updateCursor True when $cursorJson should overwrite stored cursor_json.
	 * @param int $now Current Unix timestamp.
	 * @return bool True when the lease token matched and the row was updated.
	 */
	public function heartbeat(
		int $itemId,
		string $leaseToken,
		int $newLeaseUntil,
		?string $cursorJson,
		bool $updateCursor,
		int $now,
	): bool;

	/**
	 * Save a resumable cursor checkpoint and release the item back to pending for the next chunk.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param string $cursorJson Serialized resumable cursor JSON.
	 * @param int $availableAt Earliest timestamp for the next chunk claim.
	 * @param int $now Current Unix timestamp.
	 * @return bool True when the item was checkpointed and released.
	 */
	public function checkpoint(
		int $itemId,
		string $leaseToken,
		string $cursorJson,
		int $availableAt,
		int $now,
	): bool;

	/**
	 * Mark a leased item as completed.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function complete(int $itemId, string $leaseToken, int $now): bool;

	/**
	 * Record a delivery failure, transitioning to pending (with backoff) or dead-letter status.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param string $error Truncated error summary.
	 * @param int $baseBackoffSeconds Base backoff in seconds for exponential retry scheduling.
	 * @param int $now Current Unix timestamp.
	 * @return string Resulting status ('pending', 'dead', or '' if lease token no longer matches).
	 */
	public function fail(
		int $itemId,
		string $leaseToken,
		string $error,
		int $baseBackoffSeconds,
		int $now,
	): string;

	/**
	 * Reclaim leased items whose lease_until <= $now (returning to pending or dead-lettering if exhausted).
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $now Current Unix timestamp.
	 * @return int Number of expired leases reclaimed.
	 */
	public function reclaimExpiredLeases(?string $queueName, int $now): int;

	/**
	 * List dead-letter queue items ordered by most recently updated first.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $limit Maximum items to return.
	 * @return list<QueueItem>
	 */
	public function deadLetters(?string $queueName, int $limit): array;

	/**
	 * Reset a single dead-letter item back to pending with attempts = 0.
	 *
	 * @param int $itemId Dead-letter item ID.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function retryDeadLetter(int $itemId, int $now): bool;

	/**
	 * Delete completed items updated before $cutoffTimestamp.
	 *
	 * @param int $cutoffTimestamp Unix timestamp threshold.
	 * @return int Number of deleted rows.
	 */
	public function purgeCompleted(int $cutoffTimestamp): int;

	/**
	 * Delete dead-letter items, optionally scoped to a single queue.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @return int Number of deleted rows.
	 */
	public function purgeDeadLetters(?string $queueName): int;

	/**
	 * Fetch a single queue item by ID for inspection.
	 *
	 * @param int $itemId Queue item ID.
	 * @return QueueItem|null
	 */
	public function find(int $itemId): ?QueueItem;

	/**
	 * Aggregate queue depth counts by queue name and lifecycle status.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $now Current Unix timestamp for expired lease detection.
	 * @return array<string, array{pending: int, leased: int, completed: int, dead: int, expiredLeases: int, total: int}>
	 */
	public function status(?string $queueName, int $now): array;
}
