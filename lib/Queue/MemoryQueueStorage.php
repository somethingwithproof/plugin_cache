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

use Somethingwithproof\Cache\Cache\JsonCodec;

/**
 * In-memory queue storage mirroring MariaDbQueueStorage semantics for standalone and unit tests.
 */
final class MemoryQueueStorage implements QueueStorage {
	/**
	 * Maximum backoff delay in seconds when retrying failed queue items.
	 */
	private const MAX_BACKOFF_SECONDS = 3600;

	/**
	 * Auto-increment sequence counter.
	 */
	private int $nextId = 1;

	/**
	 * Stored queue rows keyed by auto-increment ID.
	 *
	 * @var array<int, array{
	 *   id: int,
	 *   queue_name: string,
	 *   payload_json: string,
	 *   status: string,
	 *   available_at: int,
	 *   lease_until: int,
	 *   lease_token: string,
	 *   attempts: int,
	 *   max_attempts: int,
	 *   cursor_json: string|null,
	 *   idempotency_key: string|null,
	 *   last_error: string,
	 *   created_at: int,
	 *   updated_at: int
	 * }>
	 */
	private array $rows = [];

	/**
	 * Enqueue a new item or return the existing ID on idempotency collision.
	 *
	 * @param string $queueName Validated queue name.
	 * @param string $payloadJson Validated JSON payload string.
	 * @param int $availableAt Earliest claim timestamp.
	 * @param int $maxAttempts Maximum delivery attempts.
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
			foreach ($this->rows as $row) {
				if ($row['queue_name'] === $queueName && $row['idempotency_key'] === $idempotencyKey) {
					return $row['id'];
				}
			}
		}

		$id              = $this->nextId++;
		$this->rows[$id] = [
			'id'              => $id,
			'queue_name'      => $queueName,
			'payload_json'    => $payloadJson,
			'status'          => QueueItem::STATUS_PENDING,
			'available_at'    => $availableAt,
			'lease_until'     => 0,
			'lease_token'     => '',
			'attempts'        => 0,
			'max_attempts'    => $maxAttempts,
			'cursor_json'     => $cursorJson,
			'idempotency_key' => $idempotencyKey,
			'last_error'      => '',
			'created_at'      => $now,
			'updated_at'      => $now,
		];

		return $id;
	}

	/**
	 * Reclaim expired leases and claim the earliest ready pending item.
	 *
	 * @param string $queueName Validated queue name.
	 * @param string $leaseToken Newly generated lease token.
	 * @param int $leaseSeconds Lease duration in seconds.
	 * @param int $now Current Unix timestamp.
	 * @return QueueItem|null
	 */
	public function claim(string $queueName, string $leaseToken, int $leaseSeconds, int $now): ?QueueItem {
		$this->reclaimExpiredLeases($queueName, $now);

		$selectedId = null;

		foreach ($this->rows as $id => $row) {
			if ($row['queue_name'] !== $queueName || $row['status'] !== QueueItem::STATUS_PENDING || $row['available_at'] > $now) {
				continue;
			}

			if (
				$selectedId === null
				|| $row['available_at'] < $this->rows[$selectedId]['available_at']
				|| ($row['available_at'] === $this->rows[$selectedId]['available_at'] && $id < $selectedId)
			) {
				$selectedId = $id;
			}
		}

		if ($selectedId === null || !isset($this->rows[$selectedId])) {
			return null;
		}

		$selected                = $this->rows[$selectedId];
		$selected['status']      = QueueItem::STATUS_LEASED;
		$selected['lease_until'] = $now + max(1, $leaseSeconds);
		$selected['lease_token'] = $leaseToken;
		$selected['attempts']++;
		$selected['updated_at']  = $now;
		$this->rows[$selectedId] = $selected;

		return $this->hydrate($selected);
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
		if (!isset($this->rows[$itemId])) {
			return false;
		}

		$row = $this->rows[$itemId];

		if ($row['status'] !== QueueItem::STATUS_LEASED || !hash_equals($row['lease_token'], $leaseToken)) {
			return false;
		}

		$this->rows[$itemId]['lease_until'] = $newLeaseUntil;
		$this->rows[$itemId]['updated_at']  = $now;

		if ($updateCursor) {
			$this->rows[$itemId]['cursor_json'] = $cursorJson;
		}

		return true;
	}

