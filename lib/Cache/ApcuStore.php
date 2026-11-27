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
 * Thin APCu primitive interface enabling deterministic testing when ext-apcu is absent.
 */
interface ApcuStore {
	/**
	 * Return true when APCu is enabled in the current PHP SAPI.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool;

	/**
	 * Fetch a value from APCu.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param bool $success Set to true on hit, false on miss.
	 * @return mixed
	 */
	public function fetch(string $key, bool &$success): mixed;

	/**
	 * Store a value in APCu with optional TTL.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param mixed $value Scalar or string payload.
	 * @param int $ttl Expiration in seconds (0 = until evicted).
	 * @return bool
	 */
	public function store(string $key, mixed $value, int $ttl = 0): bool;

	/**
	 * Atomically store a value only if the key does not currently exist.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param mixed $value Lock token or initial value.
	 * @param int $ttl Expiration in seconds.
	 * @return bool
	 */
	public function add(string $key, mixed $value, int $ttl = 0): bool;

	/**
	 * Delete a key from APCu.
	 *
	 * @param string $key Namespaced APCu key.
	 * @return bool
	 */
	public function delete(string $key): bool;

	/**
	 * Atomically increment an integer key by step.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param int $step Increment step.
	 * @return int|false New integer value or false on failure.
	 */
	public function inc(string $key, int $step = 1): int|false;

	/**
	 * Return APCu cache summary info when available.
	 *
	 * @return array<string, mixed>
	 */
	public function info(): array;
}
