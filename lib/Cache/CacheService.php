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
 * WordPress-style Object Cache and Transients service with Drupal Cache Tags,
 * single-flight stampede locking, and XFetch probabilistic early expiration.
 *
 * XFetch algorithm reference:
 * Vattani, A., Chierichetti, F., & Lowenstein, K. (2015). "Optimal Probabilistic
 * Cache Stampede Prevention." Proceedings of the VLDB Endowment, 8(8), 886-897.
 */
final class CacheService {
	/**
	 * Active storage driver.
	 */
	private readonly CacheDriver $driver;

	/**
	 * Default entry TTL in seconds when callers omit an explicit TTL.
	 */
	private readonly int $defaultTtl;

	/**
	 * Maximum permitted JSON payload size in bytes.
	 */
	private readonly int $maxPayloadBytes;

	/**
	 * Single-flight lock lease duration in seconds.
	 */
	private readonly int $lockTtl;

	/**
	 * XFetch early-recompute scaling factor (default 1.0; 0.0 disables early recompute).
	 */
	private readonly float $xfetchBeta;

	/**
	 * Microsecond spin delay while waiting for another worker holding a single-flight lock.
	 */
	private readonly int $lockWaitMicros;

	/**
	 * Maximum retry attempts while waiting for a single-flight lock holder.
	 */
	private readonly int $lockWaitRetries;

	/**
	 * High-resolution clock callable returning current Unix time in float seconds.
	 *
	 * @var callable(): float
	 */
	private readonly mixed $microClock;

	/**
	 * Uniform random generator returning float in (0.0, 1.0] for XFetch decisions.
	 *
	 * @var callable(): float
	 */
	private readonly mixed $randomFloat;

	/**
	 * Count of successful cache hits in this service instance.
	 */
	private int $hits = 0;

	/**
	 * Count of cache misses in this service instance.
	 */
	private int $misses = 0;

	/**
	 * Count of cache write operations in this service instance.
	 */
	private int $writes = 0;

	/**
	 * Count of stale or tag-invalidated entries evicted on read or delete.
	 */
	private int $evictions = 0;

	/**
	 * Count of cache tags invalidated through invalidateTags().
	 */
	private int $tagInvalidations = 0;

	/**
	 * Count of probabilistic early recomputations triggered by XFetch.
	 */
	private int $earlyRecomputes = 0;

	/**
	 * Count of single-flight locks acquired during remember().
	 */
	private int $lockAcquisitions = 0;

	/**
	 * Count of single-flight lock contentions observed during remember().
	 */
	private int $lockContentions = 0;

	/**
	 * Construct a CacheService around a CacheDriver.
	 *
	 * @param CacheDriver $driver Backend storage driver.
	 * @param int $defaultTtl Default TTL in seconds when callers pass null.
	 * @param int $maxPayloadBytes Maximum JSON payload size in bytes.
	 * @param int $lockTtl Single-flight lock TTL in seconds.
	 * @param float $xfetchBeta XFetch beta scaling factor (0.0 disables early recompute).
	 * @param int $lockWaitMicros Spin sleep duration in microseconds on lock contention.
	 * @param int $lockWaitRetries Maximum lock contention spin iterations.
	 * @param (callable(): float)|null $microClock Optional float clock for deterministic tests.
	 * @param (callable(): float)|null $randomFloat Optional uniform RNG returning (0.0, 1.0].
	 */
	public function __construct(
		CacheDriver $driver,
		int $defaultTtl = 300,
		int $maxPayloadBytes = 1048576,
		int $lockTtl = 15,
		float $xfetchBeta = 1.0,
		int $lockWaitMicros = 25000,
		int $lockWaitRetries = 5,
		?callable $microClock = null,
		?callable $randomFloat = null,
	) {
		$this->driver          = $driver;
		$this->defaultTtl      = max(1, $defaultTtl);
		$this->maxPayloadBytes = max(1024, $maxPayloadBytes);
		$this->lockTtl         = max(1, $lockTtl);
		$this->xfetchBeta      = max(0.0, $xfetchBeta);
		$this->lockWaitMicros  = max(0, $lockWaitMicros);
		$this->lockWaitRetries = max(0, $lockWaitRetries);
		$this->microClock      = $microClock ?? static fn (): float => microtime(true);
		$this->randomFloat     = $randomFloat ?? static fn (): float => random_int(1, 1000000) / 1000000.0;
	}

	/**
	 * Return the active backend CacheDriver instance.
	 *
	 * @return CacheDriver
	 */
	public function driver(): CacheDriver {
		return $this->driver;
	}

