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

namespace Somethingwithproof\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cache\Cache\ApcuDriver;
use Somethingwithproof\Cache\Cache\ApcuStore;
use Somethingwithproof\Cache\Cache\CacheException;
use Somethingwithproof\Cache\Cache\MariaDbDriver;
use Somethingwithproof\Cache\Cache\MemoryDriver;
use Somethingwithproof\Cache\Cache\NativeApcuStore;
use Somethingwithproof\Cache\Cache\RedisDriver;
use Somethingwithproof\Cache\Db\DbAdapter;

final class DriversAndS3FifoTest extends TestCase {
	public function testMemoryDriverS3FifoScanResistancePromotionAndGhostQueue(): void {
		$now    = 1700000000;
		$driver = new MemoryDriver(20, static function () use (&$now): int {
			return $now;
		});

		// Insert 2 hot items and hit them so freq > 0 before scanning.
		$driver->setRaw('hot', 'k1', '{"v":"hot1"}', 0);
		$driver->setRaw('hot', 'k2', '{"v":"hot2"}', 0);
		$this->assertSame('{"v":"hot1"}', $driver->getRaw('hot', 'k1'));
		$this->assertSame('{"v":"hot1"}', $driver->getRaw('hot', 'k1'));
		$this->assertSame('{"v":"hot2"}', $driver->getRaw('hot', 'k2'));

		// Run a sequential one-hit-wonder scan of 80 keys (4x capacity).
		for ($i = 0; $i < 80; $i++) {
			$driver->setRaw('scan', 'onehit_' . $i, '{"v":' . $i . '}', 0);
		}

		// Hot items promoted from S to M must survive the entire scan!
		$this->assertSame('{"v":"hot1"}', $driver->getRaw('hot', 'k1'));
		$this->assertSame('{"v":"hot2"}', $driver->getRaw('hot', 'k2'));

		$metrics = $driver->metrics();
		$this->assertTrue($metrics['healthy']);
		$this->assertSame('s3-fifo', $metrics['details']['policy']);
		$this->assertLessThanOrEqual(20, $metrics['entries']);
		$this->assertGreaterThan(0, $metrics['details']['evictions']);
		$this->assertGreaterThanOrEqual(2, $metrics['details']['promotions']);

		// Re-inserting a recently evicted scan key hits ghost queue G and admits directly into M.
		$driver->setRaw('scan', 'onehit_70', '{"v":"reseen"}', 0);
		$this->assertGreaterThanOrEqual(1, $driver->metrics()['details']['ghostHits']);

		// Verify M second-chance eviction works when filling M completely.
		for ($j = 0; $j < 30; $j++) {
			$driver->setRaw('main_fill', 'm_' . $j, '{"v":' . $j . '}', 0);
			$driver->getRaw('main_fill', 'm_' . $j);
		}

		$this->assertLessThanOrEqual(20, $driver->metrics()['entries']);
		$this->assertTrue($driver->ping());

		// Verify lock expiry and token fencing.
		$this->assertTrue($driver->acquireLock('lk', 'tokA', 5));
		$this->assertFalse($driver->acquireLock('lk', 'tokB', 5));
		$this->assertFalse($driver->releaseLock('lk', 'tokB'));
		$now += 6;
		$this->assertTrue($driver->acquireLock('lk', 'tokB', 5));
		$this->assertTrue($driver->releaseLock('lk', 'tokB'));
		$this->assertFalse($driver->releaseLock('lk', 'tokB'));
	}

