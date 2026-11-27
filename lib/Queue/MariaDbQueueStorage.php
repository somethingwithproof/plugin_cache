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
use Somethingwithproof\Cache\Cache\JsonCodec;
use Somethingwithproof\Cache\Db\DbAdapter;

/**
 * MariaDB-backed queue storage operating on plugin_cache_queue with fenced lease tokens.
 */
final class MariaDbQueueStorage implements QueueStorage {
	/**
	 * Maximum backoff delay in seconds when retrying failed queue items.
	 */
	private const MAX_BACKOFF_SECONDS = 3600;

	/**
	 * Construct MariaDB queue storage.
	 *
	 * @param DbAdapter $db Prepared-statement database adapter.
	 */
	public function __construct(private readonly DbAdapter $db) {
	}

	/**
	 * Insert a new queue row or return the existing row ID on idempotency key collision.
	 *
	 * @param string $queueName Validated queue name.
	 * @param string $payloadJson Validated JSON payload string.
	 * @param int $availableAt Earliest claim timestamp.
	 * @param int $maxAttempts Maximum attempts before dead-lettering.
	 * @param string|null $cursorJson Optional initial cursor JSON.
	 * @param string|null $idempotencyKey Optional deduplication key.
	 * @param int $now Current Unix timestamp.
	 * @return int
	 */
	public function enqueue(
		string $queueName,
		string $payloadJson,
		int $availableAt,
		int $maxAttempts,
		?string $cursorJson,
		?string $idempotencyKey,
		int $now,
	): int {
		if ($idempotencyKey !== null) {
			$existing = $this->db->fetchCell(
				'SELECT id FROM plugin_cache_queue WHERE queue_name = ? AND idempotency_key = ?',
				[$queueName, $idempotencyKey]
			);

			if (is_numeric($existing) && (int) $existing > 0) {
				return (int) $existing;
			}
		}

		$ok = $this->db->execute(
			'INSERT IGNORE INTO plugin_cache_queue
				(queue_name, payload_json, status, available_at, lease_until, lease_token, attempts, max_attempts, cursor_json, idempotency_key, last_error, created_at, updated_at)
			 VALUES (?, ?, ?, ?, 0, ?, 0, ?, ?, ?, ?, ?, ?)',
			[
				$queueName,
				$payloadJson,
				QueueItem::STATUS_PENDING,
				$availableAt,
				'',
				$maxAttempts,
				$cursorJson,
				$idempotencyKey,
				'',
				$now,
				$now,
			]
		);

		if ($ok && $this->db->affectedRows() > 0) {
			return $this->db->lastInsertId();
		}

		if ($idempotencyKey !== null) {
			$existing = $this->db->fetchCell(
				'SELECT id FROM plugin_cache_queue WHERE queue_name = ? AND idempotency_key = ?',
				[$queueName, $idempotencyKey]
			);

			if (is_numeric($existing) && (int) $existing > 0) {
				return (int) $existing;
			}
		}

		throw new CacheException('Unable to enqueue item into plugin_cache_queue.');
	}

	/**
	 * Reclaim expired leases and atomically claim the oldest ready item using a fenced lease token.
	 *
	 * @param string $queueName Validated queue name.
	 * @param string $leaseToken Newly generated 32-char hex lease token.
	 * @param int $leaseSeconds Lease duration in seconds.
	 * @param int $now Current Unix timestamp.
	 * @return QueueItem|null
	 */
	public function claim(string $queueName, string $leaseToken, int $leaseSeconds, int $now): ?QueueItem {
		$this->reclaimExpiredLeases($queueName, $now);

		$leaseUntil = $now + max(1, $leaseSeconds);

		// Atomic fenced claim: update at most one ready row with our unique lease_token,
		// then fetch the row strictly matching lease_token so concurrent workers never collide.
		$updated = $this->db->execute(
			'UPDATE plugin_cache_queue
			 SET status = ?, lease_until = ?, lease_token = ?, attempts = attempts + 1, updated_at = ?
			 WHERE queue_name = ? AND status = ? AND available_at <= ?
			 ORDER BY available_at ASC, id ASC
			 LIMIT 1',
			[
				QueueItem::STATUS_LEASED,
				$leaseUntil,
				$leaseToken,
				$now,
				$queueName,
				QueueItem::STATUS_PENDING,
				$now,
			]
		);

		if (!$updated || $this->db->affectedRows() === 0) {
			return null;
		}

		$row = $this->db->fetchRow(
			'SELECT * FROM plugin_cache_queue WHERE queue_name = ? AND status = ? AND lease_token = ? LIMIT 1',
			[$queueName, QueueItem::STATUS_LEASED, $leaseToken]
		);

		return $row !== null ? $this->hydrateRow($row) : null;
	}

