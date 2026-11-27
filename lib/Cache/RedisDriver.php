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
 * Pure-PHP Redis RESP2 cache driver over TCP/TLS sockets with zero PECL dependencies.
 *
 * Supports optional TLS transport with peer certificate verification, AUTH,
 * logical DB selection, socket timeouts, atomic SET NX EX locks, INCR/MGET
 * Drupal cache tag checksums, and prefix-scoped SCAN flushes.
 */
final class RedisDriver implements CacheDriver {
	/**
	 * Active stream socket resource or null when disconnected.
	 *
	 * @var resource|null
	 */
	private $socket = null;

	/**
	 * Custom stream connector factory used for deterministic socket testing.
	 *
	 * @var (callable(string, float, resource): (resource|false))|null
	 */
	private readonly mixed $streamFactory;

	/**
	 * Construct a pure-PHP RESP2 Redis cache driver.
	 *
	 * @param string $host Redis server hostname or IP address.
	 * @param int $port Redis server TCP or TLS port.
	 * @param bool $tls Enable tls:// transport with peer verification.
	 * @param int $database Logical Redis database index (0..15).
	 * @param float $timeout Connect and stream read/write timeout in seconds.
	 * @param string|null $password Optional Redis AUTH password resolved at runtime.
	 * @param string $prefix Key namespace prefix applied to all keys.
	 * @param (callable(string, float, resource): (resource|false))|null $streamFactory Optional socket factory for testing.
	 */
	public function __construct(
		private readonly string $host = '127.0.0.1',
		private readonly int $port = 6379,
		private readonly bool $tls = false,
		private readonly int $database = 0,
		private readonly float $timeout = 2.0,
		private readonly ?string $password = null,
		private readonly string $prefix = 'cacti:',
		?callable $streamFactory = null,
	) {
		$this->streamFactory = $streamFactory;
	}

	/**
	 * Close any open socket resource on destruction.
	 */
	public function __destruct() {
		$this->disconnect();
	}

	/**
	 * Return the driver identifier.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'redis';
	}

	/**
	 * Fetch a raw JSON envelope from Redis via GET.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string|null
	 */
	public function getRaw(string $group, string $key): ?string {
		$response = $this->command(['GET', $this->entryKey($group, $key)]);

		return is_string($response) ? $response : null;
	}

	/**
	 * Store a raw JSON envelope in Redis using SET (with EX when TTL > 0).
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @param string $payload JSON-encoded envelope string.
	 * @param int $ttlSeconds Expiration in seconds (0 = no expiration).
	 * @param list<string> $tags Validated cache tags.
	 * @return bool
	 */
	public function setRaw(string $group, string $key, string $payload, int $ttlSeconds, array $tags = []): bool {
		$args = ['SET', $this->entryKey($group, $key), $payload];

		if ($ttlSeconds > 0) {
			$args[] = 'EX';
			$args[] = (string) $ttlSeconds;
		}

		$response = $this->command($args);

		return $response === 'OK';
	}

	/**
	 * Delete a single key from Redis.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return bool
	 */
	public function delete(string $group, string $key): bool {
		$response = $this->command(['DEL', $this->entryKey($group, $key)]);

		return is_int($response) && $response > 0;
	}

	/**
	 * Delete all keys matching this cache group's prefix via SCAN + DEL.
	 *
	 * @param string $group Validated cache group name.
	 * @return int
	 */
	public function flushGroup(string $group): int {
		return $this->deleteByPattern($this->prefix . 'e:' . $group . ':*');
	}

	/**
	 * Delete all cache entries under this driver's prefix via SCAN + DEL.
	 *
	 * Never executes global FLUSHDB so co-located Redis workloads stay untouched.
	 *
	 * @return int
	 */
	public function flushAll(): int {
		return $this->deleteByPattern($this->prefix . 'e:*');
	}

	/**
	 * Redis evicts keys with EX TTL natively; no manual prune query is needed.
	 *
	 * @param int|null $now Unused for Redis.
	 * @return int Always 0.
	 */
	public function pruneExpired(?int $now = null): int {
		return 0;
	}

