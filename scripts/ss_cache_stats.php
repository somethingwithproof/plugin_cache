#!/usr/bin/env php
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

/*
 * Cacti Script Server entry point for plugin_cache operational telemetry.
 * Kept strictly PHP 8.0 parseable so Cacti script_server.php never encounters
 * a syntax error on PHP 8.0/8.1 hosts; returns RRDtool unknown ('U') on
 * unsupported PHP runtimes, unhealthy backends, or collection errors.
 */

error_reporting(0);

if (!isset($called_by_script_server)) {
	if (PHP_SAPI !== 'cli') {
		exit(1);
	}

	$dir = __DIR__;
	chdir($dir . '/../../..');

	include_once('./include/cli_check.php');
	include_once($config['base_path'] . '/plugins/cache/setup.php');

	$metric = isset($argv[1]) && is_string($argv[1]) ? trim($argv[1]) : '';
	print call_user_func('ss_cache_stats', $metric) . PHP_EOL;
}

/**
 * Return plugin_cache operational telemetry in Cacti `field:value` format,
 * or a single numeric metric value when `$metric` is supplied.
 *
 * @param string $metric Optional single metric field name.
 * @return string Space-delimited `field:value` line or single metric / 'U'.
 */
function ss_cache_stats(string $metric = ''): string {
	$unknown = $metric !== ''
		? 'U'
		: 'hits:U misses:U hit_ratio_pct:U evictions:U xfetch_refreshes:U entries_count:U queue_pending:U queue_leased:U queue_dead_letter:U memory_bytes:U';

	$setupPath = __DIR__ . '/../setup.php';

	if (!function_exists('cache_boot') && is_file($setupPath)) {
		require_once($setupPath);
	}

	if (!function_exists('cache_boot') || !cache_boot()) {
		return $unknown;
	}

	try {
		$collector = new \Somethingwithproof\Cache\Metrics\CacheMetricsCollector(
			cache_service(),
			cache_queue_service()
		);

		return $collector->render(trim($metric));
	} catch (\Throwable $error) {
		return $unknown;
	}
}