	public function testApcuDriverWithInMemoryStoreAndNativeFallback(): void {
		$store  = new InMemoryApcuStore();
		$driver = new ApcuDriver('test:', $store);

		$this->assertSame('apcu', $driver->name());
		$this->assertTrue($driver->ping());
		$this->assertTrue($driver->setRaw('devices', 'd1', '{"v":1}', 60, ['device:1']));
		$this->assertSame('{"v":1}', $driver->getRaw('devices', 'd1'));
		$this->assertSame(['devices' => 1], $driver->groupCounts());

		// Group flush advances generation in O(1) without affecting other groups.
		$driver->setRaw('other', 'o1', '{"v":2}', 60);
		$this->assertSame(1, $driver->flushGroup('devices'));
		$this->assertNull($driver->getRaw('devices', 'd1'));
		$this->assertSame('{"v":2}', $driver->getRaw('other', 'o1'));

		// Global flush advances namespace generation.
		$this->assertSame(1, $driver->flushAll());
		$this->assertNull($driver->getRaw('other', 'o1'));
		$this->assertSame(0, $driver->pruneExpired());

		// Tag counters and locks.
		$this->assertSame(['device:1' => 0], $driver->getTagChecksums(['device:1']));
		$this->assertSame(['device:1' => 1], $driver->incrementTags(['device:1']));
		$this->assertSame(['device:1' => 2], $driver->incrementTags(['device:1']));
		$this->assertTrue($driver->acquireLock('l1', 'tok1', 10));
		$this->assertFalse($driver->acquireLock('l1', 'tok2', 10));
		$this->assertFalse($driver->releaseLock('l1', 'tok2'));
		$this->assertTrue($driver->releaseLock('l1', 'tok1'));
		$this->assertTrue($driver->setRaw('g', 'k', '{"v":1}', 60));
		$this->assertTrue($driver->delete('g', 'k'));

		$metrics = $driver->metrics();
		$this->assertTrue($metrics['healthy']);
		$this->assertSame('apcu', $metrics['driver']);

		// Disabled APCu store degrades gracefully without throwing.
		$store->enabled = false;
		$this->assertFalse($driver->ping());
		$this->assertNull($driver->getRaw('g', 'k'));
		$this->assertFalse($driver->setRaw('g', 'k', '{}', 60));
		$this->assertFalse($driver->delete('g', 'k'));
		$this->assertSame(0, $driver->flushGroup('g'));
		$this->assertSame(0, $driver->flushAll());
		$this->assertSame(['t' => 0], $driver->incrementTags(['t']));
		$this->assertFalse($driver->acquireLock('l', 't', 5));
		$this->assertFalse($driver->releaseLock('l', 't'));
		$this->assertSame([], $driver->groupCounts());

		// NativeApcuStore safe behavior when ext-apcu is absent in CLI.
		$native = new NativeApcuStore();

		if (!$native->isEnabled()) {
			$ok = true;
			$this->assertFalse($native->fetch('k', $ok));
			$this->assertFalse($ok);
			$this->assertFalse($native->store('k', 'v'));
			$this->assertFalse($native->add('k', 'v'));
			$this->assertFalse($native->delete('k'));
			$this->assertFalse($native->inc('k'));
			$this->assertSame([], $native->info());
		}
	}

	public function testMariaDbDriverAgainstSqliteAdapter(): void {
		$now    = 1700000000;
		$db     = new SqliteMariaDbCompatAdapter();
		$driver = new MariaDbDriver($db, static function () use (&$now): int {
			return $now;
		});

		$this->assertSame('mariadb', $driver->name());
		$this->assertTrue($driver->ping());
		$this->assertTrue($driver->setRaw('devices', 'd1', '{"v":"alpha"}', 30, ['device:1']));
		$this->assertSame('{"v":"alpha"}', $driver->getRaw('devices', 'd1'));
		$this->assertTrue($driver->setRaw('devices', 'd1', '{"v":"beta"}', 30, ['device:1']));
		$this->assertSame('{"v":"beta"}', $driver->getRaw('devices', 'd1'));
		$this->assertTrue($driver->setRaw('sites', 's1', '{"v":"sjc1"}', 0));
		$this->assertSame(['devices' => 1, 'sites' => 1], $driver->groupCounts());

		// Tag counters.
		$this->assertSame([], $driver->getTagChecksums([]));
		$this->assertSame([], $driver->incrementTags([]));
		$this->assertSame(['device:1' => 0], $driver->getTagChecksums(['device:1']));
		$this->assertSame(['device:1' => 1], $driver->incrementTags(['device:1']));
		$this->assertSame(['device:1' => 2], $driver->incrementTags(['device:1']));

		// Locks and expiry.
		$this->assertTrue($driver->acquireLock('l1', 't1', 10));
		$this->assertFalse($driver->acquireLock('l1', 't2', 10));
		$this->assertFalse($driver->releaseLock('l1', 't2'));
		$this->assertTrue($driver->releaseLock('l1', 't1'));

		// Expired row metrics & pruning.
		$now += 35;
		$metrics = $driver->metrics();
		$this->assertTrue($metrics['healthy']);
		$this->assertSame(1, $metrics['entries']);
		$this->assertSame(1, $metrics['expired']);
		$this->assertSame(1, $driver->pruneExpired());

		// Expired read eviction.
		$driver->setRaw('tmp', 'k', '{"v":1}', 5);
		$now += 6;
		$this->assertNull($driver->getRaw('tmp', 'k'));

		// Delete, flushGroup, flushAll.
		$driver->setRaw('g1', 'a', '{"v":1}', 0);
		$driver->setRaw('g2', 'b', '{"v":2}', 0);
		$this->assertTrue($driver->delete('g1', 'a'));
		$this->assertFalse($driver->delete('g1', 'a'));
		$this->assertSame(1, $driver->flushGroup('g2'));
		$this->assertGreaterThanOrEqual(1, $driver->flushAll());
	}

