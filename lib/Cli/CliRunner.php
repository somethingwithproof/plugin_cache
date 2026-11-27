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

namespace Somethingwithproof\Cache\Cli;

use Somethingwithproof\Cache\Cache\CacheException;
use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Queue\QueueItem;
use Somethingwithproof\Cache\Queue\QueueService;

/**
 * Command-line dispatcher for cli/cache.php supporting stats, flush,
 * invalidate-tag, prune, queue-status, and queue-run commands.
 */
final class CliRunner {
	/**
	 * Registered queue worker callbacks keyed by queue name.
	 *
	 * @var array<string, callable(QueueItem): (array<string, mixed>|null)>
	 */
	private array $handlers;

	/**
	 * Construct a CLI runner around CacheService and QueueService.
	 *
	 * @param CacheService $cache Active object cache service.
	 * @param QueueService $queue Active resumable batch queue service.
	 * @param array<string, callable(QueueItem): (array<string, mixed>|null)> $handlers Optional custom queue handlers.
	 */
	public function __construct(
		private readonly CacheService $cache,
		private readonly QueueService $queue,
		array $handlers = [],
	) {
		$this->handlers = $handlers;
	}

	/**
	 * Execute a CLI command and return exitCode, stdout, and stderr buffers.
	 *
	 * @param list<string> $argv Arguments after the script path.
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	public function run(array $argv): array {
		if ($argv === [] || in_array($argv[0], ['help', '--help', '-h'], true)) {
			return [
				'exitCode' => 0,
				'stdout'   => self::usage(),
				'stderr'   => '',
			];
		}

		$command = array_shift($argv);
		$parsed  = self::parseArgs($argv);
		$format  = $parsed['options']['format'] ?? 'text';

		if (!in_array($format, ['text', 'json'], true)) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => "Unsupported --format value (expected text or json).\n"];
		}

		try {
			return match ($command) {
				'stats'          => $this->cmdStats($format),
				'flush'          => $this->cmdFlush($parsed['options'], $format),
				'invalidate-tag' => $this->cmdInvalidateTag($parsed['positional'], $format),
				'prune'          => $this->cmdPrune($parsed['options'], $format),
				'queue-status'   => $this->cmdQueueStatus($parsed['options'], $format),
				'queue-run'      => $this->cmdQueueRun($parsed['options'], $format),
				default          => ['exitCode' => 1, 'stdout' => '', 'stderr' => sprintf("Unknown command \"%s\". Run with --help.\n", $command)],
			};
		} catch (CacheException $error) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'ERROR: ' . $error->getMessage() . "\n"];
		}
	}

	/**
	 * Render cache metrics in text or JSON format.
	 *
	 * @param string $format Output format ('text' or 'json').
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdStats(string $format): array {
		$stats = $this->cache->stats();

		if ($format === 'json') {
			return ['exitCode' => 0, 'stdout' => self::toJson($stats), 'stderr' => ''];
		}

		$lines = [
			sprintf('Driver: %s (%s)', $stats['driver'], $stats['healthy'] ? 'healthy' : 'unhealthy'),
			sprintf('Entries: %d active, %d expired (%d bytes)', $stats['entries'], $stats['expired'], $stats['bytes']),
			sprintf('Lookups: %d hits, %d misses (ratio %.4f)', $stats['hits'], $stats['misses'], $stats['hitRatio']),
			sprintf('Operations: %d writes, %d evictions, %d tag invalidations, %d early recomputes', $stats['writes'], $stats['evictions'], $stats['tagInvalidations'], $stats['earlyRecomputes']),
			sprintf('Groups: %d', count($stats['groups'])),
		];

		return ['exitCode' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * Flush a specific cache group or all cache entries.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @param string $format Output format ('text' or 'json').
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdFlush(array $options, string $format): array {
		if (isset($options['group']) && $options['group'] !== '') {
			$group   = $options['group'];
			$removed = $this->cache->flushGroup($group);
			$payload = ['scope' => 'group', 'group' => $group, 'removed' => $removed];

			return [
				'exitCode' => 0,
				'stdout'   => $format === 'json' ? self::toJson($payload) : sprintf("Flushed group \"%s\" (%d entries).\n", $group, $removed),
				'stderr'   => '',
			];
		}

		if (isset($options['all'])) {
			$removed = $this->cache->flushAll();
			$payload = ['scope' => 'all', 'removed' => $removed];

			return [
				'exitCode' => 0,
				'stdout'   => $format === 'json' ? self::toJson($payload) : sprintf("Flushed all cache entries (%d entries).\n", $removed),
				'stderr'   => '',
			];
		}

		return ['exitCode' => 1, 'stdout' => '', 'stderr' => "Specify --group=<name> or --all.\n"];
	}

	/**
	 * Invalidate one or more Drupal-style cache tags.
	 *
	 * @param list<string> $tags Tag arguments.
	 * @param string $format Output format ('text' or 'json').
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdInvalidateTag(array $tags, string $format): array {
		if ($tags === []) {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => "Provide at least one cache tag to invalidate.\n"];
		}

		$count   = $this->cache->invalidateTags($tags);
		$payload = ['invalidated' => $count, 'tags' => $tags];

		return [
			'exitCode' => 0,
			'stdout'   => $format === 'json' ? self::toJson($payload) : sprintf("Invalidated %d cache tag(s).\n", $count),
			'stderr'   => '',
		];
	}

	/**
	 * Prune expired cache entries, reclaim expired queue leases, and purge old completed queue items.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @param string $format Output format ('text' or 'json').
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdPrune(array $options, string $format): array {
		$completedAge = isset($options['completed-age']) && is_numeric($options['completed-age'])
			? max(0, (int) $options['completed-age'])
			: 86400;

		$expiredEntries = $this->cache->pruneExpired();
		$reclaimed      = $this->queue->reclaimExpiredLeases();
		$purged         = $this->queue->purgeCompleted($completedAge);
		$payload        = [
			'expiredEntriesPruned' => $expiredEntries,
			'expiredLeasesReclaimed' => $reclaimed,
			'completedItemsPurged' => $purged,
		];

		if ($format === 'json') {
			return ['exitCode' => 0, 'stdout' => self::toJson($payload), 'stderr' => ''];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf(
				"Pruned %d expired cache entries, reclaimed %d expired leases, purged %d completed queue items.\n",
				$expiredEntries,
				$reclaimed,
				$purged
			),
			'stderr' => '',
		];
	}

	/**
	 * Display queue depth summary across one or all queues.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @param string $format Output format ('text' or 'json').
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdQueueStatus(array $options, string $format): array {
		$queueName = isset($options['queue']) && $options['queue'] !== '' ? $options['queue'] : null;
		$status    = $this->queue->status($queueName);

		if ($format === 'json') {
			return ['exitCode' => 0, 'stdout' => self::toJson(['queues' => $status]), 'stderr' => ''];
		}

		if ($status === []) {
			return ['exitCode' => 0, 'stdout' => "No queue items found.\n", 'stderr' => ''];
		}

		$lines = [];

		foreach ($status as $name => $counts) {
			$lines[] = sprintf(
				'%s: pending=%d leased=%d completed=%d dead=%d expiredLeases=%d total=%d',
				$name,
				$counts['pending'],
				$counts['leased'],
				$counts['completed'],
				$counts['dead'],
				$counts['expiredLeases'],
				$counts['total']
			);
		}

		return ['exitCode' => 0, 'stdout' => implode("\n", $lines) . "\n", 'stderr' => ''];
	}

	/**
	 * Run a bounded batch worker against a queue or reclaim expired leases.
	 *
	 * Built-in handler for the "cache_maintenance" queue supports actions:
	 * flush_group, invalidate_tags, and prune. Custom handlers may be injected
	 * via the CliRunner constructor.
	 *
	 * @param array<string, string> $options Parsed CLI options.
	 * @param string $format Output format ('text' or 'json').
	 * @return array{exitCode: int, stdout: string, stderr: string}
	 */
	private function cmdQueueRun(array $options, string $format): array {
		$queueName = $options['queue'] ?? '';

		if ($queueName === '') {
			return ['exitCode' => 1, 'stdout' => '', 'stderr' => "Specify --queue=<name>.\n"];
		}

		$limit      = isset($options['limit']) && is_numeric($options['limit']) ? max(1, (int) $options['limit']) : 50;
		$timeBudget = isset($options['time-budget']) && is_numeric($options['time-budget']) ? max(1, (int) $options['time-budget']) : 25;
		$lease      = isset($options['lease']) && is_numeric($options['lease']) ? max(5, (int) $options['lease']) : null;

		$handler = $this->handlers[$queueName] ?? ($queueName === 'cache_maintenance' ? $this->maintenanceHandler() : null);

		if ($handler === null) {
			$reclaimed = $this->queue->reclaimExpiredLeases($queueName);
			$payload   = [
				'queue'        => $queueName,
				'reclaimed'    => $reclaimed,
				'claimed'      => 0,
				'completed'    => 0,
				'checkpointed' => 0,
				'retried'      => 0,
				'deadLettered' => 0,
			];

			return [
				'exitCode' => 0,
				'stdout'   => $format === 'json' ? self::toJson($payload) : sprintf("Queue \"%s\" has no registered CLI handler; reclaimed %d expired lease(s).\n", $queueName, $reclaimed),
				'stderr'   => '',
			];
		}

		$summary = $this->queue->runBatch($queueName, $handler, $limit, $timeBudget, $lease);

		if ($format === 'json') {
			return ['exitCode' => 0, 'stdout' => self::toJson($summary), 'stderr' => ''];
		}

		return [
			'exitCode' => 0,
			'stdout'   => sprintf(
				"Queue \"%s\": claimed=%d completed=%d checkpointed=%d retried=%d deadLettered=%d\n",
				$summary['queue'],
				$summary['claimed'],
				$summary['completed'],
				$summary['checkpointed'],
				$summary['retried'],
				$summary['deadLettered']
			),
			'stderr' => '',
		];
	}

