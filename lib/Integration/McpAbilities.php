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
use Somethingwithproof\Cache\Pages;
use Somethingwithproof\Cache\Queue\QueueService;

/**
 * Registers read-only cache and queue observability abilities via plugin_mcp's
 * abilities_register hook without importing plugin_mcp PHP classes.
 */
final class McpAbilities {
	/**
	 * MCP tool safety annotations applied to all read-only cache abilities.
	 */
	private const ANNOTATIONS = [
		'readOnly'    => true,
		'destructive' => false,
		'idempotent'  => true,
	];

	/**
	 * Register cache-get-stats, cache-list-groups, and cache-queue-status on an
	 * AbilitiesRegistrar-compatible object.
	 *
	 * @param object $registrar Registrar supplied by plugin_mcp's abilities_register hook.
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @param callable(): QueueService $queueFactory Lazy QueueService provider.
	 * @param callable(int): bool $permissionCheck Cacti user ID permission callback.
	 * @return void
	 */
	public static function register(
		object $registrar,
		callable $cacheFactory,
		callable $queueFactory,
		callable $permissionCheck,
	): void {
		if (!method_exists($registrar, 'register')) {
			return;
		}

		$registrar->register('cache-get-stats', [
			'label'        => 'Get cache metrics',
			'description'  => 'Read active cache driver health, hit/miss ratio, entry counts, expired bloat, and XFetch/eviction telemetry without exposing cached payloads.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'driver'           => ['type' => 'string', 'maxLength' => 32],
					'healthy'          => ['type' => 'boolean'],
					'hits'             => ['type' => 'integer', 'minimum' => 0],
					'misses'           => ['type' => 'integer', 'minimum' => 0],
					'hitRatio'         => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
					'writes'           => ['type' => 'integer', 'minimum' => 0],
					'evictions'        => ['type' => 'integer', 'minimum' => 0],
					'tagInvalidations' => ['type' => 'integer', 'minimum' => 0],
					'earlyRecomputes'  => ['type' => 'integer', 'minimum' => 0],
					'entries'          => ['type' => 'integer', 'minimum' => 0],
					'expired'          => ['type' => 'integer', 'minimum' => 0],
					'bytes'            => ['type' => 'integer', 'minimum' => 0],
				],
				'required' => [
					'driver',
					'healthy',
					'hits',
					'misses',
					'hitRatio',
					'writes',
					'evictions',
					'tagInvalidations',
					'earlyRecomputes',
					'entries',
					'expired',
					'bytes',
				],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($cacheFactory): array {
				$stats = $cacheFactory()->stats();

				return [
					'driver'           => $stats['driver'],
					'healthy'          => $stats['healthy'],
					'hits'             => $stats['hits'],
					'misses'           => $stats['misses'],
					'hitRatio'         => $stats['hitRatio'],
					'writes'           => $stats['writes'],
					'evictions'        => $stats['evictions'],
					'tagInvalidations' => $stats['tagInvalidations'],
					'earlyRecomputes'  => $stats['earlyRecomputes'],
					'entries'          => $stats['entries'],
					'expired'          => $stats['expired'],
					'bytes'            => $stats['bytes'],
				];
			},
		]);

		$registrar->register('cache-list-groups', [
			'label'        => 'List cache groups',
			'description'  => 'Return active cache group names and their entry counts without exposing entry keys or values.',
			'category'     => 'system',
			'input_schema' => [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'driver' => ['type' => 'string', 'maxLength' => 32],
					'groups' => [
						'type'     => 'array',
						'maxItems' => 256,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'group'   => ['type' => 'string', 'maxLength' => 64],
								'entries' => ['type' => 'integer', 'minimum' => 0],
							],
							'required'             => ['group', 'entries'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['driver', 'groups'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($cacheFactory): array {
				$service = $cacheFactory();
				$items   = [];

				foreach ($service->groups() as $group => $count) {
					$items[] = ['group' => $group, 'entries' => $count];
				}

				return [
					'driver' => $service->driver()->name(),
					'groups' => $items,
				];
			},
		]);

		$registrar->register('cache-queue-status', [
			'label'        => 'Get batch queue status',
			'description'  => 'Inspect resumable batch queue depth counts (pending, leased, completed, dead, expiredLeases) without exposing job payloads.',
			'category'     => 'system',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'queue' => ['type' => 'string', 'maxLength' => 64],
				],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'queues' => [
						'type'     => 'array',
						'maxItems' => 256,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'queue'         => ['type' => 'string', 'maxLength' => 64],
								'pending'       => ['type' => 'integer', 'minimum' => 0],
								'leased'        => ['type' => 'integer', 'minimum' => 0],
								'completed'     => ['type' => 'integer', 'minimum' => 0],
								'dead'          => ['type' => 'integer', 'minimum' => 0],
								'expiredLeases' => ['type' => 'integer', 'minimum' => 0],
								'total'         => ['type' => 'integer', 'minimum' => 0],
							],
							'required'             => ['queue', 'pending', 'leased', 'completed', 'dead', 'expiredLeases', 'total'],
							'additionalProperties' => false,
						],
					],
				],
				'required'             => ['queues'],
				'additionalProperties' => false,
			],
			'annotations' => self::ANNOTATIONS,
			'permission'  => $permissionCheck,
			'execute'     => static function (int $userId, \stdClass $args) use ($queueFactory): array {
				$queueFilter = isset($args->queue) && is_string($args->queue) && trim($args->queue) !== ''
					? trim($args->queue)
					: null;
				$status      = $queueFactory()->status($queueFilter);
				$queues      = [];

				foreach ($status as $name => $counts) {
					$queues[] = [
						'queue'         => $name,
						'pending'       => $counts['pending'],
						'leased'        => $counts['leased'],
						'completed'     => $counts['completed'],
						'dead'          => $counts['dead'],
						'expiredLeases' => $counts['expiredLeases'],
						'total'         => $counts['total'],
					];
				}

				return ['queues' => $queues];
			},
		]);
	}

	/**
	 * Default Cacti realm permission check for MCP abilities (Cache View or higher).
	 *
	 * @param int $userId Cacti user_auth.id.
	 * @return bool
	 */
	public static function userHasViewRealm(int $userId): bool {
		if ($userId <= 0 || !function_exists('db_fetch_cell_prepared') || !function_exists('is_realm_allowed')) {
			return false;
		}

		foreach ([Pages::VIEW, Pages::OPERATOR, Pages::ADMIN] as $file) {
			/** @var mixed $realmId */
			$realmId = db_fetch_cell_prepared(
				'SELECT id FROM plugin_realms WHERE plugin = ? AND file = ?',
				['cache', $file]
			);

			if (is_numeric($realmId) && (int) $realmId > 0 && is_realm_allowed((int) $realmId + 100, $userId)) {
				return true;
			}
		}

		return false;
	}
}