	public function testRedisDriverResp2ProtocolOverRealSocketPair(): void {
		$this->assertSame("*2\r\n\$4\r\nPING\r\n\$2\r\nhi\r\n", RedisDriver::encodeCommand(['PING', 'hi']));

		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($pair);
		[$client, $server] = $pair;

		// Pre-populate scripted RESP2 responses for:
		// 1. AUTH secret -> +OK
		// 2. SELECT 2 -> +OK
		// 3. PING -> +PONG
		// 4. SET cacti:e:g:k ... EX 60 -> +OK
		// 5. GET cacti:e:g:k -> $12\r\n{"v":"pong"}\r\n
		// 6. DEL cacti:e:g:k -> :1
		// 7. MGET cacti:tag:t1 cacti:tag:t2 -> *2\r\n$1\r\n3\r\n$-1\r\n
		// 8. INCR cacti:tag:t1 -> :4
		// 9. SET cacti:lock:lk tok NX EX 15 -> +OK
		// 10. GET cacti:lock:lk -> $3\r\ntok\r\n
		// 11. DEL cacti:lock:lk -> :1
		// 12. SCAN 0 MATCH cacti:e:* COUNT 200 -> *2\r\n$1\r\n0\r\n*2\r\n$13\r\ncacti:e:g1:k1\r\n$13\r\ncacti:e:g1:k2\r\n
		// 13. SCAN 0 MATCH cacti:e:g1:* COUNT 200 -> *2\r\n$1\r\n0\r\n*1\r\n$13\r\ncacti:e:g1:k1\r\n
		// 14. DEL cacti:e:g1:k1 -> :1
		// 15. SCAN 0 MATCH cacti:e:* COUNT 200 -> *2\r\n$1\r\n0\r\n*0\r\n
		// 16. PING -> +PONG (metrics)
		// 17. DBSIZE -> :42 (metrics)
		$responses = "+OK\r\n"
			. "+OK\r\n"
			. "+PONG\r\n"
			. "+OK\r\n"
			. "\$12\r\n{\"v\":\"pong\"}\r\n"
			. ":1\r\n"
			. "*2\r\n\$1\r\n3\r\n\$-1\r\n"
			. ":4\r\n"
			. "+OK\r\n"
			. "\$3\r\ntok\r\n"
			. ":1\r\n"
			. "*2\r\n\$1\r\n0\r\n*2\r\n\$13\r\ncacti:e:g1:k1\r\n\$13\r\ncacti:e:g1:k2\r\n"
			. "*2\r\n\$1\r\n0\r\n*1\r\n\$13\r\ncacti:e:g1:k1\r\n"
			. ":1\r\n"
			. "*2\r\n\$1\r\n0\r\n*0\r\n"
			. "+PONG\r\n"
			. ":42\r\n";

		fwrite($server, $responses);

		$capturedUri     = '';
		$capturedOptions = [];
		$driver          = new RedisDriver(
			'redis.internal',
			6380,
			true,
			2,
			1.5,
			'secret-pw',
			'cacti:',
			static function (string $uri, float $timeout, $context) use ($client, &$capturedUri, &$capturedOptions) {
				$capturedUri     = $uri;
				$capturedOptions = stream_context_get_options($context);

				return $client;
			}
		);

		$this->assertSame('redis', $driver->name());
		$this->assertTrue($driver->ping());
		$this->assertSame('tls://redis.internal:6380', $capturedUri);
		$this->assertTrue($capturedOptions['ssl']['verify_peer'] ?? false);
		$this->assertTrue($capturedOptions['ssl']['verify_peer_name'] ?? false);

		$this->assertTrue($driver->setRaw('g', 'k', '{"v":"pong"}', 60));
		$this->assertSame('{"v":"pong"}', $driver->getRaw('g', 'k'));
		$this->assertTrue($driver->delete('g', 'k'));
		$this->assertSame(['t1' => 3, 't2' => 0], $driver->getTagChecksums(['t1', 't2']));
		$this->assertSame(['t1' => 4], $driver->incrementTags(['t1']));
		$this->assertTrue($driver->acquireLock('lk', 'tok', 15));
		$this->assertTrue($driver->releaseLock('lk', 'tok'));
		$this->assertSame(['g1' => 2], $driver->groupCounts());
		$this->assertSame(1, $driver->flushGroup('g1'));
		$this->assertSame(0, $driver->flushAll());
		$this->assertSame(0, $driver->pruneExpired());

		$metrics = $driver->metrics();
		$this->assertTrue($metrics['healthy']);
		$this->assertSame(42, $metrics['entries']);
		$this->assertArrayNotHasKey('password', $metrics['details']);

		$driver->disconnect();
		fclose($server);
	}