	/**
	 * Retrieve a cached value or return $default on miss, expiry, or tag invalidation.
	 *
	 * @param string $key Validated cache key.
	 * @param string $group Validated cache group name.
	 * @param mixed $default Fallback value returned on cache miss.
	 * @return mixed
	 * @throws CacheException When key or group fails validation.
	 */
	public function get(string $key, string $group = 'default', mixed $default = null): mixed {
		$envelope = $this->readEnvelope($key, $group);

		if ($envelope === null) {
			$this->misses++;

			return $default;
		}

		$this->hits++;

		return $envelope['v'];
	}

	/**
	 * Determine whether a valid, unexpired, tag-fresh entry exists.
	 *
	 * @param string $key Validated cache key.
	 * @param string $group Validated cache group name.
	 * @return bool
	 * @throws CacheException When key or group fails validation.
	 */
	public function has(string $key, string $group = 'default'): bool {
		return $this->readEnvelope($key, $group) !== null;
	}

	/**
	 * Store a JSON-serializable value with optional TTL and Drupal-style cache tags.
	 *
	 * @param string $key Validated cache key.
	 * @param mixed $value JSON-encodable value (never PHP-serialized).
	 * @param int|null $ttl TTL in seconds (null = defaultTtl, 0 = no expiration).
	 * @param string $group Validated cache group name.
	 * @param list<string> $tags Optional Drupal-style cache tags (e.g. ['device:42']).
	 * @return bool True when written successfully.
	 * @throws CacheException When key, group, tags, TTL, or payload fail validation.
	 */
	public function set(
		string $key,
		mixed $value,
		?int $ttl = null,
		string $group = 'default',
		array $tags = [],
	): bool {
		return $this->writeEnvelope($key, $value, $ttl, $group, $tags, 0.0);
	}

	/**
	 * Delete a single entry from the cache backend.
	 *
	 * @param string $key Validated cache key.
	 * @param string $group Validated cache group name.
	 * @return bool
	 * @throws CacheException When key or group fails validation.
	 */
	public function delete(string $key, string $group = 'default'): bool {
		$validKey   = CacheKeyValidator::validateKey($key);
		$validGroup = CacheKeyValidator::validateGroup($group);
		$deleted    = $this->driver->delete($validGroup, $validKey);

		if ($deleted) {
			$this->evictions++;
		}

		return $deleted;
	}

	/**
	 * Stampede-safe read-through cache helper combining single-flight locking
	 * with XFetch probabilistic early expiration (Vattani et al., VLDB 2015).
	 *
	 * When a cached entry is still valid, XFetch evaluates:
	 *   now - delta * beta * ln(rand) >= expiresAt
	 * where delta is the recorded recomputation duration in seconds, beta is the
	 * configurable aggressiveness multiplier, and rand is uniform in (0, 1]. If
	 * triggered, this caller attempts to acquire the single-flight lock and
	 * refresh the entry early; if another worker already holds the lock, this
	 * caller immediately returns the still-fresh cached value with zero blocking.
	 *
	 * @param string $key Validated cache key.
	 * @param int|null $ttl TTL in seconds (null = defaultTtl, 0 = no expiration).
	 * @param callable(): mixed $callback Producer invoked on miss or XFetch early refresh.
	 * @param string $group Validated cache group name.
	 * @param list<string> $tags Optional Drupal-style cache tags.
	 * @param float|null $beta Optional per-call XFetch beta override (null uses configured beta).
	 * @return mixed Cached or newly computed value.
	 * @throws CacheException When key, group, tags, TTL, or computed payload fail validation.
	 */
	public function remember(
		string $key,
		?int $ttl,
		callable $callback,
		string $group = 'default',
		array $tags = [],
		?float $beta = null,
	): mixed {
		$validKey   = CacheKeyValidator::validateKey($key);
		$validGroup = CacheKeyValidator::validateGroup($group);
		$validTags  = CacheKeyValidator::validateTags($tags);
		$validTtl   = CacheKeyValidator::validateTtl($ttl ?? $this->defaultTtl);
		$activeBeta = $beta !== null ? max(0.0, $beta) : $this->xfetchBeta;

		$envelope = $this->readEnvelope($validKey, $validGroup);

		if ($envelope !== null) {
			$now = ($this->microClock)();

			if ($this->shouldEarlyRecompute($envelope, $now, $activeBeta)) {
				$lockKey = CacheKeyValidator::entryId($validGroup, $validKey);
				$token   = bin2hex(random_bytes(16));

				if ($this->driver->acquireLock($lockKey, $token, $this->lockTtl)) {
					$this->lockAcquisitions++;
					$this->earlyRecomputes++;

					try {
						return $this->computeAndStore($validKey, $validGroup, $validTtl, $validTags, $callback);
					} finally {
						$this->driver->releaseLock($lockKey, $token);
					}
				}

				$this->lockContentions++;
			}

			$this->hits++;

			return $envelope['v'];
		}

		$this->misses++;

		$lockKey = CacheKeyValidator::entryId($validGroup, $validKey);
		$token   = bin2hex(random_bytes(16));

		if ($this->driver->acquireLock($lockKey, $token, $this->lockTtl)) {
			$this->lockAcquisitions++;

			try {
				$recheck = $this->readEnvelope($validKey, $validGroup);

				if ($recheck !== null) {
					return $recheck['v'];
				}

				return $this->computeAndStore($validKey, $validGroup, $validTtl, $validTags, $callback);
			} finally {
				$this->driver->releaseLock($lockKey, $token);
			}
		}

		$this->lockContentions++;

		for ($attempt = 0; $attempt < $this->lockWaitRetries; $attempt++) {
			if ($this->lockWaitMicros > 0) {
				usleep($this->lockWaitMicros);
			}

			$waited = $this->readEnvelope($validKey, $validGroup);

			if ($waited !== null) {
				$this->hits++;

				return $waited['v'];
			}
		}

		return $this->computeAndStore($validKey, $validGroup, $validTtl, $validTags, $callback);
	}

