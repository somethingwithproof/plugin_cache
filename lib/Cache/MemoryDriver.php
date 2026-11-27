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

namespace Somethingwithproof\Cache\Cache;

/**
 * In-process bounded cache driver implementing the S3-FIFO eviction policy.
 *
 * Based on Yang, Zhang, Qiu, Yue, and Vinayak (ACM SOSP 2023),
 * "FIFO Queues are All You Need for Cache Eviction":
 * - Small FIFO queue (S, 10% of capacity) absorbs newly inserted items and
 *   filters one-hit-wonders during sequential scans.
 * - Main FIFO queue (M, 90% of capacity) retains promoted multi-hit items with
 *   a 2-bit capped frequency counter (0..3) granting second-chance re-insertion.
 * - Ghost FIFO queue (G, sized to M) remembers keys evicted from S without
 *   retaining their payloads so re-requested keys bypass S directly into M.
 */
final class MemoryDriver implements CacheDriver {
	/**
	 * Maximum per-entry frequency counter value (2-bit saturating counter).
	 */
	private const MAX_FREQ = 3;

	/**
	 * Total bounded entry capacity across S and M queues.
	 */
	private readonly int $capacity;

	/**
	 * Target capacity of the small probation queue S (10% of total capacity).
	 */
	private readonly int $smallCapacity;

	/**
	 * Target capacity of the main queue M and ghost queue G (90% of total capacity).
	 */
	private readonly int $mainCapacity;

	/**
	 * Clock callable returning current Unix timestamp in seconds.
	 *
	 * @var callable(): int
	 */
	private readonly mixed $clock;

	/**
	 * Resident cache entries keyed by composite entry ID.
	 *
	 * @var array<string, array{group: string, key: string, payload: string, expiresAt: int, tags: list<string>, freq: int, queue: string}>
	 */
	private array $entries = [];

	/**
	 * Small FIFO queue S preserving insertion order (keyed by composite entry ID).
	 *
	 * @var array<string, true>
	 */
	private array $smallQueue = [];

	/**
	 * Main FIFO queue M preserving insertion/re-insertion order (keyed by composite entry ID).
	 *
	 * @var array<string, true>
	 */
	private array $mainQueue = [];

	/**
	 * Ghost FIFO queue G storing only evicted keys from S (no payloads).
	 *
	 * @var array<string, true>
	 */
	private array $ghostQueue = [];

	/**
	 * Monotonic cache tag invalidation counters.
	 *
	 * @var array<string, int>
	 */
	private array $tagCounters = [];

	/**
	 * Active single-flight locks keyed by lock key.
	 *
	 * @var array<string, array{token: string, expiresAt: int}>
	 */
	private array $locks = [];

	/**
	 * Total S3-FIFO evictions performed since construction or flushAll().
	 */
	private int $evictions = 0;

	/**
	 * Total promotions from small queue S to main queue M.
	 */
	private int $promotions = 0;

	/**
	 * Total ghost queue hits admitting re-seen keys directly into main queue M.
	 */
	private int $ghostHits = 0;

	/**
	 * Construct a bounded S3-FIFO in-memory cache driver.
	 *
	 * @param int $capacity Maximum resident entries (minimum 2; default 1000).
	 * @param (callable(): int)|null $clock Optional clock returning current Unix seconds.
	 */
	public function __construct(int $capacity = 1000, ?callable $clock = null) {
		$this->capacity      = max(2, $capacity);
		$this->smallCapacity = max(1, (int) floor($this->capacity * 0.1));
		$this->mainCapacity  = max(1, $this->capacity - $this->smallCapacity);
		$this->clock         = $clock ?? static fn (): int => time();
	}

	/**
	 * Return the driver identifier.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'memory';
	}

	/**
	 * Fetch a raw JSON envelope and increment its 2-bit S3-FIFO frequency counter on hit.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string|null
	 */
	public function getRaw(string $group, string $key): ?string {
		$id = CacheKeyValidator::entryId($group, $key);

		if (!isset($this->entries[$id])) {
			return null;
		}

		$now   = ($this->clock)();
		$entry = $this->entries[$id];

		if ($entry['expiresAt'] > 0 && $entry['expiresAt'] <= $now) {
			$this->removeResident($id);

			return null;
		}

		$this->entries[$id]['freq'] = min(self::MAX_FREQ, $entry['freq'] + 1);

		return $entry['payload'];
	}

