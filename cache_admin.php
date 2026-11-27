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

		if ($cache_action === 'ping') {
			$healthy = cache_service()->driver()->ping();

			if ($healthy) {
				$cache_messages[] = __('Active cache driver connectivity probe succeeded.', 'cache');
			} else {
				$cache_errors[] = __('Active cache driver connectivity probe failed.', 'cache');
			}
		} elseif ($cache_action === 'save_config') {
			$candidate = array(
				'cache_driver'                     => isset_request_var('cache_driver') ? trim((string) get_nfilter_request_var('cache_driver')) : 'mariadb',
				'cache_default_ttl'                => isset_request_var('cache_default_ttl') ? (int) get_nfilter_request_var('cache_default_ttl') : 300,
				'cache_max_payload_bytes'          => isset_request_var('cache_max_payload_bytes') ? (int) get_nfilter_request_var('cache_max_payload_bytes') : 1048576,
				'cache_key_prefix'                 => isset_request_var('cache_key_prefix') ? (string) get_nfilter_request_var('cache_key_prefix') : 'cacti:',
				'cache_lock_ttl'                   => isset_request_var('cache_lock_ttl') ? (int) get_nfilter_request_var('cache_lock_ttl') : 15,
				'cache_xfetch_beta'                => isset_request_var('cache_xfetch_beta') ? (float) get_nfilter_request_var('cache_xfetch_beta') : 1.0,
				'cache_memory_capacity'            => isset_request_var('cache_memory_capacity') ? (int) get_nfilter_request_var('cache_memory_capacity') : 1000,
				'cache_redis_host'                 => isset_request_var('cache_redis_host') ? trim((string) get_nfilter_request_var('cache_redis_host')) : '127.0.0.1',
				'cache_redis_port'                 => isset_request_var('cache_redis_port') ? (int) get_nfilter_request_var('cache_redis_port') : 6379,
				'cache_redis_tls'                  => isset_request_var('cache_redis_tls') && (string) get_nfilter_request_var('cache_redis_tls') === 'on',
				'cache_redis_db'                   => isset_request_var('cache_redis_db') ? (int) get_nfilter_request_var('cache_redis_db') : 0,
				'cache_redis_timeout'              => isset_request_var('cache_redis_timeout') ? (float) get_nfilter_request_var('cache_redis_timeout') : 2.0,
				'cache_redis_secret_ref'           => isset_request_var('cache_redis_secret_ref') ? trim((string) get_nfilter_request_var('cache_redis_secret_ref')) : '',
				'cache_queue_default_lease'        => isset_request_var('cache_queue_default_lease') ? (int) get_nfilter_request_var('cache_queue_default_lease') : 60,
				'cache_queue_default_max_attempts' => isset_request_var('cache_queue_default_max_attempts') ? (int) get_nfilter_request_var('cache_queue_default_max_attempts') : 5,
				'cache_uninstall_keep_tables'      => cache_settings()->uninstallKeepTables,
			);

			$validationErrors = \Somethingwithproof\Cache\Config\ConfigSchema::validate($candidate);

			if ($validationErrors !== array()) {
				$cache_errors = $validationErrors;
			} else {
				$saved = cache_save_settings(\Somethingwithproof\Cache\Settings::fromRow($candidate));

				if ($saved) {
					$cache_messages[] = __('Saved plugin_cache configuration.', 'cache');
				} else {
					$cache_errors[] = __('Unable to save plugin_cache configuration.', 'cache');
				}
			}
		} else {
			$cache_errors[] = __('Unsupported administrator action.', 'cache');
		}
	}
}

$cache_csrf     = \Somethingwithproof\Cache\Ui\CsrfGuard::hiddenField($_SESSION);
$cache_settings = cache_settings();
$cache_metrics  = cache_service()->driver()->metrics();

top_header();
html_start_box(__('Object Cache Configuration & Driver Health (Administrator)', 'cache'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Cache\Ui\PagesRenderer::renderAdminConsole($cache_csrf, $cache_settings, $cache_metrics, $cache_messages, $cache_errors) . '</td></tr>';
html_end_box();
bottom_footer();
