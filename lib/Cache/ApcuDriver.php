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
 * Shared-memory APCu object cache driver with O(1) group and global generation invalidation.
 *
 * Uses generation counters for flushGroup() and flushAll() so flushing this
 * plugin's namespace never evicts unrelated PHP applications sharing APCu.
 */
final class ApcuDriver implements CacheDriver {
	/**
	 * Prefix applied to every APCu key managed by this driver.
	 */
	private readonly string $prefix;

	/**
	 * Underlying APCu primitive store.
	 */
	private readonly ApcuStore $store;

	/**
	 * Construct an APCu cache driver.
	 *
	 * @param string $prefix Namespace prefix applied to all keys.
	 * @param ApcuStore|null $store Optional APCu store adapter (defaults to NativeApcuStore).
	 */
	public function __construct(string $prefix = 'cacti:', ?ApcuStore $store = null) {
		$this->prefix = $prefix;
		$this->store  = $store ?? new NativeApcuStore();
	}

	/**
	 * Return the driver identifier.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'apcu';
	}

	/**
	 * Fetch a raw JSON envelope from APCu.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string|null
	 */
	public function getRaw(string $group, string $key): ?string {
		if (!$this->store->isEnabled()) {
			return null;
		}

		$success = false;
		$raw     = $this->store->fetch($this->entryKey($group, $key), $success);

		return $success && is_string($raw) ? $raw : null;
	}

	/**
	 * Store a raw JSON envelope in APCu and record the group in the namespace registry.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @param string $payload JSON-encoded envelope string.
	 * @param int $ttlSeconds Expiration in seconds (0 = no expiration).
	 * @param list<string> $tags Validated cache tags.
	 * @return bool
	 */
	public function setRaw(string $group, string $key, string $payload, int $ttlSeconds, array $tags = []): bool {
		if (!$this->store->isEnabled()) {
			return false;
		}

		$this->trackGroup($group);

		return $this->store->store($this->entryKey($group, $key), $payload, $ttlSeconds);
	}

	/**
	 * Delete a single entry from APCu.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return bool
	 */
	public function delete(string $group, string $key): bool {
		if (!$this->store->isEnabled()) {
			return false;
		}

		return $this->store->delete($this->entryKey($group, $key));
	}

	/**
	 * Invalidate all entries in a cache group in O(1) by advancing the group generation counter.
	 *
	 * @param string $group Validated cache group name.
	 * @return int Returns 1 when the group generation counter was incremented, 0 if unavailable.
	 */
	public function flushGroup(string $group): int {
		if (!$this->store->isEnabled()) {
			return 0;
		}

		$genKey = $this->groupGenKey($group);

		if ($this->store->inc($genKey, 1) === false) {
			$this->store->store($genKey, 1, 0);
		}

		return 1;
	}

	/**
	 * Invalidate all entries in this plugin's APCu namespace in O(1) via global generation increment.
	 *
	 * @return int Returns 1 when the global namespace generation was incremented, 0 if unavailable.
	 */
	public function flushAll(): int {
		if (!$this->store->isEnabled()) {
			return 0;
		}

		$genKey = $this->globalGenKey();

		if ($this->store->inc($genKey, 1) === false) {
			$this->store->store($genKey, 1, 0);
		}

		$this->store->delete($this->groupRegistryKey());

		return 1;
	}

	/**
	 * APCu expires keys natively on read and allocation; no manual row prune is needed.
	 *
	 * @param int|null $now Unused for APCu.
	 * @return int Always 0.
	 */
	public function pruneExpired(?int $now = null): int {
		return 0;
	}

	/**
	 * Fetch current monotonic invalidation counters for cache tags.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function getTagChecksums(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			$success      = false;
			$val          = $this->store->fetch($this->tagKey($tag), $success);
			$result[$tag] = $success && is_int($val) && $val >= 0 ? $val : 0;
		}

		return $result;
	}

	/**
	 * Atomically increment invalidation counters for cache tags.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function incrementTags(array $tags): array {
		$result = [];

		if (!$this->store->isEnabled()) {
			foreach ($tags as $tag) {
				$result[$tag] = 0;
			}

			return $result;
		}

		foreach ($tags as $tag) {
			$key  = $this->tagKey($tag);
			$next = $this->store->inc($key, 1);

			if ($next === false) {
				$this->store->store($key, 1, 0);
				$next = 1;
			}

			$result[$tag] = $next;
		}

		return $result;
	}

	/**
	 * Acquire a single-flight lock atomically via apcu_add().
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller-owned token.
	 * @param int $ttlSeconds Lease duration in seconds.
	 * @return bool
	 */
	public function acquireLock(string $lockKey, string $token, int $ttlSeconds): bool {
		if (!$this->store->isEnabled()) {
			return false;
		}

		return $this->store->add($this->lockKey($lockKey), $token, max(1, $ttlSeconds));
	}