	/**
	 * Fetch current monotonic invalidation counters for cache tags using MGET.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function getTagChecksums(array $tags): array {
		if ($tags === []) {
			return [];
		}

		$args = ['MGET'];

		foreach ($tags as $tag) {
			$args[] = $this->tagKey($tag);
		}

		$response = $this->command($args);
		$result   = [];

		foreach ($tags as $index => $tag) {
			$val          = is_array($response) ? ($response[$index] ?? null) : null;
			$result[$tag] = is_numeric($val) && (int) $val >= 0 ? (int) $val : 0;
		}

		return $result;
	}

	/**
	 * Atomically increment invalidation counters for each cache tag using INCR.
	 *
	 * @param list<string> $tags Validated cache tags.
	 * @return array<string, int>
	 */
	public function incrementTags(array $tags): array {
		$result = [];

		foreach ($tags as $tag) {
			$val          = $this->command(['INCR', $this->tagKey($tag)]);
			$result[$tag] = is_int($val) ? $val : 0;
		}

		return $result;
	}

	/**
	 * Acquire a single-flight lock atomically using SET key token NX EX ttl.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller-owned random token.
	 * @param int $ttlSeconds Lease duration in seconds.
	 * @return bool
	 */
	public function acquireLock(string $lockKey, string $token, int $ttlSeconds): bool {
		$response = $this->command([
			'SET',
			$this->lockKey($lockKey),
			$token,
			'NX',
			'EX',
			(string) max(1, $ttlSeconds),
		]);

		return $response === 'OK';
	}

	/**
	 * Release a single-flight lock after verifying token ownership.
	 *
	 * @param string $lockKey Lock identifier.
	 * @param string $token Caller-owned token.
	 * @return bool
	 */
	public function releaseLock(string $lockKey, string $token): bool {
		$key     = $this->lockKey($lockKey);
		$current = $this->command(['GET', $key]);

		if (!is_string($current) || !hash_equals($current, $token)) {
			return false;
		}

		$deleted = $this->command(['DEL', $key]);

		return is_int($deleted) && $deleted > 0;
	}

	/**
	 * Scan namespaced keys and aggregate counts by cache group.
	 *
	 * @return array<string, int>
	 */
	public function groupCounts(): array {
		$counts      = [];
		$entryPrefix = $this->prefix . 'e:';
		$prefixLen   = strlen($entryPrefix);

		foreach ($this->scanKeys($entryPrefix . '*') as $fullKey) {
			if (!str_starts_with($fullKey, $entryPrefix)) {
				continue;
			}

			$remainder = substr($fullKey, $prefixLen);
			$sep       = strpos($remainder, ':');

			if ($sep === false || $sep === 0) {
				continue;
			}

			$group          = substr($remainder, 0, $sep);
			$counts[$group] = ($counts[$group] ?? 0) + 1;
		}

		ksort($counts, SORT_STRING);

		return $counts;
	}

	/**
	 * Return Redis transport health, key counts, and safe connection metadata (no secrets).
	 *
	 * @return array{driver: string, healthy: bool, entries: int, expired: int, bytes: int, details: array<string, mixed>}
	 */
	public function metrics(): array {
		$healthy = false;
		$entries = 0;

		try {
			$healthy = $this->ping();

			if ($healthy) {
				$dbSize  = $this->command(['DBSIZE']);
				$entries = is_int($dbSize) ? $dbSize : 0;
			}
		} catch (CacheException) {
			$healthy = false;
		}

		return [
			'driver'  => $this->name(),
			'healthy' => $healthy,
			'entries' => $entries,
			'expired' => 0,
			'bytes'   => 0,
			'details' => [
				'host'     => $this->host,
				'port'     => $this->port,
				'tls'      => $this->tls,
				'database' => $this->database,
				'prefix'   => $this->prefix,
			],
		];
	}

	/**
	 * Send a RESP2 PING command and verify a PONG reply.
	 *
	 * @return bool
	 */
	public function ping(): bool {
		try {
			return $this->command(['PING']) === 'PONG';
		} catch (CacheException) {
			return false;
		}
	}

	/**
	 * Encode a Redis command array into RESP2 wire format.
	 *
	 * @param list<string> $arguments Command name followed by string arguments.
	 * @return string RESP2 wire payload.
	 */
	public static function encodeCommand(array $arguments): string {
		$buffer = '*' . count($arguments) . "\r\n";

		foreach ($arguments as $arg) {
			$buffer .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
		}

		return $buffer;
	}

