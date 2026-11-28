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

namespace Somethingwithproof\Cache\Integration;

use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Queue\QueueService;
use Somethingwithproof\Cache\Settings;

/**
 * Diagnostic checks and debug info exposed to plugin_doctor via
 * doctor_register_checks and doctor_register_debug_info hooks.
 */
final class DoctorChecks {
	/**
	 * Plugin slug reported in diagnostic descriptors and debug info sections.
	 */
	private const PLUGIN = 'cache';

	/**
	 * Diagnostic check category displayed in plugin_doctor reports.
	 */
	private const CATEGORY = 'Cache';

	/**
	 * Expired entry threshold triggering a bloat warning check result.
	 */
	private const EXPIRED_WARNING_THRESHOLD = 1000;

	/**
	 * Dead-letter item threshold escalating queue health from warn to fail.
	 */
	private const DEAD_LETTER_CRITICAL_THRESHOLD = 50;

	/**
	 * Evaluate or list structured diagnostic checks compatible with plugin_doctor's
	 * doctor_register_checks contract.
	 *
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @param callable(): QueueService $queueFactory Lazy QueueService provider.
	 * @param array<string, mixed>     $context      Optional hook context containing mode ('run'|'list').
	 * @return list<array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}>
	 */
	public static function buildChecks(callable $cacheFactory, callable $queueFactory, array $context = []): array {
		$mode = is_string($context['mode'] ?? null) ? strtolower(trim($context['mode'])) : 'run';

		if ($mode === 'list') {
			return [
				self::descriptor(
					'cache.driver_connectivity',
					'Object Cache Driver Connectivity',
					'info',
					'Probes the active cache backend driver (MariaDB, APCu, Redis, or Memory) for reachability.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'cache.expired_bloat',
					'Object Cache Expired Entry Bloat',
					'info',
					'Checks active versus expired row counts in the object cache backend.',
					['mode' => 'list'],
					''
				),
				self::descriptor(
					'cache.queue_health',
					'Batch Queue Stuck Leases & Dead Letters',
					'info',
					'Inspects resumable batch queues for dead-letter items and expired worker leases.',
					['mode' => 'list'],
					''
				),
			];
		}

		return [
			self::checkDriverConnectivity($cacheFactory),
			self::checkExpiredBloat($cacheFactory),
			self::checkQueueHealth($queueFactory),
		];
	}