	/**
	 * Flush all cache entries belonging to a single validated cache group.
	 *
	 * @param string $group Validated cache group name.
	 * @return int Number of flushed entries (or generation bump count).
	 * @throws CacheException When group name is invalid or reserved.
	 */
	public function flushGroup(string $group): int {
		$validGroup = CacheKeyValidator::validateGroup($group);
		$removed    = $this->driver->flushGroup($validGroup);
		$this->evictions += $removed;

		return $removed;
	}

	/**
	 * Flush all cache entries managed by the active driver.
	 *
	 * @return int Number of flushed entries (or generation bump count).
	 */
	public function flushAll(): int {
		$removed = $this->driver->flushAll();
		$this->evictions += $removed;

		return $removed;
	}

	/**
	 * Prune expired entries from backend storage.
	 *
	 * @return int Number of expired entries removed.
	 */
	public function pruneExpired(): int {
		$removed = $this->driver->pruneExpired((int) floor(($this->microClock)()));
		$this->evictions += $removed;

		return $removed;
	}

	/**
	 * Invalidate all cache entries associated with one or more Drupal-style cache tags.
	 *
	 * @param list<string> $tags Tag strings such as ['device:42', 'plugin:redfish'].
	 * @return int Number of distinct tags invalidated.
	 * @throws CacheException When any tag fails validation.
	 */
	public function invalidateTags(array $tags): int {
		$validTags = CacheKeyValidator::validateTags($tags);

		if ($validTags === []) {
			return 0;
		}

		$this->driver->incrementTags($validTags);
		$count = count($validTags);
		$this->tagInvalidations += $count;

		return $count;
	}

	/**
	 * Return active entry counts grouped by cache group.
	 *
	 * @return array<string, int>
	 */
	public function groups(): array {
		return $this->driver->groupCounts();
	}

	/**
	 * Return combined service counters and live backend metrics.
	 *
	 * @return array{
	 *   driver: string,
	 *   healthy: bool,
	 *   hits: int,
	 *   misses: int,
	 *   hitRatio: float,
	 *   writes: int,
	 *   evictions: int,
	 *   tagInvalidations: int,
	 *   earlyRecomputes: int,
	 *   lockAcquisitions: int,
	 *   lockContentions: int,
	 *   entries: int,
	 *   expired: int,
	 *   bytes: int,
	 *   groups: array<string, int>,
	 *   details: array<string, mixed>
	 * }
	 */
	public function stats(): array {
		$metrics  = $this->driver->metrics();
		$lookups  = $this->hits + $this->misses;
		$hitRatio = $lookups > 0 ? round($this->hits / $lookups, 4) : 0.0;

		return [
			'driver'           => $metrics['driver'],
			'healthy'          => $metrics['healthy'],
			'hits'             => $this->hits,
			'misses'           => $this->misses,
			'hitRatio'         => $hitRatio,
			'writes'           => $this->writes,
			'evictions'        => $this->evictions,
			'tagInvalidations' => $this->tagInvalidations,
			'earlyRecomputes'  => $this->earlyRecomputes,
			'lockAcquisitions' => $this->lockAcquisitions,
			'lockContentions'  => $this->lockContentions,
			'entries'          => $metrics['entries'],
			'expired'          => $metrics['expired'],
			'bytes'            => $metrics['bytes'],
			'groups'           => $this->driver->groupCounts(),
			'details'          => $metrics['details'],
		];
	}