	/**
	 * Extend an active lease and optionally update cursor_json.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param int $newLeaseUntil New lease expiry timestamp.
	 * @param string|null $cursorJson Optional updated cursor JSON.
	 * @param bool $updateCursor Whether to overwrite cursor_json.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function heartbeat(
		int $itemId,
		string $leaseToken,
		int $newLeaseUntil,
		?string $cursorJson,
		bool $updateCursor,
		int $now,
	): bool {
		if ($updateCursor) {
			$ok = $this->db->execute(
				'UPDATE plugin_cache_queue
				 SET lease_until = ?, cursor_json = ?, updated_at = ?
				 WHERE id = ? AND status = ? AND lease_token = ?',
				[$newLeaseUntil, $cursorJson, $now, $itemId, QueueItem::STATUS_LEASED, $leaseToken]
			);
		} else {
			$ok = $this->db->execute(
				'UPDATE plugin_cache_queue
				 SET lease_until = ?, updated_at = ?
				 WHERE id = ? AND status = ? AND lease_token = ?',
				[$newLeaseUntil, $now, $itemId, QueueItem::STATUS_LEASED, $leaseToken]
			);
		}

		return $ok && $this->db->affectedRows() > 0;
	}

	/**
	 * Persist resumable cursor progress and return item to pending without counting as failure.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param string $cursorJson Serialized cursor state.
	 * @param int $availableAt Earliest timestamp for the next chunk.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function checkpoint(
		int $itemId,
		string $leaseToken,
		string $cursorJson,
		int $availableAt,
		int $now,
	): bool {
		$ok = $this->db->execute(
			'UPDATE plugin_cache_queue
			 SET status = ?, lease_until = 0, lease_token = ?, cursor_json = ?, available_at = ?,
			     attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END,
			     updated_at = ?
			 WHERE id = ? AND status = ? AND lease_token = ?',
			[
				QueueItem::STATUS_PENDING,
				'',
				$cursorJson,
				$availableAt,
				$now,
				$itemId,
				QueueItem::STATUS_LEASED,
				$leaseToken,
			]
		);

		return $ok && $this->db->affectedRows() > 0;
	}

	/**
	 * Transition a leased item to completed.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function complete(int $itemId, string $leaseToken, int $now): bool {
		$ok = $this->db->execute(
			'UPDATE plugin_cache_queue
			 SET status = ?, lease_until = 0, lease_token = ?, last_error = ?, updated_at = ?
			 WHERE id = ? AND status = ? AND lease_token = ?',
			[
				QueueItem::STATUS_COMPLETED,
				'',
				'',
				$now,
				$itemId,
				QueueItem::STATUS_LEASED,
				$leaseToken,
			]
		);

		return $ok && $this->db->affectedRows() > 0;
	}

	/**
	 * Record a failure and either schedule exponential backoff or move to dead-letter status.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param string $error Truncated error summary.
	 * @param int $baseBackoffSeconds Base backoff in seconds.
	 * @param int $now Current Unix timestamp.
	 * @return string Resulting status ('pending', 'dead', or '').
	 */
	public function fail(
		int $itemId,
		string $leaseToken,
		string $error,
		int $baseBackoffSeconds,
		int $now,
	): string {
		$row = $this->db->fetchRow(
			'SELECT attempts, max_attempts FROM plugin_cache_queue WHERE id = ? AND status = ? AND lease_token = ?',
			[$itemId, QueueItem::STATUS_LEASED, $leaseToken]
		);

		if ($row === null) {
			return '';
		}

		$attempts    = is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 1;
		$maxAttempts = is_numeric($row['max_attempts'] ?? null) ? (int) $row['max_attempts'] : 1;
		$truncated   = substr($error, 0, 512);

		if ($attempts >= $maxAttempts) {
			$ok = $this->db->execute(
				'UPDATE plugin_cache_queue
				 SET status = ?, lease_until = 0, lease_token = ?, last_error = ?, updated_at = ?
				 WHERE id = ? AND status = ? AND lease_token = ?',
				[QueueItem::STATUS_DEAD, '', $truncated, $now, $itemId, QueueItem::STATUS_LEASED, $leaseToken]
			);

			return $ok && $this->db->affectedRows() > 0 ? QueueItem::STATUS_DEAD : '';
		}

		$exponent = max(0, min(16, $attempts - 1));
		$delay    = min(self::MAX_BACKOFF_SECONDS, max(1, $baseBackoffSeconds) * (1 << $exponent));

		$ok = $this->db->execute(
			'UPDATE plugin_cache_queue
			 SET status = ?, lease_until = 0, lease_token = ?, available_at = ?, last_error = ?, updated_at = ?
			 WHERE id = ? AND status = ? AND lease_token = ?',
			[
				QueueItem::STATUS_PENDING,
				'',
				$now + $delay,
				$truncated,
				$now,
				$itemId,
				QueueItem::STATUS_LEASED,
				$leaseToken,
			]
		);

		return $ok && $this->db->affectedRows() > 0 ? QueueItem::STATUS_PENDING : '';
	}

