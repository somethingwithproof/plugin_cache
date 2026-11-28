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

namespace Somethingwithproof\Cache\Metrics;

use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Queue\QueueService;

/**
 * Collects safe, non-secret operational cache and queue telemetry for Cacti
 * self-graphing via `scripts/ss_cache_stats.php` in `field:value` format.
 */
final class CacheMetricsCollector {
	/**
	 * Ordered list of Cacti RRDtool data source fields emitted by this collector.
	 *
	 * @var list<string>
	 */
	public const FIELDS = [
		'hits',
		'misses',
		'hit_ratio_pct',
		'evictions',
		'xfetch_refreshes',
		'entries_count',
		'queue_pending',
		'queue_leased',
		'queue_dead_letter',
		'memory_bytes',
	];

	/**
	 * Active cache service instance.
	 */
	private readonly CacheService $cacheService;

	/**
	 * Active resumable queue service instance.
	 */
	private readonly QueueService $queueService;

	/**
	 * Construct a metrics collector for plugin_cache.
	 *
	 * @param CacheService $cacheService Active cache service.
	 * @param QueueService $queueService Active queue service.
	 */
	public function __construct(CacheService $cacheService, QueueService $queueService) {
		$this->cacheService = $cacheService;
		$this->queueService = $queueService;
	}

	/**
	 * Collect all numeric cache and queue telemetry fields without exposing secrets.
	 *
	 * @return array{
	 *   healthy: bool,
	 *   hits: int,
	 *   misses: int,
	 *   hit_ratio_pct: float,
	 *   evictions: int,
	 *   xfetch_refreshes: int,
	 *   entries_count: int,
	 *   queue_pending: int,
	 *   queue_leased: int,
	 *   queue_dead_letter: int,
	 *   memory_bytes: int
	 * }
	 */
	public function collect(): array {
		$stats  = $this->cacheService->stats();
		$queues = $this->queueService->status();

		$queuePending    = 0;
		$queueLeased     = 0;
		$queueDeadLetter = 0;

		foreach ($queues as $q) {
			$queuePending    += $q['pending'];
			$queueLeased     += $q['leased'];
			$queueDeadLetter += $q['dead'];
		}

		$driverEvictions = is_int($stats['details']['evictions'] ?? null)
			? $stats['details']['evictions']
			: 0;
		$totalEvictions = max(0, $stats['evictions'] + $driverEvictions);
		$hitRatioPct    = round(max(0.0, min(100.0, $stats['hitRatio'] * 100.0)), 2);

		return [
			'healthy'           => $stats['healthy'],
			'hits'              => max(0, $stats['hits']),
			'misses'            => max(0, $stats['misses']),
			'hit_ratio_pct'     => $hitRatioPct,
			'evictions'         => $totalEvictions,
			'xfetch_refreshes'  => max(0, $stats['earlyRecomputes']),
			'entries_count'     => max(0, $stats['entries']),
			'queue_pending'     => max(0, $queuePending),
			'queue_leased'      => max(0, $queueLeased),
			'queue_dead_letter' => max(0, $queueDeadLetter),
			'memory_bytes'      => max(0, $stats['bytes']),
		];
	}

	/**
	 * Collect and render telemetry in Cacti script-server `field:value` format,
	 * or return a single metric value when `$metric` is provided.
	 *
	 * Emits RRDtool unknown (`U`) values when the backend is unhealthy or when
	 * collection throws so graphs display gaps instead of false zeros.
	 *
	 * @param string $metric Optional single metric field name from self::FIELDS.
	 * @return string Cacti script-server output line.
	 */
	public function render(string $metric = ''): string {
		try {
			$metrics = $this->collect();
		} catch (\Throwable) {
			return self::unknownOutput($metric);
		}

		if (!$metrics['healthy']) {
			return self::unknownOutput($metric);
		}

		if ($metric !== '') {
			if (!in_array($metric, self::FIELDS, true)) {
				return 'U';
			}

			return self::formatMetricValue($metric, $metrics[$metric]);
		}

		return self::formatCactiOutput($metrics);
	}

	/**
	 * Format a collected metric array into a space-delimited Cacti `field:value` line.
	 *
	 * @param array{
	 *   healthy?: bool,
	 *   hits: int,
	 *   misses: int,
	 *   hit_ratio_pct: float,
	 *   evictions: int,
	 *   xfetch_refreshes: int,
	 *   entries_count: int,
	 *   queue_pending: int,
	 *   queue_leased: int,
	 *   queue_dead_letter: int,
	 *   memory_bytes: int
	 * } $metrics Collected metrics map.
	 * @return string Space-delimited `field:value` string.
	 */
	public static function formatCactiOutput(array $metrics): string {
		$parts = [];

		foreach (self::FIELDS as $field) {
			$value   = $metrics[$field] ?? 0;
			$parts[] = $field . ':' . self::formatMetricValue($field, $value);
		}

		return implode(' ', $parts);
	}

	/**
	 * Build an RRDtool unknown (`U`) output line for error or unhealthy backend paths.
	 *
	 * @param string $metric Optional single metric field name.
	 * @return string Either `'U'` or space-delimited `field:U` pairs.
	 */
	public static function unknownOutput(string $metric = ''): string {
		if ($metric !== '') {
			return 'U';
		}

		$parts = [];

		foreach (self::FIELDS as $field) {
			$parts[] = $field . ':U';
		}

		return implode(' ', $parts);
	}

	/**
	 * Format a single metric value consistently for Cacti RRDtool ingestion.
	 *
	 * @param string $field Metric name.
	 * @param int|float $value Numeric metric value.
	 * @return string Formatted numeric token.
	 */
	private static function formatMetricValue(string $field, int|float $value): string {
		if ($field === 'hit_ratio_pct') {
			return sprintf('%.2f', max(0.0, min(100.0, (float) $value)));
		}

		return (string) max(0, (int) $value);
	}
}
