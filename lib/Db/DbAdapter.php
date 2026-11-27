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
 * Minimal prepared-statement database adapter decoupling MariaDB storage from
 * Cacti's global db_*_prepared() functions for unit and integration testing.
 */
interface DbAdapter {
	/**
	 * Execute a write statement (INSERT, UPDATE, DELETE, REPLACE) with bound parameters.
	 *
	 * @param string $sql Parameterized SQL query with positional ? placeholders.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return bool True on success, false on database error.
	 */
	public function execute(string $sql, array $params = []): bool;

	/**
	 * Fetch a single scalar column from the first matching row.
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return mixed Scalar cell value, or false/null when no row matches.
	 */
	public function fetchCell(string $sql, array $params = []): mixed;

	/**
	 * Fetch the first matching row as an associative array.
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return array<string, mixed>|null Associative row, or null when no row matches.
	 */
	public function fetchRow(string $sql, array $params = []): ?array;

	/**
	 * Fetch all matching rows as a list of associative arrays.
	 *
	 * @param string $sql Parameterized SQL SELECT query.
	 * @param list<bool|float|int|string|null> $params Positional parameter bindings.
	 * @return list<array<string, mixed>>
	 */
	public function fetchAssoc(string $sql, array $params = []): array;

	/**
	 * Return the last auto-increment insert ID generated on the connection.
	 *
	 * @return int
	 */
	public function lastInsertId(): int;

	/**
	 * Return the number of rows affected by the most recent execute() call.
	 *
	 * @return int
	 */
	public function affectedRows(): int;
}