	/**
	 * Insert or update a raw JSON envelope using S3-FIFO admission and eviction rules.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @param string $payload JSON-encoded envelope string.
	 * @param int $ttlSeconds Expiration in seconds (0 = no expiration).
	 * @param list<string> $tags Validated cache tags.
	 * @return bool
	 */
	public function setRaw(string $group, string $key, string $payload, int $ttlSeconds, array $tags = []): bool {
		$id        = CacheKeyValidator::entryId($group, $key);
		$now       = ($this->clock)();
		$expiresAt = $ttlSeconds > 0 ? $now + $ttlSeconds : 0;

		if (isset($this->entries[$id])) {
			$this->entries[$id]['payload']   = $payload;
			$this->entries[$id]['expiresAt'] = $expiresAt;
			$this->entries[$id]['tags']      = $tags;

			return true;
		}

		$inGhost = isset($this->ghostQueue[$id]);

		if ($inGhost) {
			unset($this->ghostQueue[$id]);
			$this->ghostHits++;
			$this->ensureCapacityForInsert(true);
			$this->mainQueue[$id] = true;
			$queue                = 'M';
		} else {
			$this->ensureCapacityForInsert(false);
			$this->smallQueue[$id] = true;
			$queue                 = 'S';
		}

		$this->entries[$id] = [
			'group'     => $group,
			'key'       => $key,
			'payload'   => $payload,
			'expiresAt' => $expiresAt,
			'tags'      => $tags,
			'freq'      => 0,
			'queue'     => $queue,
		];

		return true;
	}

	/**
	 * Delete a single entry from resident queues.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return bool
	 */
	public function delete(string $group, string $key): bool {
		$id = CacheKeyValidator::entryId($group, $key);

		if (!isset($this->entries[$id])) {
			return false;
		}

		$this->removeResident($id);

		return true;
	}

