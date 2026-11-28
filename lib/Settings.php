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

namespace Somethingwithproof\Cache;

use Somethingwithproof\Cache\Config\ConfigSchema;

/**
 * Immutable plugin configuration loaded from Cacti's settings table.
 *
 * Stores declarative Config only; cache entries, tag counters, and queue rows
 * live in separate State tables and are never serialized here.
 */
final class Settings {
	/**
	 * Setting keys persisted in Cacti's settings table.
	 */
	public const NAMES = [
		'cache_driver',
		'cache_default_ttl',
		'cache_max_payload_bytes',
		'cache_key_prefix',
		'cache_lock_ttl',
		'cache_xfetch_beta',
		'cache_memory_capacity',
		'cache_redis_host',
		'cache_redis_port',
		'cache_redis_tls',
		'cache_redis_db',
		'cache_redis_timeout',
		'cache_redis_secret_ref',
		'cache_queue_default_lease',
		'cache_queue_default_max_attempts',
		'cache_uninstall_keep_tables',
	];

	/**
	 * Construct an immutable plugin configuration snapshot.
	 *
	 * @param string $driver Active cache driver name (mariadb, apcu, redis, memory).
	 * @param int $defaultTtl Default entry TTL in seconds.
	 * @param int $maxPayloadBytes Maximum JSON payload size in bytes.
	 * @param string $keyPrefix Prefix applied to keys in shared backends.
	 * @param int $lockTtl Single-flight lock lease duration in seconds.
	 * @param float $xfetchBeta XFetch probabilistic early-expiration beta factor.
	 * @param int $memoryCapacity S3-FIFO bounded entry capacity for MemoryDriver.
	 * @param string $redisHost Redis server host or IP address.
	 * @param int $redisPort Redis server TCP/TLS port.
	 * @param bool $redisTls Whether TLS transport is enabled for Redis.
	 * @param int $redisDb Redis logical database index (0..15).
	 * @param float $redisTimeout Redis socket timeout in seconds.
	 * @param string $redisSecretRef Typed secret reference (env:VAR or vault:plugin:slot).
	 * @param int $queueDefaultLease Default queue lease duration in seconds.
	 * @param int $queueDefaultMaxAttempts Default maximum retry attempts before dead-lettering.
	 * @param bool $uninstallKeepTables Whether plugin tables are kept on uninstall.
	 */
	public function __construct(
		public readonly string $driver = 'mariadb',
		public readonly int $defaultTtl = 300,
		public readonly int $maxPayloadBytes = 1048576,
		public readonly string $keyPrefix = 'cacti:',
		public readonly int $lockTtl = 15,
		public readonly float $xfetchBeta = 1.0,
		public readonly int $memoryCapacity = 1000,
		public readonly string $redisHost = '127.0.0.1',
		public readonly int $redisPort = 6379,
		public readonly bool $redisTls = false,
		public readonly int $redisDb = 0,
		public readonly float $redisTimeout = 2.0,
		public readonly string $redisSecretRef = '',
		public readonly int $queueDefaultLease = 60,
		public readonly int $queueDefaultMaxAttempts = 5,
		public readonly bool $uninstallKeepTables = false,
	) {
	}

