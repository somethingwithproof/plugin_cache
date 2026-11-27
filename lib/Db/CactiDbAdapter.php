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

namespace Somethingwithproof\Cache\Db;

/**
 * Production database adapter backed by Cacti 1.2's prepared statement APIs.
 */
final class CactiDbAdapter implements DbAdapter {
	/**
	 * Cached row count from the most recent write statement.
	 */
	private int $lastAffectedRows = 0;

	/**
	 * Construct an adapter bound to an optional Cacti PDO connection.
	 *
	 * @param \PDO|false $connection Cacti PDO connection or false for the default session.
	 */
	public function __construct(private readonly \PDO|false $connection = false) {
	}

	/**
	 * Execute a write statement using Cacti's db_execute_prepared().
	 *
	 * @param string $sql Parameterized SQL query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return bool
	 */
	public function execute(string $sql, array $params = []): bool {
		$ok = (bool) db_execute_prepared($sql, $params, true, $this->connection);

		if ($ok && function_exists('db_affected_rows')) {
			/** @var mixed $rows */
			$rows                   = db_affected_rows($this->connection);
			$this->lastAffectedRows = is_numeric($rows) ? (int) $rows : 0;
		} else {
			$this->lastAffectedRows = 0;
		}

		return $ok;
	}

	/**
	 * Fetch a single column value using Cacti's db_fetch_cell_prepared().
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return mixed
	 */
	public function fetchCell(string $sql, array $params = []): mixed {
		return db_fetch_cell_prepared($sql, $params, '', true, $this->connection);
	}

	/**
	 * Fetch a single associative row using Cacti's db_fetch_row_prepared().
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return array<string, mixed>|null
	 */
	public function fetchRow(string $sql, array $params = []): ?array {
		$row = db_fetch_row_prepared($sql, $params, true, $this->connection);

		if (!is_array($row) || $row === []) {
			return null;
		}

		$normalized = [];

		foreach ($row as $k => $v) {
			$normalized[(string) $k] = $v;
		}

		return $normalized;
	}

	/**
	 * Fetch all matching associative rows using Cacti's db_fetch_assoc_prepared().
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return list<array<string, mixed>>
	 */
	public function fetchAssoc(string $sql, array $params = []): array {
		$rows = db_fetch_assoc_prepared($sql, $params, true, $this->connection);

		if (!is_array($rows)) {
			return [];
		}

		$result = [];

		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}

			$normalized = [];

			foreach ($row as $k => $v) {
				$normalized[(string) $k] = $v;
			}

			$result[] = $normalized;
		}

		return $result;
	}

	/**
	 * Return the last insert ID from Cacti's db_fetch_insert_id().
	 *
	 * @return int
	 */
	public function lastInsertId(): int {
		$id = db_fetch_insert_id($this->connection);

		return is_numeric($id) ? (int) $id : 0;
	}

	/**
	 * Return affected rows from the last execute() call.
	 *
	 * @return int
	 */
	public function affectedRows(): int {
		return $this->lastAffectedRows;
	}
}