	/**
	 * Reclaim expired leases in a single atomic UPDATE, dead-lettering exhausted rows
	 * and releasing remaining rows back to pending.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $now Current Unix timestamp.
	 * @return int
	 */
	public function reclaimExpiredLeases(?string $queueName, int $now): int {
		$expiredError = 'Lease expired after reaching max attempts';

		if ($queueName !== null) {
			$this->db->execute(
				'UPDATE plugin_cache_queue
				 SET status = CASE WHEN attempts >= max_attempts THEN ? ELSE ? END,
				     lease_until = 0,
				     lease_token = ?,
				     available_at = CASE WHEN attempts >= max_attempts THEN available_at ELSE ? END,
				     last_error = CASE WHEN attempts >= max_attempts THEN ? ELSE last_error END,
				     updated_at = ?
				 WHERE queue_name = ? AND status = ? AND lease_until > 0 AND lease_until <= ?',
				[
					QueueItem::STATUS_DEAD,
					QueueItem::STATUS_PENDING,
					'',
					$now,
					$expiredError,
					$now,
					$queueName,
					QueueItem::STATUS_LEASED,
					$now,
				]
			);

			return $this->db->affectedRows();
		}

		$this->db->execute(
			'UPDATE plugin_cache_queue
			 SET status = CASE WHEN attempts >= max_attempts THEN ? ELSE ? END,
			     lease_until = 0,
			     lease_token = ?,
			     available_at = CASE WHEN attempts >= max_attempts THEN available_at ELSE ? END,
			     last_error = CASE WHEN attempts >= max_attempts THEN ? ELSE last_error END,
			     updated_at = ?
			 WHERE status = ? AND lease_until > 0 AND lease_until <= ?',
			[
				QueueItem::STATUS_DEAD,
				QueueItem::STATUS_PENDING,
				'',
				$now,
				$expiredError,
				$now,
				QueueItem::STATUS_LEASED,
				$now,
			]
		);

		return $this->db->affectedRows();
	}

