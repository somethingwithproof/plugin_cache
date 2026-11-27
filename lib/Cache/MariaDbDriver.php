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

use Somethingwithproof\Cache\Db\DbAdapter;

/**
 * MariaDB object cache driver backed by plugin_cache_entries and plugin_cache_tags.
 *
 * Uses indexed cache_group and expires_at columns for fast group flushes and
 * expired-row pruning, and atomic ON DUPLICATE KEY UPDATE increments for
 * Drupal-style tag invalidation counters.
 */
final class MariaDbDriver implements CacheDriver {
	/**
	 * Database adapter executing prepared SQL queries.
	 */
	private readonly DbAdapter $db;

	/**
	 * Clock callable returning current Unix timestamp in seconds.
	 *
	 * @var callable(): int
	 */
	private readonly mixed $clock;

	/**
	 * Construct a MariaDB cache driver.
	 *
	 * @param DbAdapter $db Prepared-statement database adapter.
	 * @param (callable(): int)|null $clock Optional clock returning current Unix seconds.
	 */
	public function __construct(DbAdapter $db, ?callable $clock = null) {
		$this->db    = $db;
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Return the driver identifier.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'mariadb';
	}

	/**
	 * Read an unexpired raw JSON envelope from plugin_cache_entries.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string|null
	 */
	public function getRaw(string $group, string $key): ?string {
		$id  = CacheKeyValidator::entryId($group, $key);
		$now = ($this->clock)();
		$row = $this->db->fetchRow(
			'SELECT payload_json, expires_at FROM plugin_cache_entries WHERE cache_id = ?',
			[$id]
		);

		if ($row === null || !is_string($row['payload_json'] ?? null)) {
			return null;
		}

		$expiresAt = is_numeric($row['expires_at'] ?? null) ? (int) $row['expires_at'] : 0;

		if ($expiresAt > 0 && $expiresAt <= $now) {
			$this->db->execute('DELETE FROM plugin_cache_entries WHERE cache_id = ?', [$id]);

			return null;
		}

		return $row['payload_json'];
	}

	/**
	 * Upsert a raw JSON envelope into plugin_cache_entries.
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
		$tagsJson  = JsonCodec::encode($tags, 65536);
		$bytes     = strlen($payload);

		return $this->db->execute(
			'INSERT INTO plugin_cache_entries (cache_id, cache_group, cache_key, payload_json, tags_json, created_at, expires_at, payload_bytes)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			 ON DUPLICATE KEY UPDATE
			   payload_json = VALUES(payload_json),
			   tags_json = VALUES(tags_json),
			   created_at = VALUES(created_at),
			   expires_at = VALUES(expires_at),
			   payload_bytes = VALUES(payload_bytes)',
			[$id, $group, $key, $payload, $tagsJson, $now, $expiresAt, $bytes]
		);
	}

	/**
	 * Delete a single entry from plugin_cache_entries.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return bool
	 */
	public function delete(string $group, string $key): bool {
		$id = CacheKeyValidator::entryId($group, $key);

		if (!$this->db->execute('DELETE FROM plugin_cache_entries WHERE cache_id = ?', [$id])) {
			return false;
		}

		return $this->db->affectedRows() > 0;
	}

	/**
	 * Delete all entries matching the indexed cache_group column.
	 *
	 * @param string $group Validated cache group name.
	 * @return int
	 */
	public function flushGroup(string $group): int {
		$this->db->execute('DELETE FROM plugin_cache_entries WHERE cache_group = ?', [$group]);

		return $this->db->affectedRows();
	}

	/**
	 * Delete all non-lock cache entries from plugin_cache_entries.
	 *
	 * @return int
	 */
	public function flushAll(): int {
		$this->db->execute('DELETE FROM plugin_cache_entries WHERE cache_group <> ?', [CacheKeyValidator::RESERVED_GROUP]);

		return $this->db->affectedRows();
	}

	/**
	 * Prune expired entries using the indexed expires_at column.
	 *
	 * @param int|null $now Optional Unix timestamp override.
	 * @return int
	 */
	public function pruneExpired(?int $now = null): int {
		$cutoff = $now ?? ($this->clock)();
		$this->db->execute('DELETE FROM plugin_cache_entries WHERE expires_at > 0 AND expires_at <= ?', [$cutoff]);

		return $this->db->affectedRows();
	}

