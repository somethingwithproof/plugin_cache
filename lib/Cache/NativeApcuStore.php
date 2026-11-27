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
 * Default APCu store delegating directly to PHP's ext-apcu functions.
 */
final class NativeApcuStore implements ApcuStore {
	/**
	 * Check whether ext-apcu is loaded and enabled for the active SAPI.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return function_exists('apcu_enabled') && apcu_enabled();
	}

	/**
	 * Fetch a stored APCu value.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param bool $success Set to true on hit, false on miss.
	 * @return mixed
	 */
	public function fetch(string $key, bool &$success): mixed {
		if (!$this->isEnabled()) {
			$success = false;

			return false;
		}

		return apcu_fetch($key, $success);
	}

	/**
	 * Store a value in APCu.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param mixed $value Value to store.
	 * @param int $ttl Expiration in seconds.
	 * @return bool
	 */
	public function store(string $key, mixed $value, int $ttl = 0): bool {
		if (!$this->isEnabled()) {
			return false;
		}

		return (bool) apcu_store($key, $value, $ttl);
	}

	/**
	 * Atomically add a key only if absent.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param mixed $value Value to store.
	 * @param int $ttl Expiration in seconds.
	 * @return bool
	 */
	public function add(string $key, mixed $value, int $ttl = 0): bool {
		if (!$this->isEnabled()) {
			return false;
		}

		return (bool) apcu_add($key, $value, $ttl);
	}

	/**
	 * Delete a key from APCu.
	 *
	 * @param string $key Namespaced APCu key.
	 * @return bool
	 */
	public function delete(string $key): bool {
		if (!$this->isEnabled()) {
			return false;
		}

		return (bool) apcu_delete($key);
	}

	/**
	 * Atomically increment an integer key in APCu.
	 *
	 * @param string $key Namespaced APCu key.
	 * @param int $step Increment amount.
	 * @return int|false
	 */
	public function inc(string $key, int $step = 1): int|false {
		if (!$this->isEnabled()) {
			return false;
		}

		$result = apcu_inc($key, $step);

		return is_int($result) ? $result : false;
	}

	/**
	 * Retrieve APCu limited cache info.
	 *
	 * @return array<string, mixed>
	 */
	public function info(): array {
		if (!$this->isEnabled() || !function_exists('apcu_cache_info')) {
			return [];
		}

		$info = @apcu_cache_info(true);

		return is_array($info) ? $info : [];
	}
}
