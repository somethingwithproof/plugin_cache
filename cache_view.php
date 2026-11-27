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

$cache_stats  = cache_service()->stats();
$cache_queues = cache_queue_service()->status();

top_header();
html_start_box(__('Object Cache Metrics & Batch Queue Depth', 'cache'), '100%', false, 3, 'center', '');
print '<tr><td>' . \Somethingwithproof\Cache\Ui\PagesRenderer::renderViewDashboard($cache_stats, $cache_queues) . '</td></tr>';
html_end_box();
bottom_footer();