	public function testRedisDriverErrorHandling(): void {
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($pair);
		[$client, $server] = $pair;

		fwrite($server, "-ERR NOAUTH Authentication required\r\n");

		$driver = new RedisDriver('127.0.0.1', 6379, false, 0, 1.0, null, 'cacti:', static fn () => $client);

		$this->expectException(CacheException::class);
		$this->expectExceptionMessage('NOAUTH');

		try {
			$driver->getRaw('default', 'k');
		} finally {
			fclose($server);
		}
	}
}

/**
 * In-memory APCu test double implementing ApcuStore.
 */
final class InMemoryApcuStore implements ApcuStore {
	/**
	 * Toggle to simulate disabled APCu runtime.
	 */
	public bool $enabled = true;

	/**
	 * Stored entries keyed by APCu key.
	 *
	 * @var array<string, mixed>
	 */
	private array $data = [];

	/**
	 * Return whether APCu simulation is enabled.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * Fetch a value from memory.
	 *
	 * @param string $key Key.
	 * @param bool $success Set to true on hit.
	 * @return mixed
	 */
	public function fetch(string $key, bool &$success): mixed {
		if (!$this->enabled || !array_key_exists($key, $this->data)) {
			$success = false;

			return false;
		}

		$success = true;

		return $this->data[$key];
	}

	/**
	 * Store a value in memory.
	 *
	 * @param string $key Key.
	 * @param mixed $value Value.
	 * @param int $ttl Unused in unit double.
	 * @return bool
	 */
	public function store(string $key, mixed $value, int $ttl = 0): bool {
		if (!$this->enabled) {
			return false;
		}

		$this->data[$key] = $value;

		return true;
	}

	/**
	 * Add a key only when absent.
	 *
	 * @param string $key Key.
	 * @param mixed $value Value.
	 * @param int $ttl Unused in unit double.
	 * @return bool
	 */
	public function add(string $key, mixed $value, int $ttl = 0): bool {
		if (!$this->enabled || array_key_exists($key, $this->data)) {
			return false;
		}

		$this->data[$key] = $value;

		return true;
	}

	/**
	 * Delete a key from memory.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function delete(string $key): bool {
		if (!$this->enabled || !array_key_exists($key, $this->data)) {
			return false;
		}

		unset($this->data[$key]);

		return true;
	}

	/**
	 * Increment an existing integer key.
	 *
	 * @param string $key Key.
	 * @param int $step Increment step.
	 * @return int|false
	 */
	public function inc(string $key, int $step = 1): int|false {
		if (!$this->enabled || !isset($this->data[$key]) || !is_int($this->data[$key])) {
			return false;
		}

		$this->data[$key] += $step;

		return $this->data[$key];
	}

	/**
	 * Return simulated APCu info stats.
	 *
	 * @return array<string, mixed>
	 */
	public function info(): array {
		return [
			'num_entries' => count($this->data),
			'mem_size'    => 4096,
		];
	}
}

/**
 * SQLite-backed DbAdapter translating MariaDB upsert/ignore clauses for unit testing.
 */
final class SqliteMariaDbCompatAdapter implements DbAdapter {
	/**
	 * In-memory SQLite PDO handle.
	 */
	private \PDO $pdo;

	/**
	 * Last statement affected row count.
	 */
	private int $affected = 0;

