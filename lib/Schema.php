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

/**
 * Plugin schema migrations, hook registration catalog, and uninstall table control.
 */
final class Schema {
	/**
	 * Current database schema version.
	 */
	public const VERSION = 1;

	/**
	 * Cacti plugin hooks registered by plugin_cache.
	 */
	public const HOOKS = [
		'auth_alternate_realms'      => 'cache_auth_alternate_realms',
		'config_arrays'              => 'cache_config_arrays',
		'config_settings'            => 'cache_config_settings',
		'draw_navigation_text'       => 'cache_draw_navigation_text',
		'poller_bottom'              => 'cache_poller_bottom',
		'casc_register_providers'    => 'cache_casc_register_providers',
		'doctor_register_checks'     => 'cache_doctor_register_checks',
		'doctor_register_debug_info' => 'cache_doctor_register_debug_info',
		'abilities_register'         => 'cache_abilities_register',
		'api_register_resources'     => 'cache_api_register_resources',
	];

	/**
	 * Cacti plugin directory slug.
	 */
	private const PLUGIN = 'cache';

	/**
	 * Settings table key tracking installed schema version.
	 */
	private const SETTING = 'cache_schema_version';

	/**
	 * Construct a Schema manager bound to an optional database connection.
	 *
	 * @param \PDO|false $connection Cacti PDO connection or false for default session.
	 */
	public function __construct(private readonly \PDO|false $connection = false) {
	}

	/**
	 * Read the currently recorded plugin schema version from Cacti's settings table.
	 *
	 * @return int Recorded schema version, or 0 when not installed.
	 */
	public function storedVersion(): int {
		$value = db_fetch_cell_prepared(
			'SELECT value FROM settings WHERE name = ?',
			[self::SETTING],
			'',
			true,
			$this->connection
		);

		return is_numeric($value) ? (int) $value : 0;
	}

	/**
	 * Determine whether the database schema matches Schema::VERSION.
	 *
	 * @return bool
	 */
	public function isCurrent(): bool {
		return $this->storedVersion() === self::VERSION;
	}

	/**
	 * Create or migrate all plugin_cache tables idempotently.
	 *
	 * @return bool True when schema is current after migration; false on downgrade or error.
	 */
	public function migrate(): bool {
		$from = $this->storedVersion();

		if ($from > self::VERSION) {
			Log::error(sprintf('Schema version %d is newer than plugin code (%d); refusing downgrade', $from, self::VERSION));

			return false;
		}

		foreach (self::tables() as $table => $definition) {
			api_plugin_db_table_create(self::PLUGIN, $table, $definition);

			if (!$this->tableExists($table)) {
				Log::error(sprintf('Unable to create table %s; schema left at version %d', $table, $from));

				return false;
			}
		}

		db_execute_prepared(
			'REPLACE INTO settings (name, value) VALUES (?, ?)',
			[self::SETTING, (string) self::VERSION],
			true,
			$this->connection
		);

		if ($from !== self::VERSION) {
			Log::info(sprintf('Schema migrated from version %d to %d', $from, self::VERSION));
		}

		return true;
	}

