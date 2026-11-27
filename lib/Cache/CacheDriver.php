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
 * Storage contract for object cache, transient, tag invalidation, and lock backends.
 */
interface CacheDriver {
	/**
	 * Return the short identifier of this driver (memory, apcu, mariadb, redis).
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Read a raw JSON envelope string for the given (group, key) pair.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string|null Raw JSON envelope when present and unexpired, or null on miss.
	 */
	public function getRaw(string $group, string $key): ?string;

	/**
	 * Store a raw JSON envelope string with optional TTL and tag metadata.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @param string $payload JSON-encoded envelope string.
	 * @param int $ttlSeconds Expiration in seconds (0 = no expiration).
	 * @param list<string> $tags Validated cache tags associated with this entry.
	 * @return bool True on write success.
	 */
	public function setRaw(string $group, string $key, string $payload, int $ttlSeconds, array $tags = []): bool;

	/**
	 * Delete a single cache entry.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return bool True if an entry was removed, false otherwise.
	 */
	public function delete(string $group, string $key): bool;

	/**
	 * Flush all entries belonging to a specific cache group.
	 *
	 * @param string $group Validated cache group name.
	 * @return int Number of entries removed (or 1 for generation-based group invalidation).
	 */
	public function flushGroup(string $group): int;

	/**
	 * Flush all cache entries managed by this driver under its configured namespace.
	 *
	 * @return int Number of entries removed.
	 */
	public function flushAll(): int;

	/**
	 * Remove expired entries from backend storage.
	 *
	 * @param int|null $now Optional Unix timestamp override for deterministic testing.
	 * @return int Number of expired entries pruned.
	 */
	public function pruneExpired(?int $now = null): int;

	/**
	 * Fetch current monotonic invalidation counters for a set of Drupal-style cache tags.
	 *
	 * @param list<string> $tags Validated cache tag strings.
	 * @return array<string, int> Map of tag name to current invalidation counter (0 if never invalidated).
	 */
	public function getTagChecksums(array $tags): array;

	/**
	 * Atomically increment invalidation counters for a set of cache tags.
	 *
	 * @param list<string> $tags Validated cache tag strings.
	 * @return array<string, int> Map of tag name to its new invalidation counter.
	 */
	public function incrementTags(array $tags): array;

	/**
	 * Attempt to acquire an exclusive single-flight lock for stampede protection.
	 *
	 * @param string $lockKey Unique lock identifier.
	 * @param string $token Caller-owned random token used to verify release ownership.
	 * @param int $ttlSeconds Maximum lock lease duration in seconds.
	 * @return bool True when the lock was acquired by this caller.
	 */
	public function acquireLock(string $lockKey, string $token, int $ttlSeconds): bool;

	/**
	 * Release a previously acquired single-flight lock if still owned by $token.
	 *
	 * @param string $lockKey Unique lock identifier.
	 * @param string $token Caller-owned token from acquireLock().
	 * @return bool True if the lock was released.
	 */
	public function releaseLock(string $lockKey, string $token): bool;

	/**
	 * Return active (unexpired) entry counts grouped by cache_group.
	 *
	 * @return array<string, int>
	 */
	public function groupCounts(): array;

	/**
	 * Return backend health, occupancy, expired row count, byte footprint, and driver details.
	 *
	 * @return array{driver: string, healthy: bool, entries: int, expired: int, bytes: int, details: array<string, mixed>}
	 */
	public function metrics(): array;

	/**
	 * Verify live connectivity and readiness of the underlying storage backend.
	 *
	 * @return bool
	 */
	public function ping(): bool;
}