	/**
	 * Evaluate the XFetch early-expiration inequality on a fresh cache envelope.
	 *
	 * @param array{v: mixed, t: array<string, int>, c: int, e: int, d: float} $envelope Decoded cache envelope.
	 * @param float $now Current Unix timestamp in float seconds.
	 * @param float $beta XFetch beta scaling factor.
	 * @return bool True when probabilistic early recomputation should trigger.
	 */
	private function shouldEarlyRecompute(array $envelope, float $now, float $beta): bool {
		if ($beta <= 0.0 || $envelope['e'] <= 0 || $envelope['d'] <= 0.0) {
			return false;
		}

		$rand = max(1e-12, min(1.0, ($this->randomFloat)()));

		return ($now - ($envelope['d'] * $beta * log($rand))) >= (float) $envelope['e'];
	}

	/**
	 * Invoke the producer callback, measure recomputation delta, and store the result.
	 *
	 * @param string $validKey Validated cache key.
	 * @param string $validGroup Validated cache group name.
	 * @param int $validTtl Validated TTL in seconds.
	 * @param list<string> $validTags Validated cache tags.
	 * @param callable(): mixed $callback Producer callback.
	 * @return mixed Produced value.
	 */
	private function computeAndStore(
		string $validKey,
		string $validGroup,
		int $validTtl,
		array $validTags,
		callable $callback,
	): mixed {
		$started = ($this->microClock)();
		$value   = $callback();
		$delta   = max(0.0, ($this->microClock)() - $started);

		$this->writeEnvelope($validKey, $value, $validTtl, $validGroup, $validTags, $delta);

		return $value;
	}

	/**
	 * Encode and persist a cache envelope with captured tag checksums and compute delta.
	 *
	 * @param string $key Cache key.
	 * @param mixed $value JSON-encodable value.
	 * @param int|null $ttl TTL in seconds (null = defaultTtl, 0 = no expiry).
	 * @param string $group Cache group name.
	 * @param list<string> $tags Cache tags.
	 * @param float $deltaSeconds Measured computation duration in seconds.
	 * @return bool
	 * @throws CacheException When validation or encoding fails.
	 */
	private function writeEnvelope(
		string $key,
		mixed $value,
		?int $ttl,
		string $group,
		array $tags,
		float $deltaSeconds,
	): bool {
		$validKey   = CacheKeyValidator::validateKey($key);
		$validGroup = CacheKeyValidator::validateGroup($group);
		$validTags  = CacheKeyValidator::validateTags($tags);
		$validTtl   = CacheKeyValidator::validateTtl($ttl ?? $this->defaultTtl);

		$now          = (int) floor(($this->microClock)());
		$expiresAt    = $validTtl > 0 ? $now + $validTtl : 0;
		$tagChecksums = $validTags !== [] ? $this->driver->getTagChecksums($validTags) : [];

		$encoded = JsonCodec::encodeEnvelope(
			$value,
			$tagChecksums,
			$now,
			$expiresAt,
			$deltaSeconds,
			$this->maxPayloadBytes
		);

		$ok = $this->driver->setRaw($validGroup, $validKey, $encoded, $validTtl, $validTags);

		if ($ok) {
			$this->writes++;
		}

		return $ok;
	}

	/**
	 * Read, decode, expiry-check, and tag-verify a cache envelope from the driver.
	 *
	 * @param string $key Cache key.
	 * @param string $group Cache group name.
	 * @return array{v: mixed, t: array<string, int>, c: int, e: int, d: float}|null
	 * @throws CacheException When key or group fails validation.
	 */
	private function readEnvelope(string $key, string $group): ?array {
		$validKey   = CacheKeyValidator::validateKey($key);
		$validGroup = CacheKeyValidator::validateGroup($group);
		$raw        = $this->driver->getRaw($validGroup, $validKey);

		if ($raw === null) {
			return null;
		}

		$envelope = JsonCodec::decodeEnvelope($raw);

		if ($envelope === null) {
			$this->driver->delete($validGroup, $validKey);
			$this->evictions++;

			return null;
		}

		$now = (int) floor(($this->microClock)());

		if ($envelope['e'] > 0 && $envelope['e'] <= $now) {
			$this->driver->delete($validGroup, $validKey);
			$this->evictions++;

			return null;
		}

		if ($envelope['t'] !== []) {
			$current = $this->driver->getTagChecksums(array_keys($envelope['t']));

			foreach ($envelope['t'] as $tag => $storedChecksum) {
				if (($current[$tag] ?? 0) !== $storedChecksum) {
					$this->driver->delete($validGroup, $validKey);
					$this->evictions++;

					return null;
				}
			}
		}

		return $envelope;
	}
}