	/**
	 * Build the default maintenance worker callback for the "cache_maintenance" queue.
	 *
	 * @return callable(QueueItem): array<string, mixed>
	 */
	private function maintenanceHandler(): callable {
		return function (QueueItem $item): array {
			$action = is_string($item->payload['action'] ?? null) ? $item->payload['action'] : '';

			if ($action === 'flush_group' && is_string($item->payload['group'] ?? null)) {
				$this->cache->flushGroup($item->payload['group']);

				return ['status' => 'completed'];
			}

			if ($action === 'invalidate_tags' && is_array($item->payload['tags'] ?? null)) {
				$tags = [];

				foreach ($item->payload['tags'] as $tag) {
					if (is_string($tag)) {
						$tags[] = $tag;
					}
				}

				$this->cache->invalidateTags($tags);

				return ['status' => 'completed'];
			}

			if ($action === 'prune') {
				$this->cache->pruneExpired();

				return ['status' => 'completed'];
			}

			throw new CacheException(sprintf('Unsupported maintenance action "%s".', $action));
		};
	}

	/**
	 * Parse --key=value flags and positional arguments.
	 *
	 * @param list<string> $argv Raw argument slice.
	 * @return array{options: array<string, string>, positional: list<string>}
	 */
	private static function parseArgs(array $argv): array {
		$options    = [];
		$positional = [];

		foreach ($argv as $arg) {
			if (str_starts_with($arg, '--')) {
				$trimmed = substr($arg, 2);
				$eq      = strpos($trimmed, '=');

				if ($eq !== false) {
					$options[substr($trimmed, 0, $eq)] = substr($trimmed, $eq + 1);
				} else {
					$options[$trimmed] = '1';
				}
			} else {
				$positional[] = $arg;
			}
		}

		return ['options' => $options, 'positional' => $positional];
	}

	/**
	 * Encode structured CLI output as pretty JSON with trailing newline.
	 *
	 * @param array<string, mixed> $data Structured output payload.
	 * @return string
	 */
	private static function toJson(array $data): string {
		return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
	}

	/**
	 * Return CLI usage documentation.
	 *
	 * @return string
	 */
	private static function usage(): string {
		return <<<'TXT'
Usage: php plugins/cache/cli/cache.php <command> [options]

Commands:
  stats [--format=text|json]
  flush (--group=<name> | --all) [--format=text|json]
  invalidate-tag <tag> [<tag2> ...] [--format=text|json]
  prune [--completed-age=<seconds>] [--format=text|json]
  queue-status [--queue=<name>] [--format=text|json]
  queue-run --queue=<name> [--limit=<n>] [--time-budget=<seconds>] [--lease=<seconds>] [--format=text|json]

TXT;
	}
}
