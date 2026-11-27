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

namespace Somethingwithproof\Cache\Config;

/**
 * Runtime mirror of schema/cache.schema.yaml enforcing Config vs. State separation
 * and field validation for settings, admin forms, and CasC providers.
 */
final class ConfigSchema {
	/**
	 * Schema version string matching schema/cache.schema.yaml.
	 */
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Supported cache backend driver identifiers.
	 */
	public const DRIVERS = ['mariadb', 'apcu', 'redis', 'memory'];

	/**
	 * Pattern for validated cache key prefixes.
	 */
	public const KEY_PREFIX_PATTERN = '/^[A-Za-z0-9_.:-]{0,32}$/D';

	/**
	 * Pattern for validated Redis hostnames and IP literals.
	 */
	public const REDIS_HOST_PATTERN = '/^[A-Za-z0-9._:-]{1,253}$/D';

	/**
	 * Pattern for typed Redis secret references (never raw secret values).
	 */
	public const SECRET_REF_PATTERN = '/^(?:|env:[A-Za-z_][A-Za-z0-9_]{0,127}|vault:[a-z][a-z0-9_]{0,31}:[A-Za-z0-9_.-]{1,64})$/D';

	/**
	 * Declarative config field definitions matching schema/cache.schema.yaml.
	 *
	 * @return array<string, array{type: string, default: bool|float|int|string, min?: float|int, max?: float|int, values?: list<string>, pattern?: string, secretRef?: bool}>
	 */
	public static function configFields(): array {
		return [
			'cache_driver' => [
				'type'    => 'enum',
				'values'  => self::DRIVERS,
				'default' => 'mariadb',
			],
			'cache_default_ttl' => [
				'type'    => 'integer',
				'min'     => 1,
				'max'     => 2592000,
				'default' => 300,
			],
			'cache_max_payload_bytes' => [
				'type'    => 'integer',
				'min'     => 1024,
				'max'     => 16777216,
				'default' => 1048576,
			],
			'cache_key_prefix' => [
				'type'    => 'string',
				'pattern' => self::KEY_PREFIX_PATTERN,
				'default' => 'cacti:',
			],
			'cache_lock_ttl' => [
				'type'    => 'integer',
				'min'     => 1,
				'max'     => 300,
				'default' => 15,
			],
			'cache_xfetch_beta' => [
				'type'    => 'number',
				'min'     => 0.0,
				'max'     => 10.0,
				'default' => 1.0,
			],
			'cache_memory_capacity' => [
				'type'    => 'integer',
				'min'     => 10,
				'max'     => 100000,
				'default' => 1000,
			],
			'cache_redis_host' => [
				'type'    => 'string',
				'pattern' => self::REDIS_HOST_PATTERN,
				'default' => '127.0.0.1',
			],
			'cache_redis_port' => [
				'type'    => 'integer',
				'min'     => 1,
				'max'     => 65535,
				'default' => 6379,
			],
			'cache_redis_tls' => [
				'type'    => 'boolean',
				'default' => false,
			],
			'cache_redis_db' => [
				'type'    => 'integer',
				'min'     => 0,
				'max'     => 15,
				'default' => 0,
			],
			'cache_redis_timeout' => [
				'type'    => 'number',
				'min'     => 0.1,
				'max'     => 30.0,
				'default' => 2.0,
			],
			'cache_redis_secret_ref' => [
				'type'      => 'string',
				'pattern'   => self::SECRET_REF_PATTERN,
				'default'   => '',
				'secretRef' => true,
			],
			'cache_queue_default_lease' => [
				'type'    => 'integer',
				'min'     => 5,
				'max'     => 3600,
				'default' => 60,
			],
			'cache_queue_default_max_attempts' => [
				'type'    => 'integer',
				'min'     => 1,
				'max'     => 50,
				'default' => 5,
			],
			'cache_uninstall_keep_tables' => [
				'type'    => 'boolean',
				'default' => false,
			],
		];
	}

	/**
	 * Return state table names that must never be exported as configuration.
	 *
	 * @return list<string>
	 */
	public static function stateTables(): array {
		return [
			'plugin_cache_entries',
			'plugin_cache_tags',
			'plugin_cache_queue',
		];
	}

	/**
	 * Validate a candidate config map against the static schema rules.
	 *
	 * @param array<string, mixed> $config Candidate key-value configuration map.
	 * @return list<string> List of human-readable validation errors (empty when valid).
	 */
	public static function validate(array $config): array {
		$fields = self::configFields();
		$errors = [];

		foreach ($config as $key => $value) {
			if (!isset($fields[$key])) {
				$errors[] = sprintf('Unknown or non-config key "%s" is not permitted in cache config.', $key);

				continue;
			}

			$rule = $fields[$key];

			if ($rule['type'] === 'enum') {
				$allowed = $rule['values'] ?? [];

				if (!is_string($value) || !in_array($value, $allowed, true)) {
					$errors[] = sprintf('Field "%s" must be one of: %s.', $key, implode(', ', $allowed));
				}

				continue;
			}

			if ($rule['type'] === 'boolean') {
				if (!is_bool($value)) {
					$errors[] = sprintf('Field "%s" must be a boolean.', $key);
				}

				continue;
			}

			if ($rule['type'] === 'integer') {
				if (!is_int($value)) {
					$errors[] = sprintf('Field "%s" must be an integer.', $key);

					continue;
				}

				$min = (int) ($rule['min'] ?? PHP_INT_MIN);
				$max = (int) ($rule['max'] ?? PHP_INT_MAX);

				if ($value < $min || $value > $max) {
					$errors[] = sprintf('Field "%s" must be between %d and %d.', $key, $min, $max);
				}

				continue;
			}

			if ($rule['type'] === 'number') {
				if (!is_int($value) && !is_float($value)) {
					$errors[] = sprintf('Field "%s" must be a number.', $key);

					continue;
				}

				$num = (float) $value;
				$min = (float) ($rule['min'] ?? -INF);
				$max = (float) ($rule['max'] ?? INF);

				if (!is_finite($num) || $num < $min || $num > $max) {
					$errors[] = sprintf('Field "%s" must be between %.2f and %.2f.', $key, $min, $max);
				}

				continue;
			}

			if (!is_string($value)) {
				$errors[] = sprintf('Field "%s" must be a string.', $key);

				continue;
			}

			if (isset($rule['pattern']) && preg_match($rule['pattern'], $value) !== 1) {
				$errors[] = sprintf('Field "%s" does not match the required format.', $key);
			}
		}

		return $errors;
	}
}