	/**
	 * Hydrate and clamp a Settings instance from raw Cacti settings rows.
	 *
	 * @param array<string, mixed> $row Raw setting values keyed by setting name.
	 * @return self
	 */
	public static function fromRow(array $row): self {
		$rawDriver = self::scalarString($row['cache_driver'] ?? 'mariadb');
		$driver    = in_array($rawDriver, ConfigSchema::DRIVERS, true) ? $rawDriver : 'mariadb';

		$defaultTtl      = self::clampInt($row['cache_default_ttl'] ?? 300, 1, 2592000, 300);
		$maxPayloadBytes = self::clampInt($row['cache_max_payload_bytes'] ?? 1048576, 1024, 16777216, 1048576);

		$rawPrefix = self::scalarString($row['cache_key_prefix'] ?? 'cacti:');
		$keyPrefix = preg_match(ConfigSchema::KEY_PREFIX_PATTERN, $rawPrefix) === 1 ? $rawPrefix : 'cacti:';

		$lockTtl        = self::clampInt($row['cache_lock_ttl'] ?? 15, 1, 300, 15);
		$xfetchBeta     = self::clampFloat($row['cache_xfetch_beta'] ?? 1.0, 0.0, 10.0, 1.0);
		$memoryCapacity = self::clampInt($row['cache_memory_capacity'] ?? 1000, 10, 100000, 1000);

		$rawHost   = trim(self::scalarString($row['cache_redis_host'] ?? '127.0.0.1'));
		$redisHost = preg_match(ConfigSchema::REDIS_HOST_PATTERN, $rawHost) === 1 ? $rawHost : '127.0.0.1';
		$redisPort = self::clampInt($row['cache_redis_port'] ?? 6379, 1, 65535, 6379);
		$redisTls  = self::toBool($row['cache_redis_tls'] ?? false);
		$redisDb   = self::clampInt($row['cache_redis_db'] ?? 0, 0, 15, 0);
		$timeout   = self::clampFloat($row['cache_redis_timeout'] ?? 2.0, 0.1, 30.0, 2.0);

		$rawSecretRef   = trim(self::scalarString($row['cache_redis_secret_ref'] ?? ''));
		$redisSecretRef = preg_match(ConfigSchema::SECRET_REF_PATTERN, $rawSecretRef) === 1 ? $rawSecretRef : '';

		$queueLease    = self::clampInt($row['cache_queue_default_lease'] ?? 60, 5, 3600, 60);
		$queueAttempts = self::clampInt($row['cache_queue_default_max_attempts'] ?? 5, 1, 50, 5);
		$keepTables    = self::toBool($row['cache_uninstall_keep_tables'] ?? false);

		return new self(
			$driver,
			$defaultTtl,
			$maxPayloadBytes,
			$keyPrefix,
			$lockTtl,
			$xfetchBeta,
			$memoryCapacity,
			$redisHost,
			$redisPort,
			$redisTls,
			$redisDb,
			$timeout,
			$redisSecretRef,
			$queueLease,
			$queueAttempts,
			$keepTables
		);
	}

	/**
	 * Convert configuration to a canonical associative array suitable for CasC export.
	 *
	 * Secret references are exported as symbolic references only; resolved secrets
	 * are never included.
	 *
	 * @return array<string, bool|float|int|string>
	 */
	public function toConfigArray(): array {
		return [
			'cache_driver'                     => $this->driver,
			'cache_default_ttl'                => $this->defaultTtl,
			'cache_max_payload_bytes'          => $this->maxPayloadBytes,
			'cache_key_prefix'                 => $this->keyPrefix,
			'cache_lock_ttl'                   => $this->lockTtl,
			'cache_xfetch_beta'                => $this->xfetchBeta,
			'cache_memory_capacity'            => $this->memoryCapacity,
			'cache_redis_host'                 => $this->redisHost,
			'cache_redis_port'                 => $this->redisPort,
			'cache_redis_tls'                  => $this->redisTls,
			'cache_redis_db'                   => $this->redisDb,
			'cache_redis_timeout'              => $this->redisTimeout,
			'cache_redis_secret_ref'           => $this->redisSecretRef,
			'cache_queue_default_lease'        => $this->queueDefaultLease,
			'cache_queue_default_max_attempts' => $this->queueDefaultMaxAttempts,
			'cache_uninstall_keep_tables'      => $this->uninstallKeepTables,
		];
	}

	/**
	 * Convert configuration into Cacti settings table string pairs.
	 *
	 * @return array<string, string>
	 */
	public function toCactiSettingsMap(): array {
		return [
			'cache_driver'                     => $this->driver,
			'cache_default_ttl'                => (string) $this->defaultTtl,
			'cache_max_payload_bytes'          => (string) $this->maxPayloadBytes,
			'cache_key_prefix'                 => $this->keyPrefix,
			'cache_lock_ttl'                   => (string) $this->lockTtl,
			'cache_xfetch_beta'                => sprintf('%.2F', $this->xfetchBeta),
			'cache_memory_capacity'            => (string) $this->memoryCapacity,
			'cache_redis_host'                 => $this->redisHost,
			'cache_redis_port'                 => (string) $this->redisPort,
			'cache_redis_tls'                  => $this->redisTls ? 'on' : '',
			'cache_redis_db'                   => (string) $this->redisDb,
			'cache_redis_timeout'              => sprintf('%.2F', $this->redisTimeout),
			'cache_redis_secret_ref'           => $this->redisSecretRef,
			'cache_queue_default_lease'        => (string) $this->queueDefaultLease,
			'cache_queue_default_max_attempts' => (string) $this->queueDefaultMaxAttempts,
			'cache_uninstall_keep_tables'      => $this->uninstallKeepTables ? 'on' : '',
		];
	}