	/**
	 * Fetch dead-letter items ordered by updated_at descending.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $limit Maximum items to return.
	 * @return list<QueueItem>
	 */
	public function deadLetters(?string $queueName, int $limit): array {
		$boundedLimit = max(1, min(500, $limit));

		if ($queueName !== null) {
			$rows = $this->db->fetchAssoc(
				'SELECT * FROM plugin_cache_queue WHERE queue_name = ? AND status = ? ORDER BY updated_at DESC, id DESC LIMIT ' . $boundedLimit,
				[$queueName, QueueItem::STATUS_DEAD]
			);
		} else {
			$rows = $this->db->fetchAssoc(
				'SELECT * FROM plugin_cache_queue WHERE status = ? ORDER BY updated_at DESC, id DESC LIMIT ' . $boundedLimit,
				[QueueItem::STATUS_DEAD]
			);
		}

		$items = [];

		foreach ($rows as $row) {
			$items[] = $this->hydrateRow($row);
		}

		return $items;
	}

	/**
	 * Reset a dead-letter item to pending for redelivery.
	 *
	 * @param int $itemId Dead-letter item ID.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function retryDeadLetter(int $itemId, int $now): bool {
		$ok = $this->db->execute(
			'UPDATE plugin_cache_queue
			 SET status = ?, attempts = 0, available_at = ?, lease_until = 0, lease_token = ?, last_error = ?, updated_at = ?
			 WHERE id = ? AND status = ?',
			[QueueItem::STATUS_PENDING, $now, '', '', $now, $itemId, QueueItem::STATUS_DEAD]
		);

		return $ok && $this->db->affectedRows() > 0;
	}

	/**
	 * Delete completed rows older than $cutoffTimestamp.
	 *
	 * @param int $cutoffTimestamp Unix timestamp threshold.
	 * @return int
	 */
	public function purgeCompleted(int $cutoffTimestamp): int {
		$this->db->execute(
			'DELETE FROM plugin_cache_queue WHERE status = ? AND updated_at <= ?',
			[QueueItem::STATUS_COMPLETED, $cutoffTimestamp]
		);

		return $this->db->affectedRows();
	}

	/**
	 * Delete dead-letter rows, optionally filtered by queue name.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @return int
	 */
	public function purgeDeadLetters(?string $queueName): int {
		if ($queueName !== null) {
			$this->db->execute(
				'DELETE FROM plugin_cache_queue WHERE queue_name = ? AND status = ?',
				[$queueName, QueueItem::STATUS_DEAD]
			);
		} else {
			$this->db->execute(
				'DELETE FROM plugin_cache_queue WHERE status = ?',
				[QueueItem::STATUS_DEAD]
			);
		}

		return $this->db->affectedRows();
	}

	/**
	 * Load a single queue item by ID.
	 *
	 * @param int $itemId Queue item ID.
	 * @return QueueItem|null
	 */
	public function find(int $itemId): ?QueueItem {
		$row = $this->db->fetchRow('SELECT * FROM plugin_cache_queue WHERE id = ?', [$itemId]);

		return $row !== null ? $this->hydrateRow($row) : null;
	}

