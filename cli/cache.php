#!/usr/bin/env php
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

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$dir = __DIR__;
chdir($dir . '/../../..');

require('./include/cli_check.php');
require_once($config['base_path'] . '/plugins/cache/setup.php');

if (cache_refuse_unsupported_php()) {
	exit(1);
}

cache_boot();

$args   = array_slice($argv, 1);
$runner = new \Somethingwithproof\Cache\Cli\CliRunner(cache_service(), cache_queue_service());
$result = $runner->run($args);

if ($result['stdout'] !== '') {
	fwrite(STDOUT, $result['stdout']);
}

if ($result['stderr'] !== '') {
	fwrite(STDERR, $result['stderr']);
}

exit($result['exitCode']);
