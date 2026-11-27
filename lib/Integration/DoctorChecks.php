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
	 * Expired entry threshold triggering a bloat warning check result.
	 */
	private const EXPIRED_WARNING_THRESHOLD = 1000;

	/**
	 * Dead-letter item threshold escalating queue health from warning to critical.
	 */
	private const DEAD_LETTER_CRITICAL_THRESHOLD = 50;

	/**
	 * Build structured diagnostic checks compatible with plugin_doctor's
	 * doctor_register_checks hook.
	 *
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @param callable(): QueueService $queueFactory Lazy QueueService provider.
	 * @return list<array{id: string, name: string, category: string, run: callable(): array{status: string, message: string, details: array<string, mixed>}}>
	 */
	public static function buildChecks(callable $cacheFactory, callable $queueFactory): array {
		return [
			[
				'id'       => 'cache_driver_connectivity',
				'name'     => 'Object Cache Driver Connectivity',
				'category' => 'cache',
				'run'      => static function () use ($cacheFactory): array {
					try {
						$service = $cacheFactory();
						$healthy = $service->driver()->ping();
						$driver  = $service->driver()->name();

						if (!$healthy) {
							return [
								'status'  => 'critical',
								'message' => sprintf('Cache driver "%s" failed connectivity probe.', $driver),
								'details' => ['driver' => $driver, 'healthy' => false],
							];
						}

						return [
							'status'  => 'ok',
							'message' => sprintf('Cache driver "%s" is reachable and healthy.', $driver),
							'details' => ['driver' => $driver, 'healthy' => true],
						];
					} catch (\Throwable $error) {
						return [
							'status'  => 'critical',
							'message' => 'Cache driver probe threw an exception: ' . $error->getMessage(),
							'details' => ['exception' => $error::class],
						];
					}
				},
			],
			[
				'id'       => 'cache_expired_bloat',
				'name'     => 'Object Cache Expired Entry Bloat',
				'category' => 'cache',
				'run'      => static function () use ($cacheFactory): array {
					$stats   = $cacheFactory()->stats();
					$entries = $stats['entries'];
					$expired = $stats['expired'];
					$total   = $entries + $expired;
					$ratio   = $total > 0 ? $expired / $total : 0.0;

					if ($expired >= self::EXPIRED_WARNING_THRESHOLD || ($total >= 100 && $ratio >= 0.25)) {
						return [
							'status'  => 'warning',
							'message' => sprintf('Cache contains %d expired entries (%.1f%% of stored rows); run prune.', $expired, $ratio * 100.0),
							'details' => ['entries' => $entries, 'expired' => $expired, 'expiredRatio' => round($ratio, 4)],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('Cache has %d active and %d expired entries.', $entries, $expired),
						'details' => ['entries' => $entries, 'expired' => $expired, 'expiredRatio' => round($ratio, 4)],
					];
				},
			],
			[
				'id'       => 'cache_queue_health',
				'name'     => 'Batch Queue Stuck Leases & Dead Letters',
				'category' => 'cache',
				'run'      => static function () use ($queueFactory): array {
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
						return [
							'status'  => 'critical',
							'message' => sprintf('Batch queues have %d dead-letter items and %d expired leases.', $dead, $expiredLeases),
							'details' => ['dead' => $dead, 'expiredLeases' => $expiredLeases, 'pending' => $pending, 'queues' => $queues],
						];
					}

					if ($dead > 0 || $expiredLeases > 0) {
						return [
							'status'  => 'warning',
							'message' => sprintf('Batch queues require attention: %d dead-letter item(s), %d expired lease(s).', $dead, $expiredLeases),
							'details' => ['dead' => $dead, 'expiredLeases' => $expiredLeases, 'pending' => $pending, 'queues' => $queues],
						];
					}

					return [
						'status'  => 'ok',
						'message' => sprintf('%d queue(s) healthy (%d pending, 0 dead-letter, 0 expired leases).', count($queues), $pending),
						'details' => ['dead' => 0, 'expiredLeases' => 0, 'pending' => $pending, 'queues' => $queues],
					];
				},
			],
		];
	}

	/**
	 * Build a structured debug info section compatible with plugin_doctor's
	 * doctor_register_debug_info hook (zero secrets, zero raw payloads).
	 *
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @param callable(): QueueService $queueFactory Lazy QueueService provider.
	 * @param callable(): Settings $settingsProvider Lazy Settings provider.
	 * @return array<string, mixed>
	 */
	public static function buildDebugInfo(
		callable $cacheFactory,
		callable $queueFactory,
		callable $settingsProvider,
	): array {
		$settings = $settingsProvider();
		$stats    = $cacheFactory()->stats();
		$queues   = $queueFactory()->status();

		return [
			'label'  => 'Object Cache & Batch Queue (plugin_cache)',
			'fields' => [
				'driver'            => $stats['driver'],
				'healthy'           => $stats['healthy'],
				'default_ttl'       => $settings->defaultTtl,
				'max_payload_bytes' => $settings->maxPayloadBytes,
				'xfetch_beta'       => $settings->xfetchBeta,
				'memory_capacity'   => $settings->memoryCapacity,
				'redis_tls'         => $settings->redisTls,
				'redis_secret_ref'  => $settings->redisSecretRef !== '' ? 'configured' : 'none',
				'active_entries'    => $stats['entries'],
				'expired_entries'   => $stats['expired'],
				'stored_bytes'      => $stats['bytes'],
				'group_count'       => count($stats['groups']),
				'queue_count'       => count($queues),
				'queues'            => $queues,
			],
		];
	}
}