	/**
	 * Close the active Redis socket connection.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		if (is_resource($this->socket)) {
			@fclose($this->socket);
		}

		$this->socket = null;
	}

	/**
	 * Execute a single RESP2 command and parse its typed reply.
	 *
	 * @param list<string> $arguments Command tokens.
	 * @return mixed Simple string, bulk string, int, array, or null.
	 * @throws CacheException On transport failure or Redis error reply.
	 */
	private function command(array $arguments): mixed {
		$this->ensureConnected();
		$socket = $this->socket;

		if (!is_resource($socket)) {
			throw new CacheException('Redis socket is not connected.');
		}

		$wire = self::encodeCommand($arguments);
		$this->writeAll($socket, $wire);

		return $this->readResponse($socket);
	}

	/**
	 * Open a TCP/TLS socket connection and perform optional AUTH and SELECT handshake.
	 *
	 * @return void
	 * @throws CacheException When connecting, authenticating, or selecting DB fails.
	 */
	private function ensureConnected(): void {
		if (is_resource($this->socket) && !feof($this->socket)) {
			return;
		}

		$this->disconnect();

		$scheme   = $this->tls ? 'tls' : 'tcp';
		$hostSpec = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
		$uri      = sprintf('%s://%s:%d', $scheme, $hostSpec, $this->port);

		$contextOptions = $this->tls ? [
			'ssl' => [
				'verify_peer'      => true,
				'verify_peer_name' => true,
				'allow_self_signed' => false,
				'SNI_enabled'      => true,
				'peer_name'        => $this->host,
			],
		] : [];

		$context = stream_context_create($contextOptions);

		if ($this->streamFactory !== null) {
			$socket = ($this->streamFactory)($uri, $this->timeout, $context);
		} else {
			$errno  = 0;
			$errstr = '';
			$socket = @stream_socket_client($uri, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);

			if (!is_resource($socket)) {
				throw new CacheException(sprintf('Redis connection to %s failed (%d): %s', $uri, $errno, $errstr));
			}
		}

		if (!is_resource($socket)) {
			throw new CacheException(sprintf('Redis connection to %s failed.', $uri));
		}

		$seconds      = (int) floor($this->timeout);
		$microseconds = (int) round(($this->timeout - $seconds) * 1000000);
		stream_set_timeout($socket, $seconds, $microseconds);

		$this->socket = $socket;

		try {
			if ($this->password !== null && $this->password !== '') {
				$authResp = $this->commandRaw($socket, ['AUTH', $this->password]);

				if ($authResp !== 'OK') {
					throw new CacheException('Redis AUTH did not return OK.');
				}
			}

			if ($this->database > 0) {
				$selectResp = $this->commandRaw($socket, ['SELECT', (string) $this->database]);

				if ($selectResp !== 'OK') {
					throw new CacheException(sprintf('Redis SELECT %d failed.', $this->database));
				}
			}
		} catch (CacheException $error) {
			$this->disconnect();

			throw $error;
		}
	}

	/**
	 * Send a command directly over an already opened socket without re-entering ensureConnected().
	 *
	 * @param resource $socket Open stream socket.
	 * @param list<string> $arguments Command tokens.
	 * @return mixed
	 * @throws CacheException On write/read failure or Redis error reply.
	 */
	private function commandRaw($socket, array $arguments): mixed {
		$this->writeAll($socket, self::encodeCommand($arguments));

		return $this->readResponse($socket);
	}

	/**
	 * Write a complete byte buffer to the socket, handling partial writes.
	 *
	 * @param resource $socket Open stream socket.
	 * @param string $buffer Wire bytes to send.
	 * @return void
	 * @throws CacheException On broken pipe or timeout.
	 */
	private function writeAll($socket, string $buffer): void {
		$total   = strlen($buffer);
		$written = 0;

		while ($written < $total) {
			$chunk = @fwrite($socket, substr($buffer, $written));

			if ($chunk === false || $chunk === 0) {
				$this->disconnect();

				throw new CacheException('Failed writing RESP2 command to Redis socket.');
			}

			$written += $chunk;
		}
	}

