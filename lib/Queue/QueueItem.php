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
 * Immutable representation of a resumable queue item from plugin_cache_queue.
 */
final class QueueItem {
	/**
	 * Item waiting for a worker claim.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Item currently leased by an active worker.
	 */
	public const STATUS_LEASED = 'leased';

	/**
	 * Item finished successfully.
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Item exhausted maxAttempts and moved to the dead-letter queue.
	 */
	public const STATUS_DEAD = 'dead';

	/**
	 * Construct an immutable QueueItem snapshot.
	 *
	 * @param int $id Auto-increment item identifier.
	 * @param string $queueName Logical queue name.
	 * @param array<string, mixed> $payload Decoded JSON job payload.
	 * @param string $status Lifecycle status (pending, leased, completed, dead).
	 * @param int $availableAt Earliest Unix timestamp when the item may be claimed.
	 * @param int $leaseUntil Unix timestamp when the active lease expires (0 if unleased).
	 * @param string $leaseToken Hex lease ownership token for fencing updates.
	 * @param int $attempts Number of delivery attempts so far.
	 * @param int $maxAttempts Maximum delivery attempts before dead-lettering.
	 * @param array<string, mixed>|null $cursor Optional resumable batch cursor state.
	 * @param string|null $idempotencyKey Optional per-queue deduplication key.
	 * @param string $lastError Last recorded error summary (truncated, zero secrets).
	 * @param int $createdAt Unix timestamp when the item was enqueued.
	 * @param int $updatedAt Unix timestamp of the last state transition.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $queueName,
		public readonly array $payload,
		public readonly string $status,
		public readonly int $availableAt,
		public readonly int $leaseUntil,
		public readonly string $leaseToken,
		public readonly int $attempts,
		public readonly int $maxAttempts,
		public readonly ?array $cursor,
		public readonly ?string $idempotencyKey,
		public readonly string $lastError,
		public readonly int $createdAt,
		public readonly int $updatedAt,
	) {
	}

	/**
	 * Convert item metadata to an array safe for UI, CLI, and JSON responses.
	 *
	 * @param bool $includePayload Whether to include payload and cursor fields.
	 * @return array<string, mixed>
	 */
	public function toArray(bool $includePayload = false): array {
		$data = [
			'id'             => $this->id,
			'queueName'      => $this->queueName,
			'status'         => $this->status,
			'availableAt'    => $this->availableAt,
			'leaseUntil'     => $this->leaseUntil,
			'attempts'       => $this->attempts,
			'maxAttempts'    => $this->maxAttempts,
			'hasCursor'      => $this->cursor !== null,
			'idempotencyKey' => $this->idempotencyKey,
			'lastError'      => $this->lastError,
			'createdAt'      => $this->createdAt,
			'updatedAt'      => $this->updatedAt,
		];

		if ($includePayload) {
			$data['payload'] = $this->payload;
			$data['cursor']  = $this->cursor;
		}

		return $data;
	}
}
