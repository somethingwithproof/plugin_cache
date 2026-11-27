<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cache, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cache
 */

/**
 * PHPStan stubs for optional runtime extensions (ext-apcu) and soft-coupled
 * sibling plugin helpers (cacti_vault_get) that are not required at boot.
 */

/**
 * @return bool
 */
function apcu_enabled(): bool {
	return false;
}

/**
 * @param string $key
 * @param bool $success
 * @return mixed
 */
function apcu_fetch(string $key, bool &$success = false): mixed {
	return false;
}

/**
 * @param string $key
 * @param mixed $var
 * @param int $ttl
 * @return bool
 */
function apcu_store(string $key, mixed $var, int $ttl = 0): bool {
	return false;
}

/**
 * @param string $key
 * @param mixed $var
 * @param int $ttl
 * @return bool
 */
function apcu_add(string $key, mixed $var, int $ttl = 0): bool {
	return false;
}

/**
 * @param string $key
 * @return bool
 */
function apcu_delete(string $key): bool {
	return false;
}

/**
 * @param string $key
 * @param int $step
 * @param bool $success
 * @param int $ttl
 * @return int|false
 */
function apcu_inc(string $key, int $step = 1, bool &$success = false, int $ttl = 0): int|false {
	return false;
}

/**
 * @param bool $limited
 * @return array<string, mixed>|false
 */
function apcu_cache_info(bool $limited = false): array|false {
	return false;
}

/**
 * Optional soft-coupled secret lookup provided when a vault plugin is enabled.
 *
 * @param string $plugin
 * @param string $slot
 * @return string|null
 */
function cacti_vault_get(string $plugin, string $slot): ?string {
	return null;
}
