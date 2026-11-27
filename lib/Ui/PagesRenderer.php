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

namespace Somethingwithproof\Cache\Ui;

use Somethingwithproof\Cache\Config\ConfigSchema;
use Somethingwithproof\Cache\Queue\QueueItem;
use Somethingwithproof\Cache\Settings;

/**
 * HTML renderer for Cache View, Operator, and Admin Cacti Console pages.
 *
 * Escapes all dynamic text through html_escape() and emits no inline scripts.
 */
final class PagesRenderer {
	/**
	 * Render the read-only Cache View dashboard (metrics, group counts, queue depths).
	 *
	 * @param array<string, mixed> $stats Result of CacheService::stats().
	 * @param array<string, array{pending: int, leased: int, completed: int, dead: int, expiredLeases: int, total: int}> $queues Result of QueueService::status().
	 * @return string Safe HTML markup.
	 */
	public static function renderViewDashboard(array $stats, array $queues): string {
		$driver      = is_scalar($stats['driver'] ?? null) ? (string) $stats['driver'] : 'unknown';
		$healthy     = (bool) ($stats['healthy'] ?? false);
		$hits        = is_numeric($stats['hits'] ?? null) ? (int) $stats['hits'] : 0;
		$misses      = is_numeric($stats['misses'] ?? null) ? (int) $stats['misses'] : 0;
		$hitRatio    = is_numeric($stats['hitRatio'] ?? null) ? (float) $stats['hitRatio'] : 0.0;
		$entries     = is_numeric($stats['entries'] ?? null) ? (int) $stats['entries'] : 0;
		$expired     = is_numeric($stats['expired'] ?? null) ? (int) $stats['expired'] : 0;
		$bytes       = is_numeric($stats['bytes'] ?? null) ? (int) $stats['bytes'] : 0;
		$early       = is_numeric($stats['earlyRecomputes'] ?? null) ? (int) $stats['earlyRecomputes'] : 0;
		$tagInval    = is_numeric($stats['tagInvalidations'] ?? null) ? (int) $stats['tagInvalidations'] : 0;
		$groups      = is_array($stats['groups'] ?? null) ? $stats['groups'] : [];

		$html  = '<h3>' . html_escape(__('Object Cache Overview', 'cache')) . '</h3>';
		$html .= '<table class="cactiTable"><thead><tr class="tableHeader">'
			. '<th>' . html_escape(__('Driver', 'cache')) . '</th>'
			. '<th>' . html_escape(__('Health', 'cache')) . '</th>'
			. '<th>' . html_escape(__('Active Entries', 'cache')) . '</th>'
			. '<th>' . html_escape(__('Expired Rows', 'cache')) . '</th>'
			. '<th>' . html_escape(__('Stored Bytes', 'cache')) . '</th>'
			. '<th>' . html_escape(__('Hits / Misses (Ratio)', 'cache')) . '</th>'
			. '<th>' . html_escape(__('XFetch Early Recomputes', 'cache')) . '</th>'
			. '<th>' . html_escape(__('Tag Invalidations', 'cache')) . '</th>'
			. '</tr></thead><tbody><tr>'
			. '<td>' . html_escape($driver) . '</td>'
			. '<td>' . html_escape($healthy ? __('Healthy', 'cache') : __('Unhealthy', 'cache')) . '</td>'
			. '<td>' . $entries . '</td>'
			. '<td>' . $expired . '</td>'
			. '<td>' . $bytes . '</td>'
			. '<td>' . sprintf('%d / %d (%.1f%%)', $hits, $misses, $hitRatio * 100.0) . '</td>'
			. '<td>' . $early . '</td>'
			. '<td>' . $tagInval . '</td>'
			. '</tr></tbody></table>';

		$html .= '<h3>' . html_escape(__('Cache Groups', 'cache')) . '</h3>';

		if ($groups === []) {
			$html .= '<p>' . html_escape(__('No active cache groups.', 'cache')) . '</p>';
		} else {
			$html .= '<table class="cactiTable"><thead><tr class="tableHeader">'
				. '<th>' . html_escape(__('Group', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Active Entries', 'cache')) . '</th>'
				. '</tr></thead><tbody>';

			foreach ($groups as $group => $count) {
				$html .= '<tr><td>' . html_escape((string) $group) . '</td><td>' . (is_numeric($count) ? (int) $count : 0) . '</td></tr>';
			}

			$html .= '</tbody></table>';
		}

		$html .= '<h3>' . html_escape(__('Resumable Batch Queues', 'cache')) . '</h3>';

		if ($queues === []) {
			$html .= '<p>' . html_escape(__('No batch queues recorded.', 'cache')) . '</p>';
		} else {
			$html .= '<table class="cactiTable"><thead><tr class="tableHeader">'
				. '<th>' . html_escape(__('Queue', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Pending', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Leased', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Completed', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Dead-Letter', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Expired Leases', 'cache')) . '</th>'
				. '</tr></thead><tbody>';

			foreach ($queues as $name => $counts) {
				$html .= '<tr>'
					. '<td>' . html_escape($name) . '</td>'
					. '<td>' . $counts['pending'] . '</td>'
					. '<td>' . $counts['leased'] . '</td>'
					. '<td>' . $counts['completed'] . '</td>'
					. '<td>' . $counts['dead'] . '</td>'
					. '<td>' . $counts['expiredLeases'] . '</td>'
					. '</tr>';
			}

			$html .= '</tbody></table>';
		}

		return $html;
	}

	/**
	 * Render the Cache Operator forms (flush group, invalidate tags, prune, dead-letter triage).
	 *
	 * @param string $csrfField Hidden CSRF input element.
	 * @param list<QueueItem> $deadLetters Current dead-letter items.
	 * @param list<string> $messages Informational feedback messages.
	 * @param list<string> $errors Validation or CSRF error messages.
	 * @return string Safe HTML markup.
	 */
	public static function renderOperatorConsole(
		string $csrfField,
		array $deadLetters,
		array $messages,
		array $errors,
	): string {
		$html = self::renderAlerts($messages, $errors);

		$html .= '<h3>' . html_escape(__('Cache Group & Tag Operations', 'cache')) . '</h3>';
		$html .= '<form method="post" action="cache_operator.php">' . $csrfField
			. '<input type="hidden" name="action" value="flush_group">'
			. '<label for="cache_group">' . html_escape(__('Flush Cache Group:', 'cache')) . ' </label>'
			. '<input type="text" id="cache_group" name="group" maxlength="64" placeholder="default"> '
			. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Flush Group', 'cache')) . '</button>'
			. '</form><br>';

		$html .= '<form method="post" action="cache_operator.php">' . $csrfField
			. '<input type="hidden" name="action" value="invalidate_tags">'
			. '<label for="cache_tags">' . html_escape(__('Invalidate Cache Tags (comma-separated):', 'cache')) . ' </label>'
			. '<input type="text" id="cache_tags" name="tags" size="48" placeholder="device:42,plugin:redfish"> '
			. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Invalidate Tags', 'cache')) . '</button>'
			. '</form><br>';

		$html .= '<form method="post" action="cache_operator.php">' . $csrfField
			. '<input type="hidden" name="action" value="prune">'
			. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Prune Expired Entries & Reclaim Leases', 'cache')) . '</button>'
			. '</form><br>';

		$html .= '<form method="post" action="cache_operator.php">' . $csrfField
			. '<input type="hidden" name="action" value="flush_all">'
			. '<label><input type="checkbox" name="confirm_flush_all" value="1"> ' . html_escape(__('Confirm full cache flush', 'cache')) . '</label> '
			. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Flush All', 'cache')) . '</button>'
			. '</form><hr>';

		$html .= '<h3>' . html_escape(__('Dead-Letter Queue Inspection', 'cache')) . '</h3>';

		if ($deadLetters === []) {
			$html .= '<p>' . html_escape(__('No dead-letter queue items.', 'cache')) . '</p>';
		} else {
			$html .= '<table class="cactiTable"><thead><tr class="tableHeader">'
				. '<th>' . html_escape(__('ID', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Queue', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Attempts', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Last Error', 'cache')) . '</th>'
				. '<th>' . html_escape(__('Action', 'cache')) . '</th>'
				. '</tr></thead><tbody>';

			foreach ($deadLetters as $item) {
				$html .= '<tr>'
					. '<td>' . $item->id . '</td>'
					. '<td>' . html_escape($item->queueName) . '</td>'
					. '<td>' . sprintf('%d / %d', $item->attempts, $item->maxAttempts) . '</td>'
					. '<td>' . html_escape($item->lastError) . '</td>'
					. '<td><form method="post" action="cache_operator.php">' . $csrfField
					. '<input type="hidden" name="action" value="retry_dead">'
					. '<input type="hidden" name="item_id" value="' . $item->id . '">'
					. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Retry', 'cache')) . '</button>'
					. '</form></td>'
					. '</tr>';
			}

			$html .= '</tbody></table><br>';
			$html .= '<form method="post" action="cache_operator.php">' . $csrfField
				. '<input type="hidden" name="action" value="purge_dead">'
				. '<label><input type="checkbox" name="confirm_purge_dead" value="1"> ' . html_escape(__('Confirm dead-letter purge', 'cache')) . '</label> '
				. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Purge Dead Letters', 'cache')) . '</button>'
				. '</form>';
		}

		return $html;
	}

	/**
	 * Render the Cache Admin configuration and driver health probe page.
	 *
	 * @param string $csrfField Hidden CSRF input element.
	 * @param Settings $settings Active plugin settings.
	 * @param array<string, mixed> $metrics Driver health metrics.
	 * @param list<string> $messages Informational feedback messages.
	 * @param list<string> $errors Validation or CSRF error messages.
	 * @return string Safe HTML markup.
	 */
	public static function renderAdminConsole(
		string $csrfField,
		Settings $settings,
		array $metrics,
		array $messages,
		array $errors,
	): string {
		$html    = self::renderAlerts($messages, $errors);
		$healthy = (bool) ($metrics['healthy'] ?? false);

		$html .= '<h3>' . html_escape(__('Active Driver Health', 'cache')) . '</h3>';
		$html .= '<p>' . html_escape(__(
			'Driver: %s | Status: %s | Config Schema: v%s',
			$settings->driver,
			$healthy ? __('Healthy', 'cache') : __('Unhealthy', 'cache'),
			ConfigSchema::SCHEMA_VERSION,
			'cache'
		)) . '</p>';

		$html .= '<form method="post" action="cache_admin.php">' . $csrfField
			. '<input type="hidden" name="action" value="ping">'
			. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Run Driver Connectivity Probe', 'cache')) . '</button>'
			. '</form><hr>';

		$html .= '<h3>' . html_escape(__('Driver & Policy Configuration (Config Only)', 'cache')) . '</h3>';
		$html .= '<form method="post" action="cache_admin.php">' . $csrfField
			. '<input type="hidden" name="action" value="save_config">'
			. '<table class="cactiTable"><tbody>';

		$driverOptions = '';

		foreach (ConfigSchema::DRIVERS as $candidate) {
			$selected       = $settings->driver === $candidate ? ' selected' : '';
			$driverOptions .= '<option value="' . html_escape($candidate) . '"' . $selected . '>' . html_escape($candidate) . '</option>';
		}

		$html .= '<tr><td><label for="cache_driver">' . html_escape(__('Cache Driver', 'cache')) . '</label></td>'
			. '<td><select id="cache_driver" name="cache_driver">' . $driverOptions . '</select></td></tr>';
		$html .= self::textRow('cache_default_ttl', __('Default TTL (seconds)', 'cache'), (string) $settings->defaultTtl);
		$html .= self::textRow('cache_max_payload_bytes', __('Max Payload Bytes', 'cache'), (string) $settings->maxPayloadBytes);
		$html .= self::textRow('cache_key_prefix', __('Key Prefix', 'cache'), $settings->keyPrefix);
		$html .= self::textRow('cache_lock_ttl', __('Stampede Lock TTL (seconds)', 'cache'), (string) $settings->lockTtl);
		$html .= self::textRow('cache_xfetch_beta', __('XFetch Beta Factor', 'cache'), sprintf('%.2F', $settings->xfetchBeta));
		$html .= self::textRow('cache_memory_capacity', __('Memory Driver S3-FIFO Capacity', 'cache'), (string) $settings->memoryCapacity);
		$html .= self::textRow('cache_redis_host', __('Redis Host', 'cache'), $settings->redisHost);
		$html .= self::textRow('cache_redis_port', __('Redis Port', 'cache'), (string) $settings->redisPort);
		$html .= '<tr><td><label for="cache_redis_tls">' . html_escape(__('Redis TLS', 'cache')) . '</label></td>'
			. '<td><input type="checkbox" id="cache_redis_tls" name="cache_redis_tls" value="on"' . ($settings->redisTls ? ' checked' : '') . '></td></tr>';
		$html .= self::textRow('cache_redis_db', __('Redis Database (0-15)', 'cache'), (string) $settings->redisDb);
		$html .= self::textRow('cache_redis_timeout', __('Redis Timeout (seconds)', 'cache'), sprintf('%.2F', $settings->redisTimeout));
		$html .= self::textRow('cache_redis_secret_ref', __('Redis Secret Reference (env:VAR or vault:plugin:slot)', 'cache'), $settings->redisSecretRef);
		$html .= self::textRow('cache_queue_default_lease', __('Queue Default Lease (seconds)', 'cache'), (string) $settings->queueDefaultLease);
		$html .= self::textRow('cache_queue_default_max_attempts', __('Queue Default Max Attempts', 'cache'), (string) $settings->queueDefaultMaxAttempts);

		$html .= '</tbody></table><br>'
			. '<button type="submit" class="ui-button ui-corner-all ui-widget">' . html_escape(__('Save Configuration', 'cache')) . '</button>'
			. '</form>';

		return $html;
	}

	/**
	 * Render feedback and validation alerts.
	 *
	 * @param list<string> $messages Informational feedback messages.
	 * @param list<string> $errors Error messages.
	 * @return string
	 */
	private static function renderAlerts(array $messages, array $errors): string {
		$html = '';

		foreach ($messages as $message) {
			$html .= '<div class="cactiMessageInfo">' . html_escape($message) . '</div>';
		}

		foreach ($errors as $error) {
			$html .= '<div class="cactiMessageError">' . html_escape($error) . '</div>';
		}

		return $html;
	}

	/**
	 * Render a single labeled text input row for the Admin configuration table.
	 *
	 * @param string $name Field name and HTML id.
	 * @param string $label Human-readable label.
	 * @param string $value Current field value.
	 * @return string
	 */
	private static function textRow(string $name, string $label, string $value): string {
		return '<tr><td><label for="' . html_escape($name) . '">' . html_escape($label) . '</label></td>'
			. '<td><input type="text" id="' . html_escape($name) . '" name="' . html_escape($name) . '" value="' . html_escape($value) . '"></td></tr>';
	}
}