	/**
	 * Fetch current monotonic tag invalidation counters from plugin_cache_tags.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function getTagChecksums(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			$result[$tag] = 0;
		}

		if ($tags === []) {
			return $result;
		}

		$placeholders = implode(', ', array_fill(0, count($tags), '?'));
		$rows         = $this->db->fetchAssoc(
			"SELECT tag, invalidations FROM plugin_cache_tags WHERE tag IN ($placeholders)",
			$tags
		);

		foreach ($rows as $row) {
			$tag = $row['tag'] ?? null;
			$cnt = $row['invalidations'] ?? null;

			if (is_string($tag) && is_numeric($cnt)) {
				$result[$tag] = max(0, (int) $cnt);
			}
		}

		return $result;
	}

	/**
	 * Atomically increment tag invalidation counters in plugin_cache_tags.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function incrementTags(array $tags): array {
		if ($tags === []) {
			return [];
		}

		$now = ($this->clock)();

		foreach ($tags as $tag) {
			$this->db->execute(
				'INSERT INTO plugin_cache_tags (tag, invalidations, updated_at)
				 VALUES (?, 1, ?)
				 ON DUPLICATE KEY UPDATE
				   invalidations = invalidations + 1,
				   updated_at = VALUES(updated_at)',
				[$tag, $now]
			);
		}

		return $this->getTagChecksums($tags);
	}

	/**
	 * Acquire a single-flight lock row in the reserved __locks group using INSERT IGNORE.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller-owned random token.
	 * @param int $ttlSeconds Lease duration in seconds.
	 * @return bool
	 */
	public function acquireLock(string $lockKey, string $token, int $ttlSeconds): bool {
		$id        = CacheKeyValidator::entryId(CacheKeyValidator::RESERVED_GROUP, $lockKey);
		$now       = ($this->clock)();
		$expiresAt = $now + max(1, $ttlSeconds);

		$this->db->execute(
			'DELETE FROM plugin_cache_entries WHERE cache_id = ? AND expires_at > 0 AND expires_at <= ?',
			[$id, $now]
		);

		$ok = $this->db->execute(
			'INSERT IGNORE INTO plugin_cache_entries (cache_id, cache_group, cache_key, payload_json, tags_json, created_at, expires_at, payload_bytes)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
			[$id, CacheKeyValidator::RESERVED_GROUP, $lockKey, $token, '[]', $now, $expiresAt, strlen($token)]
		);

		return $ok && $this->db->affectedRows() > 0;
	}

	/**
	 * Release a single-flight lock row only when payload_json matches the caller token.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller-owned token.
	 * @return bool
	 */
	public function releaseLock(string $lockKey, string $token): bool {
		$id = CacheKeyValidator::entryId(CacheKeyValidator::RESERVED_GROUP, $lockKey);

		if (!$this->db->execute(
			'DELETE FROM plugin_cache_entries WHERE cache_id = ? AND cache_group = ? AND payload_json = ?',
			[$id, CacheKeyValidator::RESERVED_GROUP, $token]
		)) {
			return false;
		}

		return $this->db->affectedRows() > 0;
	}

	/**
	 * Return active (unexpired) entry counts grouped by cache_group.
	 *
	 * @return array<string, int>
	 */
	public function groupCounts(): array {
		$now  = ($this->clock)();
		$rows = $this->db->fetchAssoc(
			'SELECT cache_group, COUNT(*) AS cnt
			 FROM plugin_cache_entries
			 WHERE cache_group <> ? AND (expires_at = 0 OR expires_at > ?)
			 GROUP BY cache_group
			 ORDER BY cache_group',
			[CacheKeyValidator::RESERVED_GROUP, $now]
		);

		$counts = [];

		foreach ($rows as $row) {
			$group = $row['cache_group'] ?? null;
			$cnt   = $row['cnt'] ?? null;

			if (is_string($group) && is_numeric($cnt)) {
				$counts[$group] = (int) $cnt;
			}
		}

		return $counts;
	}

	/**
	 * Return MariaDB cache table occupancy, expired-row count, byte footprint, and tag count.
	 *
	 * @return array{driver: string, healthy: bool, entries: int, expired: int, bytes: int, details: array<string, mixed>}
	 */
	public function metrics(): array {
		$now     = ($this->clock)();
		$healthy = $this->ping();

		if (!$healthy) {
			return [
				'driver'  => $this->name(),
				'healthy' => false,
				'entries' => 0,
				'expired' => 0,
				'bytes'   => 0,
				'details' => ['table' => 'plugin_cache_entries'],
			];
		}

		$row = $this->db->fetchRow(
			'SELECT
				SUM(CASE WHEN expires_at = 0 OR expires_at > ? THEN 1 ELSE 0 END) AS active_cnt,
				SUM(CASE WHEN expires_at > 0 AND expires_at <= ? THEN 1 ELSE 0 END) AS expired_cnt,
				COALESCE(SUM(payload_bytes), 0) AS total_bytes
			 FROM plugin_cache_entries
			 WHERE cache_group <> ?',
			[$now, $now, CacheKeyValidator::RESERVED_GROUP]
		);

		$tagCount = $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cache_tags');

		return [
			'driver'  => $this->name(),
			'healthy' => true,
			'entries' => is_numeric($row['active_cnt'] ?? null) ? (int) $row['active_cnt'] : 0,
			'expired' => is_numeric($row['expired_cnt'] ?? null) ? (int) $row['expired_cnt'] : 0,
			'bytes'   => is_numeric($row['total_bytes'] ?? null) ? (int) $row['total_bytes'] : 0,
			'details' => [
				'table'        => 'plugin_cache_entries',
				'trackedTags'  => is_numeric($tagCount) ? (int) $tagCount : 0,
			],
		];
	}

	/**
	 * Verify database connectivity against plugin_cache_entries.
	 *
	 * @return bool
	 */
	public function ping(): bool {
		$val = $this->db->fetchCell('SELECT 1 FROM plugin_cache_entries LIMIT 1');

		if ($val === 1 || $val === '1') {
			return true;
		}

		$count = $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cache_entries');

		return is_numeric($count);
	}
}