	/**
	 * Release a single-flight lock if still held by $token.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller-owned token.
	 * @return bool
	 */
	public function releaseLock(string $lockKey, string $token): bool {
		if (!$this->store->isEnabled()) {
			return false;
		}

		$key     = $this->lockKey($lockKey);
		$success = false;
		$current = $this->store->fetch($key, $success);

		if (!$success || !is_string($current) || !hash_equals($current, $token)) {
			return false;
		}

		return $this->store->delete($key);
	}

	/**
	 * Return known cache groups tracked in the APCu namespace registry.
	 *
	 * @return array<string, int>
	 */
	public function groupCounts(): array {
		if (!$this->store->isEnabled()) {
			return [];
		}

		$success = false;
		$raw     = $this->store->fetch($this->groupRegistryKey(), $success);

		if (!$success || !is_string($raw)) {
			return [];
		}

		try {
			$decoded = JsonCodec::decodeArray($raw);
		} catch (CacheException) {
			return [];
		}

		$counts = [];

		foreach ($decoded as $group => $seen) {
			if (is_int($seen) && $seen >= 0) {
				$counts[$group] = $seen;
			}
		}

		ksort($counts, SORT_STRING);

		return $counts;
	}

	/**
	 * Return APCu availability and pool usage metrics.
	 *
	 * @return array{driver: string, healthy: bool, entries: int, expired: int, bytes: int, details: array<string, mixed>}
	 */
	public function metrics(): array {
		$healthy = $this->ping();
		$info    = $healthy ? $this->store->info() : [];
		$entries = isset($info['num_entries']) && is_int($info['num_entries']) ? $info['num_entries'] : 0;
		$bytes   = isset($info['mem_size']) && is_int($info['mem_size']) ? $info['mem_size'] : 0;

		return [
			'driver'  => $this->name(),
			'healthy' => $healthy,
			'entries' => $entries,
			'expired' => 0,
			'bytes'   => $bytes,
			'details' => [
				'prefix'           => $this->prefix,
				'globalGeneration' => $this->globalGeneration(),
				'trackedGroups'    => count($this->groupCounts()),
			],
		];
	}

	/**
	 * Verify APCu is enabled and writable.
	 *
	 * @return bool
	 */
	public function ping(): bool {
		if (!$this->store->isEnabled()) {
			return false;
		}

		$probeKey = $this->prefix . 'probe:' . getmypid();

		if (!$this->store->store($probeKey, '1', 5)) {
			return false;
		}

		$success = false;
		$val     = $this->store->fetch($probeKey, $success);
		$this->store->delete($probeKey);

		return $success && $val === '1';
	}

	/**
	 * Build the generation-scoped APCu storage key for an entry.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string
	 */
	private function entryKey(string $group, string $key): string {
		$globalGen = $this->globalGeneration();
		$groupGen  = $this->groupGeneration($group);

		return sprintf('%se:%d:%s:%d:%s', $this->prefix, $globalGen, $group, $groupGen, $key);
	}

	/**
	 * Return the current global namespace generation counter.
	 *
	 * @return int
	 */
	private function globalGeneration(): int {
		$success = false;
		$gen     = $this->store->fetch($this->globalGenKey(), $success);

		return $success && is_int($gen) && $gen >= 0 ? $gen : 0;
	}

	/**
	 * Return the current generation counter for a cache group.
	 *
	 * @param string $group Validated cache group name.
	 * @return int
	 */
	private function groupGeneration(string $group): int {
		$success = false;
		$gen     = $this->store->fetch($this->groupGenKey($group), $success);

		return $success && is_int($gen) && $gen >= 0 ? $gen : 0;
	}

	/**
	 * Record a cache group name in the lightweight APCu group registry.
	 *
	 * @param string $group Validated cache group name.
	 * @return void
	 */
	private function trackGroup(string $group): void {
		$counts         = $this->groupCounts();
		$counts[$group] = ($counts[$group] ?? 0) + 1;

		try {
			$this->store->store($this->groupRegistryKey(), JsonCodec::encode($counts, 65536), 0);
		} catch (CacheException) {
			// Best-effort telemetry registry; never fail cache writes.
		}
	}

	/**
	 * Return the APCu key for the global generation counter.
	 *
	 * @return string
	 */
	private function globalGenKey(): string {
		return $this->prefix . 'gen:global';
	}

	/**
	 * Return the APCu key for a group generation counter.
	 *
	 * @param string $group Validated cache group name.
	 * @return string
	 */
	private function groupGenKey(string $group): string {
		return $this->prefix . 'gen:group:' . $group;
	}

	/**
	 * Return the APCu key for the group registry.
	 *
	 * @return string
	 */
	private function groupRegistryKey(): string {
		return $this->prefix . 'meta:groups:' . $this->globalGeneration();
	}

	/**
	 * Return the APCu key for a cache tag counter.
	 *
	 * @param string $tag Validated cache tag.
	 * @return string
	 */
	private function tagKey(string $tag): string {
		return $this->prefix . 'tag:' . $tag;
	}

	/**
	 * Return the APCu key for a single-flight lock.
	 *
	 * @param string $lockKey Lock identifier.
	 * @return string
	 */
	private function lockKey(string $lockKey): string {
		return $this->prefix . 'lock:' . $lockKey;
	}
}