	/**
	 * Remove all entries belonging to the given cache group.
	 *
	 * @param string $group Validated cache group name.
	 * @return int
	 */
	public function flushGroup(string $group): int {
		$removed = 0;

		foreach ($this->entries as $id => $entry) {
			if ($entry['group'] === $group) {
				$this->removeResident($id);
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Remove all resident entries and clear S3-FIFO queues.
	 *
	 * @return int
	 */
	public function flushAll(): int {
		$removed          = count($this->entries);
		$this->entries    = [];
		$this->smallQueue = [];
		$this->mainQueue  = [];
		$this->ghostQueue = [];

		return $removed;
	}

	/**
	 * Prune all expired entries from resident storage.
	 *
	 * @param int|null $now Optional Unix timestamp override.
	 * @return int
	 */
	public function pruneExpired(?int $now = null): int {
		$cutoff  = $now ?? ($this->clock)();
		$removed = 0;

		foreach ($this->entries as $id => $entry) {
			if ($entry['expiresAt'] > 0 && $entry['expiresAt'] <= $cutoff) {
				$this->removeResident($id);
				$removed++;
			}
		}

		foreach ($this->locks as $lockKey => $lock) {
			if ($lock['expiresAt'] <= $cutoff) {
				unset($this->locks[$lockKey]);
			}
		}

		return $removed;
	}

	/**
	 * Return current invalidation counters for the requested cache tags.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function getTagChecksums(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			$result[$tag] = $this->tagCounters[$tag] ?? 0;
		}

		return $result;
	}

	/**
	 * Increment invalidation counters for the given cache tags.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function incrementTags(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			$next                    = ($this->tagCounters[$tag] ?? 0) + 1;
			$this->tagCounters[$tag] = $next;
			$result[$tag]            = $next;
		}

		return $result;
	}

	/**
	 * Acquire an in-process single-flight lock if absent or expired.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller token.
	 * @param int $ttlSeconds Lease duration in seconds.
	 * @return bool
	 */
	public function acquireLock(string $lockKey, string $token, int $ttlSeconds): bool {
		$now = ($this->clock)();

		if (isset($this->locks[$lockKey]) && $this->locks[$lockKey]['expiresAt'] > $now) {
			return false;
		}

		$this->locks[$lockKey] = [
			'token'     => $token,
			'expiresAt' => $now + max(1, $ttlSeconds),
		];

		return true;
	}

	/**
	 * Release an in-process single-flight lock when owned by $token.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller token.
	 * @return bool
	 */
	public function releaseLock(string $lockKey, string $token): bool {
		if (!isset($this->locks[$lockKey])) {
			return false;
		}

		if (!hash_equals($this->locks[$lockKey]['token'], $token)) {
			return false;
		}

		unset($this->locks[$lockKey]);

		return true;
	}

	/**
	 * Return active (unexpired) entry counts keyed by cache group.
	 *
	 * @return array<string, int>
	 */
	public function groupCounts(): array {
		$now    = ($this->clock)();
		$counts = [];

		foreach ($this->entries as $entry) {
			if ($entry['expiresAt'] > 0 && $entry['expiresAt'] <= $now) {
				continue;
			}

			$counts[$entry['group']] = ($counts[$entry['group']] ?? 0) + 1;
		}

		ksort($counts, SORT_STRING);

		return $counts;
	}

	/**
	 * Return driver metrics including S3-FIFO queue occupancy, evictions, promotions, and ghost hits.
	 *
	 * @return array{driver: string, healthy: bool, entries: int, expired: int, bytes: int, details: array<string, mixed>}
	 */
	public function metrics(): array {
		$now     = ($this->clock)();
		$active  = 0;
		$expired = 0;
		$bytes   = 0;

		foreach ($this->entries as $entry) {
			$bytes += strlen($entry['payload']);

			if ($entry['expiresAt'] > 0 && $entry['expiresAt'] <= $now) {
				$expired++;
			} else {
				$active++;
			}
		}

		return [
			'driver'  => $this->name(),
			'healthy' => true,
			'entries' => $active,
			'expired' => $expired,
			'bytes'   => $bytes,
			'details' => [
				'policy'        => 's3-fifo',
				'capacity'      => $this->capacity,
				'smallCapacity' => $this->smallCapacity,
				'mainCapacity'  => $this->mainCapacity,
				'smallSize'     => count($this->smallQueue),
				'mainSize'      => count($this->mainQueue),
				'ghostSize'     => count($this->ghostQueue),
				'evictions'     => $this->evictions,
				'promotions'    => $this->promotions,
				'ghostHits'     => $this->ghostHits,
			],
		];
	}

	/**
	 * In-process memory driver is always ready.
	 *
	 * @return bool
	 */
	public function ping(): bool {
		return true;
	}

	/**
	 * Ensure space is available for a new admission according to S3-FIFO queue budgets.
	 *
	 * @param bool $admitToMain True when the key hit ghost queue G and enters M directly.
	 * @return void
	 */
	private function ensureCapacityForInsert(bool $admitToMain): void {
		if (count($this->entries) < $this->capacity) {
			if (!$admitToMain && count($this->smallQueue) >= $this->smallCapacity) {
				$this->evictSmall();
			} elseif ($admitToMain && count($this->mainQueue) >= $this->mainCapacity) {
				$this->evictMain();
			}

			return;
		}

		if ($admitToMain) {
			if (count($this->mainQueue) > 0) {
				$this->evictMain();
			} else {
				$this->evictSmall();
			}

			return;
		}

		if (count($this->smallQueue) >= $this->smallCapacity) {
			$this->evictSmall();
		} else {
			$this->evictMain();
		}

		while (count($this->entries) >= $this->capacity) {
			if (count($this->smallQueue) > 0) {
				$this->evictSmall();
			} elseif (count($this->mainQueue) > 0) {
				$this->evictMain();
			} else {
				break;
			}
		}
	}

	/**
	 * Evict from small FIFO queue S, promoting hit items (freq > 0) to main queue M
	 * and recording evicted zero-frequency keys in ghost queue G.
	 *
	 * @return void
	 */
	private function evictSmall(): void {
		while ($this->smallQueue !== []) {
			$id = array_key_first($this->smallQueue);
			unset($this->smallQueue[$id]);

			if (!isset($this->entries[$id])) {
				continue;
			}

			if ($this->entries[$id]['freq'] > 0) {
				$promoted = $this->entries[$id];

				while (count($this->mainQueue) >= $this->mainCapacity) {
					$this->evictMain();
				}

				$promoted['freq']      = 0;
				$promoted['queue']     = 'M';
				$this->entries[$id]    = $promoted;
				$this->mainQueue[$id]  = true;
				$this->promotions++;

				continue;
			}

			unset($this->entries[$id]);
			$this->evictions++;
			$this->insertGhost($id);

			return;
		}
	}

	/**
	 * Evict from main FIFO queue M using second-chance frequency decrementing.
	 *
	 * @return void
	 */
	private function evictMain(): void {
		while ($this->mainQueue !== []) {
			$id = array_key_first($this->mainQueue);
			unset($this->mainQueue[$id]);

			if (!isset($this->entries[$id])) {
				continue;
			}

			if ($this->entries[$id]['freq'] > 0) {
				$this->entries[$id]['freq']--;
				$this->mainQueue[$id] = true;

				continue;
			}

			unset($this->entries[$id]);
			$this->evictions++;

			return;
		}
	}

	/**
	 * Insert an evicted key ID into bounded ghost FIFO queue G.
	 *
	 * @param string $id Composite entry ID.
	 * @return void
	 */
	private function insertGhost(string $id): void {
		unset($this->ghostQueue[$id]);

		while (count($this->ghostQueue) >= $this->mainCapacity) {
			$oldest = array_key_first($this->ghostQueue);

			if ($oldest === null) {
				break;
			}

			unset($this->ghostQueue[$oldest]);
		}

		$this->ghostQueue[$id] = true;
	}

	/**
	 * Remove a resident entry from both the entry map and its active FIFO queue.
	 *
	 * @param string $id Composite entry ID.
	 * @return void
	 */
	private function removeResident(string $id): void {
		unset($this->entries[$id], $this->smallQueue[$id], $this->mainQueue[$id]);
	}
}
