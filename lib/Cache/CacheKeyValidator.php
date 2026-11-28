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
 * Strict input validator for cache keys, groups, tags, queue names, and TTLs.
 *
 * Enforces safe ASCII character sets and byte bounds to prevent Redis RESP
 * protocol injection, SQL truncation collisions, and unbounded storage abuse.
 */
final class CacheKeyValidator {
	/**
	 * Regex for valid cache keys (1..191 ASCII identifier/path characters).
	 */
	public const KEY_PATTERN = '/^[A-Za-z0-9_.:\/-]{1,191}$/D';

	/**
	 * Regex for valid cache group and queue names (1..64 safe identifier characters).
	 */
	public const GROUP_PATTERN = '/^[A-Za-z0-9_.-]{1,64}$/D';

	/**
	 * Regex for valid Drupal-style cache tags (e.g. device:42, plugin:redfish).
	 */
	public const TAG_PATTERN = '/^[A-Za-z0-9_.:\/-]{1,128}$/D';

	/**
	 * Regex for valid idempotency keys (1..128 safe ASCII characters).
	 */
	public const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9_.:\/-]{1,128}$/D';

	/**
	 * Reserved internal group prefix used for single-flight locks.
	 */
	public const RESERVED_GROUP = '__locks';

	/**
	 * Maximum number of distinct cache tags permitted on a single entry or invalidation call.
	 */
	public const MAX_TAGS = 64;

	/**
	 * Allowed byte set for cache keys, cache tags, and idempotency keys.
	 */
	private const KEY_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:/-';

	/**
	 * Allowed byte set for cache groups and queue names.
	 */
	private const GROUP_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-';

	/**
	 * Validate a cache key and return it unchanged.
	 *
	 * @param string $key Candidate cache key.
	 * @return string Validated cache key.
	 * @throws CacheException When the key is empty, overlong, or contains unsafe characters.
	 */
	public static function validateKey(string $key): string {
		$len = strlen($key);

		if ($len < 1 || $len > 191 || strspn($key, self::KEY_CHARS) !== $len) {
			throw new CacheException('Invalid cache key: must match [A-Za-z0-9_.:/-]{1,191}.');
		}

		return $key;
	}

	/**
	 * Validate a cache group name.
	 *
	 * @param string $group Candidate cache group name.
	 * @param bool $allowReserved Whether the internal __locks group is allowed.
	 * @return string Validated cache group name.
	 * @throws CacheException When the group name is invalid or reserved.
	 */
	public static function validateGroup(string $group, bool $allowReserved = false): string {
		$len = strlen($group);

		if ($len < 1 || $len > 64 || strspn($group, self::GROUP_CHARS) !== $len) {
			throw new CacheException('Invalid cache group: must match [A-Za-z0-9_.-]{1,64}.');
		}

		if (!$allowReserved && $group === self::RESERVED_GROUP) {
			throw new CacheException('Cache group "__locks" is reserved for internal single-flight locking.');
		}

		return $group;
	}

	/**
	 * Validate and deduplicate a list of Drupal-style cache tags.
	 *
	 * @param list<string> $tags Candidate cache tag strings.
	 * @return list<string> Sorted unique validated cache tags.
	 * @throws CacheException When any tag is invalid or the tag count exceeds MAX_TAGS.
	 */
	public static function validateTags(array $tags): array {
		if ($tags === []) {
			return [];
		}

		$unique = [];

		foreach ($tags as $tag) {
			$len = strlen($tag);

			if ($len < 1 || $len > 128 || strspn($tag, self::KEY_CHARS) !== $len) {
				throw new CacheException('Invalid cache tag: must match [A-Za-z0-9_.:/-]{1,128}.');
			}

			$unique[$tag] = true;
		}

		if (count($unique) > self::MAX_TAGS) {
			throw new CacheException(sprintf('Too many cache tags: maximum is %d.', self::MAX_TAGS));
		}

		$result = array_keys($unique);
		sort($result, SORT_STRING);

		return $result;
	}

	/**
	 * Validate a queue name.
	 *
	 * @param string $queueName Candidate queue identifier.
	 * @return string Validated queue name.
	 * @throws CacheException When the queue name is invalid.
	 */
	public static function validateQueueName(string $queueName): string {
		$len = strlen($queueName);

		if ($len < 1 || $len > 64 || strspn($queueName, self::GROUP_CHARS) !== $len) {
			throw new CacheException('Invalid queue name: must match [A-Za-z0-9_.-]{1,64}.');
		}

		return $queueName;
	}

	/**
	 * Validate an optional queue idempotency key.
	 *
	 * @param string|null $key Candidate idempotency key or null.
	 * @return string|null Validated idempotency key or null.
	 * @throws CacheException When a non-null key is invalid.
	 */
	public static function validateIdempotencyKey(?string $key): ?string {
		if ($key === null || $key === '') {
			return null;
		}

		$len = strlen($key);

		if ($len > 128 || strspn($key, self::KEY_CHARS) !== $len) {
			throw new CacheException('Invalid idempotency key: must match [A-Za-z0-9_.:/-]{1,128}.');
		}

		return $key;
	}

	/**
	 * Validate a TTL in seconds (0 means no expiration; negative values are rejected).
	 *
	 * @param int $ttl Candidate TTL in seconds.
	 * @return int Validated non-negative TTL.
	 * @throws CacheException When TTL is negative.
	 */
	public static function validateTtl(int $ttl): int {
		if ($ttl < 0) {
			throw new CacheException('Cache TTL cannot be negative.');
		}

		return $ttl;
	}

	/**
	 * Compute a collision-resistant SHA-256 entry identifier for a (group, key) pair.
	 *
	 * @param string $group Validated cache group.
	 * @param string $key Validated cache key.
	 * @return string 64-character lowercase hex SHA-256 digest.
	 */
	public static function entryId(string $group, string $key): string {
		return hash('sha256', $group . "\0" . $key);
	}
}