	/**
	 * Parse a single RESP2 reply frame from the socket.
	 *
	 * @param resource $socket Open stream socket.
	 * @return mixed
	 * @throws CacheException On EOF, protocol violation, or Redis error frame.
	 */
	private function readResponse($socket): mixed {
		$line = $this->readLine($socket);

		if ($line === '') {
			throw new CacheException('Empty RESP2 reply from Redis.');
		}

		$prefix  = $line[0];
		$payload = substr($line, 1);

		if ($prefix === '+') {
			return $payload;
		}

		if ($prefix === '-') {
			throw new CacheException('Redis server error: ' . $payload);
		}

		if ($prefix === ':') {
			return (int) $payload;
		}

		if ($prefix === '$') {
			$length = (int) $payload;

			if ($length < 0) {
				return null;
			}

			$data = $this->readExact($socket, $length + 2);

			return substr($data, 0, $length);
		}

		if ($prefix === '*') {
			$count = (int) $payload;

			if ($count < 0) {
				return null;
			}

			$items = [];

			for ($i = 0; $i < $count; $i++) {
				$items[] = $this->readResponse($socket);
			}

			return $items;
		}

		throw new CacheException(sprintf('Unexpected RESP2 prefix byte "%s".', $prefix));
	}

	/**
	 * Read a CRLF-terminated RESP2 header line from the socket.
	 *
	 * @param resource $socket Open stream socket.
	 * @return string Line content without trailing CRLF.
	 * @throws CacheException On EOF or timeout.
	 */
	private function readLine($socket): string {
		$line = @fgets($socket);

		if ($line === false) {
			$this->disconnect();

			throw new CacheException('Unexpected EOF or timeout reading RESP2 line from Redis.');
		}

		return rtrim($line, "\r\n");
	}

	/**
	 * Read an exact byte count from the socket.
	 *
	 * @param resource $socket Open stream socket.
	 * @param int $length Number of bytes to read.
	 * @return string
	 * @throws CacheException On premature EOF or timeout.
	 */
	private function readExact($socket, int $length): string {
		$buffer = '';

		while (strlen($buffer) < $length) {
			$remaining = $length - strlen($buffer);

			if ($remaining < 1) {
				break;
			}

			$chunk = @fread($socket, $remaining);

			if ($chunk === false || $chunk === '') {
				$this->disconnect();

				throw new CacheException('Unexpected EOF or timeout reading RESP2 bulk payload from Redis.');
			}

			$buffer .= $chunk;
		}

		return $buffer;
	}

	/**
	 * Iterate matching keys via Redis SCAN with bounded batch count.
	 *
	 * @param string $pattern Redis glob match pattern.
	 * @return list<string>
	 */
	private function scanKeys(string $pattern): array {
		$cursor  = '0';
		$matched = [];
		$loops   = 0;

		do {
			$response = $this->command(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200']);

			if (!is_array($response) || count($response) < 2) {
				break;
			}

			$cursor = is_scalar($response[0]) ? (string) $response[0] : '0';
			$batch  = is_array($response[1]) ? $response[1] : [];

			foreach ($batch as $key) {
				if (is_string($key)) {
					$matched[] = $key;
				}
			}

			$loops++;
		} while ($cursor !== '0' && $loops < 1000);

		return $matched;
	}

	/**
	 * Delete all keys matching a namespace pattern in batches.
	 *
	 * @param string $pattern Redis glob match pattern.
	 * @return int Total deleted keys.
	 */
	private function deleteByPattern(string $pattern): int {
		$keys    = $this->scanKeys($pattern);
		$deleted = 0;

		foreach (array_chunk($keys, 100) as $chunk) {
			$args = array_merge(['DEL'], $chunk);
			$resp = $this->command($args);

			if (is_int($resp)) {
				$deleted += $resp;
			}
		}

		return $deleted;
	}

	/**
	 * Build the namespaced Redis key for a cache entry.
	 *
	 * @param string $group Validated cache group name.
	 * @param string $key Validated cache key.
	 * @return string
	 */
	private function entryKey(string $group, string $key): string {
		return $this->prefix . 'e:' . $group . ':' . $key;
	}

	/**
	 * Build the namespaced Redis key for a cache tag counter.
	 *
	 * @param string $tag Validated cache tag.
	 * @return string
	 */
	private function tagKey(string $tag): string {
		return $this->prefix . 'tag:' . $tag;
	}

	/**
	 * Build the namespaced Redis key for a single-flight lock.
	 *
	 * @param string $lockKey Lock identifier.
	 * @return string
	 */
	private function lockKey(string $lockKey): string {
		return $this->prefix . 'lock:' . $lockKey;
	}
}