	/**
	 * Read the plugin version currently recorded in Cacti's plugin_config table.
	 *
	 * @return string
	 */
	public function recordedPluginVersion(): string {
		$value = db_fetch_cell_prepared(
			'SELECT version FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_scalar($value) ? (string) $value : '';
	}

	/**
	 * Persist plugin metadata into Cacti's plugin_config row.
	 *
	 * @param string $version Plugin version string from INFO.
	 * @param string $author Plugin author string from INFO.
	 * @param string $homepage Plugin homepage URL from INFO.
	 * @return void
	 */
	public function recordPluginVersion(string $version, string $author, string $homepage): void {
		db_execute_prepared(
			'UPDATE plugin_config SET version = ?, author = ?, webpage = ? WHERE directory = ?',
			[$version, $author, $homepage, self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Determine whether plugin_cache is currently enabled in plugin_config.
	 *
	 * @return bool
	 */
	public function pluginEnabled(): bool {
		$status = db_fetch_cell_prepared(
			'SELECT status FROM plugin_config WHERE directory = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_numeric($status) && (int) $status === 1;
	}

	/**
	 * Verify that all declared plugin hooks and RBAC realms are registered.
	 *
	 * @return bool
	 */
	public function hooksRegistered(): bool {
		$placeholders = implode(', ', array_fill(0, count(self::HOOKS), '?'));
		$hooks        = db_fetch_cell_prepared(
			"SELECT COUNT(DISTINCT hook) FROM plugin_hooks WHERE name = ? AND hook IN ($placeholders)",
			array_merge([self::PLUGIN], array_keys(self::HOOKS)),
			'',
			true,
			$this->connection
		);
		$realms = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM plugin_realms WHERE plugin = ?',
			[self::PLUGIN],
			'',
			true,
			$this->connection
		);

		return is_numeric($hooks)
			&& (int) $hooks === count(self::HOOKS)
			&& is_numeric($realms)
			&& (int) $realms === count(Pages::realms());
	}

	/**
	 * Detach plugin tables from plugin_db_changes so Cacti uninstall preserves them.
	 *
	 * @return void
	 */
	public function untrackTables(): void {
		db_execute_prepared(
			"DELETE FROM plugin_db_changes WHERE plugin = ? AND method = 'create'",
			[self::PLUGIN],
			true,
			$this->connection
		);
	}

	/**
	 * Remove the recorded schema version setting during uninstall.
	 *
	 * @return void
	 */
	public function forgetVersion(): void {
		db_execute_prepared(
			'DELETE FROM settings WHERE name = ?',
			[self::SETTING],
			true,
			$this->connection
		);
	}

	/**
	 * Check whether a plugin table exists in the active MariaDB schema.
	 *
	 * @param string $table Unquoted table name.
	 * @return bool
	 */
	private function tableExists(string $table): bool {
		/** @var mixed $count */
		$count = db_fetch_cell_prepared(
			'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
			[$table],
			'',
			true,
			$this->connection
		);

		return is_numeric($count) && (int) $count > 0;
	}

	/**
	 * Return Cacti api_plugin_db_table_create() definitions for all plugin_cache State tables.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function tables(): array {
		return [
			'plugin_cache_entries' => [
				'columns' => [
					['name' => 'cache_id', 'type' => 'char(64)', 'NULL' => false, 'default' => ''],
					['name' => 'cache_group', 'type' => 'varchar(64)', 'NULL' => false, 'default' => 'default'],
					['name' => 'cache_key', 'type' => 'varchar(191)', 'NULL' => false, 'default' => ''],
					['name' => 'payload_json', 'type' => 'mediumtext', 'NULL' => false],
					['name' => 'tags_json', 'type' => 'text', 'NULL' => false],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'expires_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'payload_bytes', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'cache_id',
				'unique_keys' => [
					['name' => 'uniq_group_key', 'columns' => ['cache_group', 'cache_key']],
				],
				'keys' => [
					['name' => 'idx_cache_group', 'columns' => 'cache_group'],
					['name' => 'idx_expires_at', 'columns' => 'expires_at'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Object cache and transient JSON entries (State, never exported)',
			],
			'plugin_cache_tags' => [
				'columns' => [
					['name' => 'tag', 'type' => 'varchar(128)', 'NULL' => false, 'default' => ''],
					['name' => 'invalidations', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary' => 'tag',
				'keys'    => [
					['name' => 'idx_updated_at', 'columns' => 'updated_at'],
				],
				'type'    => 'InnoDB',
				'comment' => 'Monotonic Drupal-style cache tag invalidation counters',
			],
			'plugin_cache_queue' => [
				'columns' => [
					['name' => 'id', 'type' => 'bigint(20)', 'unsigned' => true, 'NULL' => false, 'auto_increment' => true],
					['name' => 'queue_name', 'type' => 'varchar(64)', 'NULL' => false, 'default' => 'default'],
					['name' => 'payload_json', 'type' => 'mediumtext', 'NULL' => false],
					['name' => 'status', 'type' => 'varchar(16)', 'NULL' => false, 'default' => 'pending'],
					['name' => 'available_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'lease_until', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'lease_token', 'type' => 'char(32)', 'NULL' => false, 'default' => ''],
					['name' => 'attempts', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'max_attempts', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '5'],
					['name' => 'cursor_json', 'type' => 'mediumtext', 'NULL' => true],
					['name' => 'idempotency_key', 'type' => 'varchar(128)', 'NULL' => true],
					['name' => 'last_error', 'type' => 'varchar(512)', 'NULL' => false, 'default' => ''],
					['name' => 'created_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
					['name' => 'updated_at', 'type' => 'int(10)', 'unsigned' => true, 'NULL' => false, 'default' => '0'],
				],
				'primary'     => 'id',
				'unique_keys' => [
					['name' => 'uniq_queue_idempotency', 'columns' => ['queue_name', 'idempotency_key']],
				],
				'keys' => [
					['name' => 'idx_queue_claim', 'columns' => ['queue_name', 'status', 'available_at', 'lease_until']],
					['name' => 'idx_status_lease', 'columns' => ['status', 'lease_until']],
				],
				'type'    => 'InnoDB',
				'comment' => 'Resumable batch queue items with leases, backoff, and cursors',
			],
		];
	}
}