	/**
	 * Aggregate queue depths grouped by queue_name and lifecycle status.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $now Current Unix timestamp for expired lease checks.
	 * @return array<string, array{pending: int, leased: int, completed: int, dead: int, expiredLeases: int, total: int}>
	 */
	public function status(?string $queueName, int $now): array {
		if ($queueName !== null) {
			$rows = $this->db->fetchAssoc(
				'SELECT
					queue_name,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_cnt,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS leased_cnt,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_cnt,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS dead_cnt,
					SUM(CASE WHEN status = ? AND lease_until > 0 AND lease_until <= ? THEN 1 ELSE 0 END) AS expired_cnt,
					COUNT(*) AS total_cnt
				 FROM plugin_cache_queue
				 WHERE queue_name = ?
				 GROUP BY queue_name
				 ORDER BY queue_name',
				[
					QueueItem::STATUS_PENDING,
					QueueItem::STATUS_LEASED,
					QueueItem::STATUS_COMPLETED,
					QueueItem::STATUS_DEAD,
					QueueItem::STATUS_LEASED,
					$now,
					$queueName,
				]
			);
		} else {
			$rows = $this->db->fetchAssoc(
				'SELECT
					queue_name,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_cnt,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS leased_cnt,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_cnt,
					SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS dead_cnt,
					SUM(CASE WHEN status = ? AND lease_until > 0 AND lease_until <= ? THEN 1 ELSE 0 END) AS expired_cnt,
					COUNT(*) AS total_cnt
				 FROM plugin_cache_queue
				 GROUP BY queue_name
				 ORDER BY queue_name',
				[
					QueueItem::STATUS_PENDING,
					QueueItem::STATUS_LEASED,
					QueueItem::STATUS_COMPLETED,
					QueueItem::STATUS_DEAD,
					QueueItem::STATUS_LEASED,
					$now,
				]
			);
		}

		$result = [];

		foreach ($rows as $row) {
			$name = $row['queue_name'] ?? null;

			if (!is_string($name) || $name === '') {
				continue;
			}

			$result[$name] = [
				'pending'       => is_numeric($row['pending_cnt'] ?? null) ? (int) $row['pending_cnt'] : 0,
				'leased'        => is_numeric($row['leased_cnt'] ?? null) ? (int) $row['leased_cnt'] : 0,
				'completed'     => is_numeric($row['completed_cnt'] ?? null) ? (int) $row['completed_cnt'] : 0,
				'dead'          => is_numeric($row['dead_cnt'] ?? null) ? (int) $row['dead_cnt'] : 0,
				'expiredLeases' => is_numeric($row['expired_cnt'] ?? null) ? (int) $row['expired_cnt'] : 0,
				'total'         => is_numeric($row['total_cnt'] ?? null) ? (int) $row['total_cnt'] : 0,
			];
		}

		return $result;
	}

	/**
	 * Convert a database row into an immutable QueueItem value object.
	 *
	 * @param array<string, mixed> $row Raw associative row from plugin_cache_queue.
	 * @return QueueItem
	 */
	private function hydrateRow(array $row): QueueItem {
		$payload = [];

		if (is_string($row['payload_json'] ?? null) && $row['payload_json'] !== '') {
			try {
				$payload = JsonCodec::decodeArray($row['payload_json']);
			} catch (CacheException) {
				$payload = [];
			}
		}

		$cursor = null;

		if (is_string($row['cursor_json'] ?? null) && $row['cursor_json'] !== '') {
			try {
				$cursor = JsonCodec::decodeArray($row['cursor_json']);
			} catch (CacheException) {
				$cursor = null;
			}
		}

		return new QueueItem(
			is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
			is_string($row['queue_name'] ?? null) ? $row['queue_name'] : 'default',
			$payload,
			is_string($row['status'] ?? null) ? $row['status'] : QueueItem::STATUS_PENDING,
			is_numeric($row['available_at'] ?? null) ? (int) $row['available_at'] : 0,
			is_numeric($row['lease_until'] ?? null) ? (int) $row['lease_until'] : 0,
			is_string($row['lease_token'] ?? null) ? $row['lease_token'] : '',
			is_numeric($row['attempts'] ?? null) ? (int) $row['attempts'] : 0,
			is_numeric($row['max_attempts'] ?? null) ? (int) $row['max_attempts'] : 5,
			$cursor,
			is_string($row['idempotency_key'] ?? null) && $row['idempotency_key'] !== '' ? $row['idempotency_key'] : null,
			is_string($row['last_error'] ?? null) ? $row['last_error'] : '',
			is_numeric($row['created_at'] ?? null) ? (int) $row['created_at'] : 0,
			is_numeric($row['updated_at'] ?? null) ? (int) $row['updated_at'] : 0,
		);
	}
}