	/**
	 * Build a structured debug info section list compatible with plugin_doctor's
	 * doctor_register_debug_info hook (zero secrets, zero raw payloads).
	 *
	 * @param callable(): CacheService $cacheFactory     Lazy CacheService provider.
	 * @param callable(): QueueService $queueFactory     Lazy QueueService provider.
	 * @param callable(): Settings     $settingsProvider Lazy Settings provider.
	 * @param string                   $version          Plugin version string from INFO.
	 * @return list<array{id: string, plugin: string, label: string, description: string, private: bool, fields: array<string, array{label: string, value: bool|float|int|string}>}>
	 */
	public static function buildDebugInfo(
		callable $cacheFactory,
		callable $queueFactory,
		callable $settingsProvider,
		string $version = '0.1.0',
	): array {
		$settings   = $settingsProvider();
		$stats      = $cacheFactory()->stats();
		$queues     = $queueFactory()->status();
		$queueNames = array_keys($queues);
		sort($queueNames, SORT_STRING);

		$pendingTotal = 0;
		$deadTotal    = 0;

		foreach ($queues as $counts) {
			$pendingTotal += $counts['pending'];
			$deadTotal    += $counts['dead'];
		}

		return [
			[
				'id'          => 'cache.overview',
				'plugin'      => self::PLUGIN,
				'label'       => 'Object Cache & Batch Queue (plugin_cache)',
				'description' => 'Active object cache backend health, TTL/XFetch configuration, and batch queue totals (zero secrets or payloads).',
				'private'     => false,
				'fields'      => [
					'version' => [
						'label' => 'Plugin Version',
						'value' => $version !== '' ? $version : '0.1.0',
					],
					'driver' => [
						'label' => 'Cache Driver',
						'value' => $stats['driver'],
					],
					'healthy' => [
						'label' => 'Driver Healthy',
						'value' => $stats['healthy'],
					],
					'default_ttl' => [
						'label' => 'Default TTL (seconds)',
						'value' => $settings->defaultTtl,
					],
					'max_payload_bytes' => [
						'label' => 'Max Payload Bytes',
						'value' => $settings->maxPayloadBytes,
					],
					'xfetch_beta' => [
						'label' => 'XFetch Beta',
						'value' => $settings->xfetchBeta,
					],
					'memory_capacity' => [
						'label' => 'Memory Capacity',
						'value' => $settings->memoryCapacity,
					],
					'redis_tls' => [
						'label' => 'Redis TLS Enabled',
						'value' => $settings->redisTls,
					],
					'redis_secret_ref' => [
						'label' => 'Redis Secret Reference',
						'value' => $settings->redisSecretRef !== '' ? 'configured' : 'none',
					],
					'active_entries' => [
						'label' => 'Active Entries',
						'value' => $stats['entries'],
					],
					'expired_entries' => [
						'label' => 'Expired Entries',
						'value' => $stats['expired'],
					],
					'stored_bytes' => [
						'label' => 'Stored Bytes',
						'value' => $stats['bytes'],
					],
					'group_count' => [
						'label' => 'Cache Group Count',
						'value' => count($stats['groups']),
					],
					'queue_count' => [
						'label' => 'Batch Queue Count',
						'value' => count($queues),
					],
					'queue_pending' => [
						'label' => 'Queue Pending Items',
						'value' => $pendingTotal,
					],
					'queue_dead' => [
						'label' => 'Queue Dead-Letter Items',
						'value' => $deadTotal,
					],
					'queue_names' => [
						'label' => 'Active Queues',
						'value' => $queueNames !== [] ? implode(', ', $queueNames) : 'none',
					],
				],
			],
		];
	}

