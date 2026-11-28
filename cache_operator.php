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

chdir('../../');
include('./include/auth.php');
include_once('./plugins/cache/setup.php');

if (!cache_page_start()) {
	exit;
}

$cache_service  = cache_service();
$cache_queue    = cache_queue_service();
$cache_messages = array();
$cache_errors   = array();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	$cache_submitted_csrf = isset_request_var(\Somethingwithproof\Cache\Ui\CsrfGuard::FIELD_NAME)
		? (string) get_nfilter_request_var(\Somethingwithproof\Cache\Ui\CsrfGuard::FIELD_NAME)
		: '';

	if (!\Somethingwithproof\Cache\Ui\CsrfGuard::verify($_SESSION, $cache_submitted_csrf)) {
		http_response_code(403);
		$cache_errors[] = __('CSRF verification failed. Reload the page and retry.', 'cache');
	} else {
		$cache_action = isset_request_var('action') ? (string) get_nfilter_request_var('action') : '';

		try {
			if ($cache_action === 'flush_group') {
				$group   = isset_request_var('group') ? trim((string) get_nfilter_request_var('group')) : '';
				$removed = $cache_service->flushGroup($group);
				$cache_messages[] = __('Flushed cache group "%s" (%d entries).', $group, $removed, 'cache');
			} elseif ($cache_action === 'flush_all') {
				$confirmed = isset_request_var('confirm_flush_all') && (string) get_nfilter_request_var('confirm_flush_all') === '1';

				if (!$confirmed) {
					$cache_errors[] = __('Tick the confirmation box before flushing all cache entries.', 'cache');
				} else {
					$removed          = $cache_service->flushAll();
					$cache_messages[] = __('Flushed all cache entries (%d entries).', $removed, 'cache');
				}
			} elseif ($cache_action === 'invalidate_tags') {
				$rawTags = isset_request_var('tags') ? (string) get_nfilter_request_var('tags') : '';
				$tags    = array();

				foreach (preg_split('/[\r\n,]+/', $rawTags) ?: array() as $part) {
					$trimmed = trim($part);

					if ($trimmed !== '') {
						$tags[] = $trimmed;
					}
				}

				if ($tags === array()) {
					$cache_errors[] = __('Provide at least one cache tag to invalidate.', 'cache');
				} else {
					$count            = $cache_service->invalidateTags($tags);
					$cache_messages[] = __('Invalidated %d cache tag(s).', $count, 'cache');
				}
			} elseif ($cache_action === 'prune') {
				$expired          = $cache_service->pruneExpired();
				$reclaimed        = $cache_queue->reclaimExpiredLeases();
				$cache_messages[] = __('Pruned %d expired entries and reclaimed %d expired queue leases.', $expired, $reclaimed, 'cache');
			} elseif ($cache_action === 'retry_dead') {
				$itemId = isset_request_var('item_id') ? (int) get_filter_request_var('item_id') : 0;

				if ($cache_queue->retryDeadLetter($itemId)) {
					$cache_messages[] = __('Requeued dead-letter item #%d.', $itemId, 'cache');
				} else {
					$cache_errors[] = __('Dead-letter item #%d was not found.', $itemId, 'cache');
				}
			} elseif ($cache_action === 'purge_dead') {
				$confirmed = isset_request_var('confirm_purge_dead') && (string) get_nfilter_request_var('confirm_purge_dead') === '1';

				if (!$confirmed) {
					$cache_errors[] = __('Tick the confirmation box before purging dead-letter items.', 'cache');
				} else {
					$purged           = $cache_queue->purgeDeadLetters();
					$cache_messages[] = __('Purged %d dead-letter queue item(s).', $purged, 'cache');
				}
			} else {
				$cache_errors[] = __('Unsupported operator action.', 'cache');
			}
		} catch (\Somethingwithproof\Cache\Cache\CacheException $error) {
			$cache_errors[] = $error->getMessage();
		}
	}
}

$cache_csrf        = \Somethingwithproof\Cache\Ui\CsrfGuard::hiddenField($_SESSION);
$cache_deadLetters = $cache_queue->deadLetters(null, 50);

top_header();
html_start_box(__('Object Cache Operations & Dead-Letter Queue (Operator)', 'cache'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Cache\Ui\PagesRenderer::renderOperatorConsole($cache_csrf, $cache_deadLetters, $cache_messages, $cache_errors) . '</td></tr>';
html_end_box();
bottom_footer();