	/**
	 * Save resumable cursor progress and release the item back to pending.
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
		if (!isset($this->rows[$itemId])) {
			return false;
		}

		$row = $this->rows[$itemId];

		if ($row['status'] !== QueueItem::STATUS_LEASED || !hash_equals($row['lease_token'], $leaseToken)) {
			return false;
		}

		$this->rows[$itemId]['status']       = QueueItem::STATUS_PENDING;
		$this->rows[$itemId]['lease_until']  = 0;
		$this->rows[$itemId]['lease_token']  = '';
		$this->rows[$itemId]['cursor_json']  = $cursorJson;
		$this->rows[$itemId]['available_at'] = $availableAt;
		$this->rows[$itemId]['attempts']     = max(0, $row['attempts'] - 1);
		$this->rows[$itemId]['updated_at']   = $now;

		return true;
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
		if (!isset($this->rows[$itemId])) {
			return false;
		}

		$row = $this->rows[$itemId];

		if ($row['status'] !== QueueItem::STATUS_LEASED || !hash_equals($row['lease_token'], $leaseToken)) {
			return false;
		}

		$this->rows[$itemId]['status']      = QueueItem::STATUS_COMPLETED;
		$this->rows[$itemId]['lease_until'] = 0;
		$this->rows[$itemId]['lease_token'] = '';
		$this->rows[$itemId]['last_error']  = '';
		$this->rows[$itemId]['updated_at']  = $now;

		return true;
	}

	/**
	 * Record a delivery failure with exponential backoff or dead-letter transition.
	 *
	 * @param int $itemId Queue item ID.
	 * @param string $leaseToken Active lease token.
	 * @param string $error Truncated error summary.
	 * @param int $baseBackoffSeconds Base backoff in seconds.
	 * @param int $now Current Unix timestamp.
	 * @return string
	 */
	public function fail(
		int $itemId,
		string $leaseToken,
		string $error,
		int $baseBackoffSeconds,
		int $now,
	): string {
		if (!isset($this->rows[$itemId])) {
			return '';
		}

		$row = $this->rows[$itemId];

		if ($row['status'] !== QueueItem::STATUS_LEASED || !hash_equals($row['lease_token'], $leaseToken)) {
			return '';
		}

		$truncated = substr($error, 0, 512);

		if ($row['attempts'] >= $row['max_attempts']) {
			$this->rows[$itemId]['status']      = QueueItem::STATUS_DEAD;
			$this->rows[$itemId]['lease_until'] = 0;
			$this->rows[$itemId]['lease_token'] = '';
			$this->rows[$itemId]['last_error']  = $truncated;
			$this->rows[$itemId]['updated_at']  = $now;

			return QueueItem::STATUS_DEAD;
		}

		$exponent = max(0, min(16, $row['attempts'] - 1));
		$delay    = min(self::MAX_BACKOFF_SECONDS, max(1, $baseBackoffSeconds) * (1 << $exponent));

		$this->rows[$itemId]['status']       = QueueItem::STATUS_PENDING;
		$this->rows[$itemId]['lease_until']  = 0;
		$this->rows[$itemId]['lease_token']  = '';
		$this->rows[$itemId]['available_at'] = $now + $delay;
		$this->rows[$itemId]['last_error']   = $truncated;
		$this->rows[$itemId]['updated_at']   = $now;

		return QueueItem::STATUS_PENDING;
	}

	/**
	 * Reclaim leased items whose lease_until <= $now.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $now Current Unix timestamp.
	 * @return int
	 */
	public function reclaimExpiredLeases(?string $queueName, int $now): int {
		$reclaimed = 0;

		foreach ($this->rows as $id => $row) {
			if ($queueName !== null && $row['queue_name'] !== $queueName) {
				continue;
			}

			if ($row['status'] !== QueueItem::STATUS_LEASED || $row['lease_until'] <= 0 || $row['lease_until'] > $now) {
				continue;
			}

			$this->rows[$id]['lease_until'] = 0;
			$this->rows[$id]['lease_token'] = '';
			$this->rows[$id]['updated_at']  = $now;

			if ($row['attempts'] >= $row['max_attempts']) {
				$this->rows[$id]['status']     = QueueItem::STATUS_DEAD;
				$this->rows[$id]['last_error'] = 'Lease expired after reaching max attempts';
			} else {
				$this->rows[$id]['status']       = QueueItem::STATUS_PENDING;
				$this->rows[$id]['available_at'] = $now;
			}

			$reclaimed++;
		}

		return $reclaimed;
	}

	/**
	 * List dead-letter items ordered by updated_at descending.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $limit Maximum items to return.
	 * @return list<QueueItem>
	 */
	public function deadLetters(?string $queueName, int $limit): array {
		$matched = [];

		foreach ($this->rows as $row) {
			if ($row['status'] !== QueueItem::STATUS_DEAD) {
				continue;
			}

			if ($queueName !== null && $row['queue_name'] !== $queueName) {
				continue;
			}

			$matched[] = $row;
		}

		usort($matched, static fn (array $a, array $b): int => [$b['updated_at'], $b['id']] <=> [$a['updated_at'], $a['id']]);
		$sliced = array_slice($matched, 0, max(1, min(500, $limit)));

		return array_map(fn (array $row): QueueItem => $this->hydrate($row), $sliced);
	}