	/**
	 * Resolve the runtime Redis password from the configured typed secret reference.
	 *
	 * Supports env:VAR natively and vault:<plugin>:<slot> via optional runtime
	 * cacti_vault_get() soft-coupling without hard plugin imports.
	 *
	 * @param (callable(string): ?string)|null $envReader Optional environment lookup for testing.
	 * @return string|null Resolved password or null when unconfigured or unavailable.
	 */
	public function resolveRedisPassword(?callable $envReader = null): ?string {
		if ($this->redisSecretRef === '') {
			return null;
		}

		if (str_starts_with($this->redisSecretRef, 'env:')) {
			$varName = substr($this->redisSecretRef, 4);

			if ($envReader !== null) {
				$value = $envReader($varName);

				return is_string($value) && $value !== '' ? $value : null;
			}

			$value = getenv($varName);

			return is_string($value) && $value !== '' ? $value : null;
		}

		if (str_starts_with($this->redisSecretRef, 'vault:')) {
			$parts = explode(':', $this->redisSecretRef, 3);

			if (count($parts) === 3 && function_exists('cacti_vault_get')) {
				/** @var mixed $secret */
				$secret = cacti_vault_get($parts[1], $parts[2]);

				return is_string($secret) && $secret !== '' ? $secret : null;
			}
		}

		return null;
	}

