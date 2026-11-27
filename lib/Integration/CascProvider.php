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

use Somethingwithproof\Cache\Config\ConfigSchema;
use Somethingwithproof\Cache\Settings;

/**
 * Decoupled provider for plugin_casc's casc_register_providers hook (slug: cache).
 *
 * Exports declarative plugin Config only (driver selection, TTLs, payload limit,
 * Redis transport parameters, and symbolic secret references). Never exports
 * State (cached entries, tag counters, queue rows) or resolved Redis secrets,
 * and imports zero plugin_casc PHP classes at boot.
 */
final class CascProvider {
	/**
	 * Plugin slug registered with plugin_casc.
	 */
	public const SLUG = 'cache';

	/**
	 * Register the cache provider on a plugin_casc registrar or duck-typed test double.
	 *
	 * @param object $registrar Registrar supplied by plugin_casc's casc_register_providers hook.
	 * @param callable(): Settings $settingsProvider Returns live plugin settings.
	 * @param callable(Settings): bool $settingsWriter Persists updated plugin settings.
	 * @return bool True when registration succeeded.
	 */
	public static function register(
		object $registrar,
		callable $settingsProvider,
		callable $settingsWriter,
	): bool {
		if (!method_exists($registrar, 'register')) {
			return false;
		}

		$descriptor = self::descriptor($settingsProvider, $settingsWriter);

		$cascDescriptorClass = 'Somethingwithproof\\Casc\\Extension\\PluginProviderDescriptor';

		if (class_exists($cascDescriptorClass)) {
			$instance = new $cascDescriptorClass(
				$descriptor['slug'],
				$descriptor['schemaVersion'],
				$descriptor['export'],
				$descriptor['validate'],
				$descriptor['plan'],
				$descriptor['apply'],
			);
			$registrar->register($instance);

			return true;
		}

		$registrar->register($descriptor);

		return true;
	}

	/**
	 * Build the plain-array CasC provider descriptor for plugin_cache.
	 *
	 * @param callable(): Settings $settingsProvider Returns live plugin settings.
	 * @param callable(Settings): bool $settingsWriter Persists updated plugin settings.
	 * @return array{
	 *   slug: string,
	 *   schemaVersion: string,
	 *   export: callable(array<string, mixed>): array<string, bool|float|int|string>,
	 *   validate: callable(array<string, mixed>): list<string>,
	 *   plan: callable(array<string, mixed>, array<string, mixed>): list<array<string, mixed>>,
	 *   apply: callable(array<string, mixed>, string, array<string, mixed>): array<string, mixed>
	 * }
	 */
	public static function descriptor(callable $settingsProvider, callable $settingsWriter): array {
		return [
			'slug'          => self::SLUG,
			'schemaVersion' => ConfigSchema::SCHEMA_VERSION,
			'export'        => static function (array $options = []) use ($settingsProvider): array {
				return $settingsProvider()->toConfigArray();
			},
			'validate'      => static function (array $desired): array {
				return ConfigSchema::validate($desired);
			},
			'plan'          => static function (array $desired, array $options = []) use ($settingsProvider): array {
				$errors = ConfigSchema::validate($desired);

				if ($errors !== []) {
					return array_map(
						static fn (string $msg): array => [
							'operation' => 'error',
							'key'       => self::SLUG,
							'message'   => $msg,
						],
						$errors
					);
				}

				$current = $settingsProvider()->toConfigArray();
				$actions = [];

				foreach ($desired as $field => $targetValue) {
					$liveValue = $current[$field] ?? null;

					if ($liveValue !== $targetValue) {
						$actions[] = [
							'operation' => 'update',
							'key'       => self::SLUG . '.' . $field,
							'before'    => $liveValue,
							'after'     => $targetValue,
						];
					}
				}

				return $actions;
			},
			'apply'         => static function (array $desired, string $planHash, array $options = []) use ($settingsProvider, $settingsWriter): array {
				$errors = ConfigSchema::validate($desired);

				if ($errors !== []) {
					return [
						'ok'       => false,
						'planHash' => $planHash,
						'errors'   => $errors,
					];
				}

				$merged   = array_merge($settingsProvider()->toConfigArray(), $desired);
				$next     = Settings::fromRow($merged);
				$written  = $settingsWriter($next);

				return [
					'ok'       => $written,
					'planHash' => $planHash,
					'updated'  => array_keys($desired),
				];
			},
		];
	}
}
