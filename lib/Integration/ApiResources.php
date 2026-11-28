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
 * Plain-array resource descriptors for the api_register_resources hook
 * exposing read-only cacheGroups and cacheQueues collections without importing
 * any external API plugin classes at boot.
 */
final class ApiResources {
	/**
	 * Relative path to plugin_cache's static Config/State YAML schema file.
	 */
	private const SCHEMA_PATH = 'schema/cache.schema.yaml';

	/**
	 * Build canonical plain-array API resource descriptors for cacheGroups and cacheQueues.
	 *
	 * @param callable(): CacheService $cacheFactory Lazy CacheService provider.
	 * @param callable(): QueueService $queueFactory Lazy QueueService provider.
	 * @return list<array<string, mixed>>
	 */
	public static function build(callable $cacheFactory, callable $queueFactory): array {
		return [
			[
				'plugin'        => 'cache',
				'collection'    => 'cacheGroups',
				'singleton'     => 'cacheGroup',
				'description'   => 'Active object cache groups and entry counts.',
				'realm_read'    => Pages::VIEW,
				'schema'        => self::SCHEMA_PATH,
				'filter_fields' => ['id', 'group', 'driver'],
				'order_fields'  => ['id', 'group', 'entries'],
				'handlers'      => [
					'list' => static function (array $context = []) use ($cacheFactory): array {
						$service = $cacheFactory();
						$driver  = $service->driver()->name();
						$rows    = [];

						foreach ($service->groups() as $group => $entries) {
							$rows[] = [
								'id'      => $group,
								'name'    => 'cacheGroups/' . $group,
								'group'   => $group,
								'entries' => $entries,
								'driver'  => $driver,
							];
						}

						return $rows;
					},
					'get' => static function (string $id, array $context = []) use ($cacheFactory): ?array {
						$service = $cacheFactory();
						$groups  = $service->groups();

						if (!array_key_exists($id, $groups)) {
							return null;
						}

						return [
							'id'      => $id,
							'name'    => 'cacheGroups/' . $id,
							'group'   => $id,
							'entries' => $groups[$id],
							'driver'  => $service->driver()->name(),
						];
					},
				],
			],
			[
				'plugin'        => 'cache',
				'collection'    => 'cacheQueues',
				'singleton'     => 'cacheQueue',
				'description'   => 'Resumable batch queue depth summaries by lifecycle status.',
				'realm_read'    => Pages::VIEW,
				'schema'        => self::SCHEMA_PATH,
				'filter_fields' => ['id', 'queue'],
				'order_fields'  => ['id', 'queue', 'pending', 'total'],
				'handlers'      => [
					'list' => static function (array $context = []) use ($queueFactory): array {
						$rows = [];

						foreach ($queueFactory()->status() as $name => $counts) {
							$rows[] = [
								'id'            => $name,
								'name'          => 'cacheQueues/' . $name,
								'queue'         => $name,
								'pending'       => $counts['pending'],
								'leased'        => $counts['leased'],
								'completed'     => $counts['completed'],
								'dead'          => $counts['dead'],
								'expiredLeases' => $counts['expiredLeases'],
								'total'         => $counts['total'],
							];
						}

						return $rows;
					},
					'get' => static function (string $id, array $context = []) use ($queueFactory): ?array {
						$status = $queueFactory()->status($id);

						if (!isset($status[$id])) {
							return null;
						}

						$counts = $status[$id];

						return [
							'id'            => $id,
							'name'          => 'cacheQueues/' . $id,
							'queue'         => $id,
							'pending'       => $counts['pending'],
							'leased'        => $counts['leased'],
							'completed'     => $counts['completed'],
							'dead'          => $counts['dead'],
							'expiredLeases' => $counts['expiredLeases'],
							'total'         => $counts['total'],
						];
					},
				],
			],
		];
	}
}