	/**
	 * Return the Cacti Settings tab form specification.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function form(): array {
		return [
			'cache_header' => [
				'friendly_name' => __('Object Cache & Batch Queue', 'cache'),
				'method'        => 'spacer',
			],
			'cache_driver' => [
				'friendly_name' => __('Cache Driver', 'cache'),
				'description'   => __('Backend driver used for object cache and transient storage.', 'cache'),
				'method'        => 'drop_array',
				'default'       => 'mariadb',
				'array'         => [
					'mariadb' => __('MariaDB (plugin_cache_entries)', 'cache'),
					'apcu'    => __('APCu (in-memory shared)', 'cache'),
					'redis'   => __('Redis (pure-PHP RESP2 socket)', 'cache'),
					'memory'  => __('Process Memory (S3-FIFO bounded)', 'cache'),
				],
			],
			'cache_default_ttl' => [
				'friendly_name' => __('Default TTL (seconds)', 'cache'),
				'description'   => __('Default cache entry expiration in seconds when no TTL is supplied.', 'cache'),
				'method'        => 'textbox',
				'default'       => '300',
				'max_length'    => '10',
				'size'          => '10',
			],
			'cache_max_payload_bytes' => [
				'friendly_name' => __('Maximum Payload Size (bytes)', 'cache'),
				'description'   => __('Upper bound in bytes on JSON-encoded cache and queue payloads (1024 to 16777216).', 'cache'),
				'method'        => 'textbox',
				'default'       => '1048576',
				'max_length'    => '10',
				'size'          => '12',
			],
			'cache_key_prefix' => [
				'friendly_name' => __('Key Prefix', 'cache'),
				'description'   => __('Key namespace prefix applied in shared backends (APCu and Redis).', 'cache'),
				'method'        => 'textbox',
				'default'       => 'cacti:',
				'max_length'    => '32',
				'size'          => '24',
			],
			'cache_lock_ttl' => [
				'friendly_name' => __('Stampede Lock TTL (seconds)', 'cache'),
				'description'   => __('Single-flight lock duration in seconds for CacheService::remember().', 'cache'),
				'method'        => 'textbox',
				'default'       => '15',
				'max_length'    => '5',
				'size'          => '8',
			],
			'cache_xfetch_beta' => [
				'friendly_name' => __('XFetch Beta Factor', 'cache'),
				'description'   => __('Probabilistic early-expiration multiplier (default 1.0; set 0 to disable early recompute).', 'cache'),
				'method'        => 'textbox',
				'default'       => '1.0',
				'max_length'    => '6',
				'size'          => '8',
			],
			'cache_memory_capacity' => [
				'friendly_name' => __('Memory Driver S3-FIFO Capacity', 'cache'),
				'description'   => __('Maximum entries retained in MemoryDriver before S3-FIFO eviction runs.', 'cache'),
				'method'        => 'textbox',
				'default'       => '1000',
				'max_length'    => '8',
				'size'          => '10',
			],
			'cache_redis_header' => [
				'friendly_name' => __('Redis Transport (Pure-PHP RESP2)', 'cache'),
				'method'        => 'spacer',
			],
			'cache_redis_host' => [
				'friendly_name' => __('Redis Host', 'cache'),
				'description'   => __('Hostname or IP address for the Redis server.', 'cache'),
				'method'        => 'textbox',
				'default'       => '127.0.0.1',
				'max_length'    => '253',
				'size'          => '40',
			],
			'cache_redis_port' => [
				'friendly_name' => __('Redis Port', 'cache'),
				'description'   => __('TCP or TLS port for the Redis server.', 'cache'),
				'method'        => 'textbox',
				'default'       => '6379',
				'max_length'    => '5',
				'size'          => '8',
			],
			'cache_redis_tls' => [
				'friendly_name' => __('Enable Redis TLS', 'cache'),
				'description'   => __('Connect to Redis using tls:// with peer certificate verification.', 'cache'),
				'method'        => 'checkbox',
				'default'       => '',
			],
			'cache_redis_db' => [
				'friendly_name' => __('Redis Database Index', 'cache'),
				'description'   => __('Logical Redis database number (0 through 15).', 'cache'),
				'method'        => 'textbox',
				'default'       => '0',
				'max_length'    => '2',
				'size'          => '4',
			],
			'cache_redis_timeout' => [
				'friendly_name' => __('Redis Timeout (seconds)', 'cache'),
				'description'   => __('Socket connect and read/write timeout in seconds.', 'cache'),
				'method'        => 'textbox',
				'default'       => '2.0',
				'max_length'    => '6',
				'size'          => '8',
			],
			'cache_redis_secret_ref' => [
				'friendly_name' => __('Redis Secret Reference', 'cache'),
				'description'   => __('Typed secret reference (env:VAR or vault:plugin:slot); raw passwords are never stored.', 'cache'),
				'method'        => 'textbox',
				'default'       => '',
				'max_length'    => '160',
				'size'          => '48',
			],
			'cache_queue_header' => [
				'friendly_name' => __('Resumable Batch Queue', 'cache'),
				'method'        => 'spacer',
			],
			'cache_queue_default_lease' => [
				'friendly_name' => __('Default Queue Lease (seconds)', 'cache'),
				'description'   => __('Lease duration in seconds before an unacknowledged queue item becomes reclaimable.', 'cache'),
				'method'        => 'textbox',
				'default'       => '60',
				'max_length'    => '6',
				'size'          => '8',
			],
			'cache_queue_default_max_attempts' => [
				'friendly_name' => __('Default Max Attempts', 'cache'),
				'description'   => __('Maximum delivery attempts before moving an item to dead-letter status.', 'cache'),
				'method'        => 'textbox',
				'default'       => '5',
				'max_length'    => '3',
				'size'          => '6',
			],
			'cache_uninstall_keep_tables' => [
				'friendly_name' => __('Keep Plugin Tables on Uninstall', 'cache'),
				'description'   => __('Preserve plugin_cache_entries, plugin_cache_tags, and plugin_cache_queue tables on uninstall.', 'cache'),
				'method'        => 'checkbox',
				'default'       => '',
			],
		];
	}

	/**
	 * Convert a scalar value to string, returning empty string for non-scalars.
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 */
	private static function scalarString(mixed $value): string {
		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Parse and clamp an integer value within inclusive bounds.
	 *
	 * @param mixed $value Candidate value.
	 * @param int $min Minimum allowed value.
	 * @param int $max Maximum allowed value.
	 * @param int $default Fallback value when non-numeric.
	 * @return int
	 */
	private static function clampInt(mixed $value, int $min, int $max, int $default): int {
		if (!is_int($value) && !(is_string($value) && is_numeric(trim($value)))) {
			return $default;
		}

		$parsed = (int) $value;

		return max($min, min($max, $parsed));
	}

	/**
	 * Parse and clamp a float value within inclusive bounds.
	 *
	 * @param mixed $value Candidate value.
	 * @param float $min Minimum allowed value.
	 * @param float $max Maximum allowed value.
	 * @param float $default Fallback value when non-numeric.
	 * @return float
	 */
	private static function clampFloat(mixed $value, float $min, float $max, float $default): float {
		if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
			return $default;
		}

		$parsed = (float) $value;

		if (!is_finite($parsed)) {
			return $default;
		}

		return max($min, min($max, $parsed));
	}

	/**
	 * Normalize a Cacti checkbox or boolean config value.
	 *
	 * @param mixed $value Raw checkbox value ('on', '1', true) or falsey value.
	 * @return bool
	 */
	private static function toBool(mixed $value): bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return $value === 1;
		}

		if (is_string($value)) {
			$normalized = strtolower(trim($value));

			return in_array($normalized, ['on', '1', 'true'], true);
		}

		return false;
	}
}