	/**
	 * Reset a dead-letter item back to pending.
	 *
	 * @param int $itemId Dead-letter item ID.
	 * @param int $now Current Unix timestamp.
	 * @return bool
	 */
	public function retryDeadLetter(int $itemId, int $now): bool {
		if (!isset($this->rows[$itemId]) || $this->rows[$itemId]['status'] !== QueueItem::STATUS_DEAD) {
			return false;
		}

		$this->rows[$itemId]['status']       = QueueItem::STATUS_PENDING;
		$this->rows[$itemId]['attempts']     = 0;
		$this->rows[$itemId]['available_at'] = $now;
		$this->rows[$itemId]['lease_until']  = 0;
		$this->rows[$itemId]['lease_token']  = '';
		$this->rows[$itemId]['last_error']   = '';
		$this->rows[$itemId]['updated_at']   = $now;

		return true;
	}

	/**
	 * Purge completed rows updated on or before $cutoffTimestamp.
	 *
	 * @param int $cutoffTimestamp Unix timestamp threshold.
	 * @return int
	 */
	public function purgeCompleted(int $cutoffTimestamp): int {
		$removed = 0;

		foreach ($this->rows as $id => $row) {
			if ($row['status'] === QueueItem::STATUS_COMPLETED && $row['updated_at'] <= $cutoffTimestamp) {
				unset($this->rows[$id]);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Purge dead-letter rows, optionally scoped to one queue.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @return int
	 */
	public function purgeDeadLetters(?string $queueName): int {
		$removed = 0;

		foreach ($this->rows as $id => $row) {
			if ($row['status'] !== QueueItem::STATUS_DEAD) {
				continue;
			}

			if ($queueName !== null && $row['queue_name'] !== $queueName) {
				continue;
			}

			unset($this->rows[$id]);
			$removed++;
		}

		return $removed;
	}

	/**
	 * Find a single queue item by ID.
	 *
	 * @param int $itemId Queue item ID.
	 * @return QueueItem|null
	 */
	public function find(int $itemId): ?QueueItem {
		return isset($this->rows[$itemId]) ? $this->hydrate($this->rows[$itemId]) : null;
	}

	/**
	 * Aggregate queue counts by queue name and status.
	 *
	 * @param string|null $queueName Optional queue filter.
	 * @param int $now Current Unix timestamp.
	 * @return array<string, array{pending: int, leased: int, completed: int, dead: int, expiredLeases: int, total: int}>
	 */
	public function status(?string $queueName, int $now): array {
		$summary = [];

		foreach ($this->rows as $row) {
			if ($queueName !== null && $row['queue_name'] !== $queueName) {
				continue;
			}

			$name = $row['queue_name'];

			if (!isset($summary[$name])) {
				$summary[$name] = [
					'pending'       => 0,
					'leased'        => 0,
					'completed'     => 0,
					'dead'          => 0,
					'expiredLeases' => 0,
					'total'         => 0,
				];
			}

			$summary[$name]['total']++;

			if ($row['status'] === QueueItem::STATUS_PENDING) {
				$summary[$name]['pending']++;
			} elseif ($row['status'] === QueueItem::STATUS_LEASED) {
				$summary[$name]['leased']++;

				if ($row['lease_until'] > 0 && $row['lease_until'] <= $now) {
					$summary[$name]['expiredLeases']++;
				}
			} elseif ($row['status'] === QueueItem::STATUS_COMPLETED) {
				$summary[$name]['completed']++;
			} elseif ($row['status'] === QueueItem::STATUS_DEAD) {
				$summary[$name]['dead']++;
			}
		}

		ksort($summary, SORT_STRING);

		return $summary;
	}

	/**
	 * Convert an internal row array into an immutable QueueItem.
	 *
	 * @param array{
	 *   id: int,
	 *   queue_name: string,
	 *   payload_json: string,
	 *   status: string,
	 *   available_at: int,
	 *   lease_until: int,
	 *   lease_token: string,
	 *   attempts: int,
	 *   max_attempts: int,
	 *   cursor_json: string|null,
	 *   idempotency_key: string|null,
	 *   last_error: string,
	 *   created_at: int,
	 *   updated_at: int
	 * } $row Internal row state.
	 * @return QueueItem
	 */
	private function hydrate(array $row): QueueItem {
		return new QueueItem(
			$row['id'],
			$row['queue_name'],
			JsonCodec::decodeArray($row['payload_json']),
			$row['status'],
			$row['available_at'],
			$row['lease_until'],
			$row['lease_token'],
			$row['attempts'],
			$row['max_attempts'],
			$row['cursor_json'] !== null ? JsonCodec::decodeArray($row['cursor_json']) : null,
			$row['idempotency_key'],
			$row['last_error'],
			$row['created_at'],
			$row['updated_at'],
		);
	}
}