	/**
	 * Construct an in-memory SQLite schema matching plugin_cache tables.
	 */
	public function __construct() {
		$this->pdo = new \PDO('sqlite::memory:');
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec(
			'CREATE TABLE plugin_cache_entries (
				cache_id TEXT PRIMARY KEY,
				cache_group TEXT NOT NULL,
				cache_key TEXT NOT NULL,
				payload_json TEXT NOT NULL,
				tags_json TEXT NOT NULL,
				created_at INTEGER NOT NULL,
				expires_at INTEGER NOT NULL,
				payload_bytes INTEGER NOT NULL,
				UNIQUE(cache_group, cache_key)
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE plugin_cache_tags (
				tag TEXT PRIMARY KEY,
				invalidations INTEGER NOT NULL,
				updated_at INTEGER NOT NULL
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE plugin_cache_queue (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				queue_name TEXT NOT NULL,
				payload_json TEXT NOT NULL,
				status TEXT NOT NULL,
				available_at INTEGER NOT NULL,
				lease_until INTEGER NOT NULL,
				lease_token TEXT NOT NULL,
				attempts INTEGER NOT NULL,
				max_attempts INTEGER NOT NULL,
				cursor_json TEXT NULL,
				idempotency_key TEXT NULL,
				last_error TEXT NOT NULL,
				created_at INTEGER NOT NULL,
				updated_at INTEGER NOT NULL,
				UNIQUE(queue_name, idempotency_key)
			)'
		);
	}

	/**
	 * Execute a SQL statement with minimal MariaDB-to-SQLite dialect adaptation.
	 *
	 * @param string $sql Parameterized SQL query.
	 * @param list<bool|float|int|string|null> $params Positional bindings.
	 * @return bool
	 */
	public function execute(string $sql, array $params = []): bool {
		$adapted = $this->adaptSql($sql);
		$stmt    = $this->pdo->prepare($adapted);
		$ok      = $stmt->execute($params);
		$this->affected = $stmt->rowCount();

		return $ok;
	}

	/**
	 * Fetch a scalar cell.
	 *
	 * @param string $sql Parameterized SQL query.
	 * @param list<bool|float|int|string|null> $params Positional bindings.
	 * @return mixed
	 */
	public function fetchCell(string $sql, array $params = []): mixed {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);

		return $stmt->fetchColumn();
	}

	/**
	 * Fetch a single associative row.
	 *
	 * @param string $sql Parameterized SQL query.
	 * @param list<bool|float|int|string|null> $params Positional bindings.
	 * @return array<string, mixed>|null
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}

	/**
	 * Fetch all matching associative rows.
	 *
	 * @param string $sql Parameterized SQL query.
	 * @param list<bool|float|int|string|null> $params Positional bindings.
	 * @return list<array<string, mixed>>
	 */
	public function fetchAssoc(string $sql, array $params = []): array {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		/** @var list<array<string, mixed>> $rows */
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return $rows;
	}

	/**
	 * Return last auto-increment insert ID.
	 *
	 * @return int
	 */
	public function lastInsertId(): int {
		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * Return affected rows from the previous execute().
	 *
	 * @return int
	 */
	public function affectedRows(): int {
		return $this->affected;
	}

	/**
	 * Translate MariaDB-specific upsert/limit syntax to SQLite equivalents for unit tests.
	 *
	 * @param string $sql MariaDB SQL query.
	 * @return string
	 */
	private function adaptSql(string $sql): string {
		if (str_contains($sql, 'INSERT IGNORE INTO')) {
			return str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql);
		}

		if (str_contains($sql, 'INSERT INTO plugin_cache_entries') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
			return 'INSERT INTO plugin_cache_entries (cache_id, cache_group, cache_key, payload_json, tags_json, created_at, expires_at, payload_bytes)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?)
				ON CONFLICT(cache_id) DO UPDATE SET
				  payload_json = excluded.payload_json,
				  tags_json = excluded.tags_json,
				  created_at = excluded.created_at,
				  expires_at = excluded.expires_at,
				  payload_bytes = excluded.payload_bytes';
		}

		if (str_contains($sql, 'INSERT INTO plugin_cache_tags') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
			return 'INSERT INTO plugin_cache_tags (tag, invalidations, updated_at)
				VALUES (?, 1, ?)
				ON CONFLICT(tag) DO UPDATE SET
				  invalidations = plugin_cache_tags.invalidations + 1,
				  updated_at = excluded.updated_at';
		}

		if (str_contains($sql, 'UPDATE plugin_cache_queue') && str_contains($sql, 'ORDER BY available_at ASC, id ASC')) {
			return 'UPDATE plugin_cache_queue
				SET status = ?, lease_until = ?, lease_token = ?, attempts = attempts + 1, updated_at = ?
				WHERE id = (
					SELECT id FROM plugin_cache_queue
					WHERE queue_name = ? AND status = ? AND available_at <= ?
					ORDER BY available_at ASC, id ASC
					LIMIT 1
				)';
		}

		return $sql;
	}
}