	/**
	 * Probe active object cache driver connectivity.
	 *
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkDriverConnectivity(callable $cacheFactory): array {
		try {
			$service = $cacheFactory();
			$healthy = $service->driver()->ping();
			$driver  = $service->driver()->name();

			if (!$healthy) {
				return self::descriptor(
					'cache.driver_connectivity',
					'Object Cache Driver Connectivity',
					'fail',
					sprintf('Cache driver "%s" failed connectivity probe.', $driver),
					['driver' => $driver, 'healthy' => false],
					'Check cache backend connectivity and credentials under Console -> Configuration -> Settings -> Cache.'
				);
			}

			return self::descriptor(
				'cache.driver_connectivity',
				'Object Cache Driver Connectivity',
				'ok',
				sprintf('Cache driver "%s" is reachable and healthy.', $driver),
				['driver' => $driver, 'healthy' => true],
				''
			);
		} catch (\Throwable $error) {
			return self::descriptor(
				'cache.driver_connectivity',
				'Object Cache Driver Connectivity',
				'fail',
				'Cache driver probe threw an exception: ' . $error->getMessage(),
				['exception' => $error::class],
				'Inspect Cacti logs under CACHE and verify backend service availability.'
			);
		}
	}

	/**
	 * Check for excessive expired object cache rows requiring pruning.
	 *
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkExpiredBloat(callable $cacheFactory): array {
		try {
			$stats   = $cacheFactory()->stats();
			$entries = $stats['entries'];
			$expired = $stats['expired'];
			$total   = $entries + $expired;
			$ratio   = $total > 0 ? $expired / $total : 0.0;

			if ($expired >= self::EXPIRED_WARNING_THRESHOLD || ($total >= 100 && $ratio >= 0.25)) {
				return self::descriptor(
					'cache.expired_bloat',
					'Object Cache Expired Entry Bloat',
					'warn',
					sprintf('Cache contains %d expired entries (%.1f%% of stored rows); run prune.', $expired, $ratio * 100.0),
					['entries' => $entries, 'expired' => $expired, 'expiredRatio' => round($ratio, 4)],
					'Run cli/cache.php prune or confirm the Cacti poller_bottom maintenance hook is executing.'
				);
			}

			return self::descriptor(
				'cache.expired_bloat',
				'Object Cache Expired Entry Bloat',
				'ok',
				sprintf('Cache has %d active and %d expired entries.', $entries, $expired),
				['entries' => $entries, 'expired' => $expired, 'expiredRatio' => round($ratio, 4)],
				''
			);
		} catch (\Throwable $error) {
			return self::descriptor(
				'cache.expired_bloat',
				'Object Cache Expired Entry Bloat',
				'fail',
				'Cache stats query threw an exception: ' . $error->getMessage(),
				['exception' => $error::class],
				'Inspect Cacti logs under CACHE and verify backend service availability.'
			);
		}
	}

	/**
	 * Check resumable batch queues for dead-letter items and expired worker leases.
	 *
	 * @param callable(): QueueService $queueFactory Lazy QueueService provider.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function checkQueueHealth(callable $queueFactory): array {
		try {
			$queues        = $queueFactory()->status();
			$expiredLeases = 0;
			$dead          = 0;
			$pending       = 0;

			foreach ($queues as $counts) {
				$expiredLeases += $counts['expiredLeases'];
				$dead          += $counts['dead'];
				$pending       += $counts['pending'];
			}

			if ($dead >= self::DEAD_LETTER_CRITICAL_THRESHOLD) {
				return self::descriptor(
					'cache.queue_health',
					'Batch Queue Stuck Leases & Dead Letters',
					'fail',
					sprintf('Batch queues have %d dead-letter items and %d expired leases.', $dead, $expiredLeases),
					['dead' => $dead, 'expiredLeases' => $expiredLeases, 'pending' => $pending, 'queues' => $queues],
					'Inspect failing queue workers via cli/cache.php queue-status and drain or purge dead-letter items.'
				);
			}

			if ($dead > 0 || $expiredLeases > 0) {
				return self::descriptor(
					'cache.queue_health',
					'Batch Queue Stuck Leases & Dead Letters',
					'warn',
					sprintf('Batch queues require attention: %d dead-letter item(s), %d expired lease(s).', $dead, $expiredLeases),
					['dead' => $dead, 'expiredLeases' => $expiredLeases, 'pending' => $pending, 'queues' => $queues],
					'Run cli/cache.php queue-status to inspect affected queues and reclaim expired leases.'
				);
			}

			return self::descriptor(
				'cache.queue_health',
				'Batch Queue Stuck Leases & Dead Letters',
				'ok',
				sprintf('%d queue(s) healthy (%d pending, 0 dead-letter, 0 expired leases).', count($queues), $pending),
				['dead' => 0, 'expiredLeases' => 0, 'pending' => $pending, 'queues' => $queues],
				''
			);
		} catch (\Throwable $error) {
			return self::descriptor(
				'cache.queue_health',
				'Batch Queue Stuck Leases & Dead Letters',
				'fail',
				'Queue status query threw an exception: ' . $error->getMessage(),
				['exception' => $error::class],
				'Inspect Cacti logs under CACHE and verify plugin_cache_queue table health.'
			);
		}
	}

	/**
	 * Construct a contract-compliant plugin_doctor check descriptor with bounded string lengths.
	 *
	 * @param string               $id          Dot-prefixed check identifier (cache.*).
	 * @param string               $title       Human-readable check title.
	 * @param string               $status      Allowed status enum (ok|warn|fail|info).
	 * @param string               $message     Summary message.
	 * @param array<string, mixed> $details     Structured diagnostic details.
	 * @param string               $remediation Actionable remediation text.
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: array<string, mixed>, remediation: string}
	 */
	private static function descriptor(
		string $id,
		string $title,
		string $status,
		string $message,
		array $details,
		string $remediation,
	): array {
		return [
			'id'          => $id,
			'plugin'      => self::PLUGIN,
			'category'    => self::CATEGORY,
			'title'       => substr($title, 0, 256),
			'status'      => $status,
			'message'     => substr($message !== '' ? $message : $title, 0, 2048),
			'details'     => $details,
			'remediation' => substr($remediation, 0, 2048),
		];
	}
}
