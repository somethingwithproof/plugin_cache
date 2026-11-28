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

namespace Somethingwithproof\Cache\Tests\Security;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cache\Cache\CacheException;
use Somethingwithproof\Cache\Cache\CacheKeyValidator;
use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Cache\JsonCodec;
use Somethingwithproof\Cache\Cache\MemoryDriver;
use Somethingwithproof\Cache\Config\ConfigSchema;
use Somethingwithproof\Cache\Queue\MemoryQueueStorage;
use Somethingwithproof\Cache\Queue\QueueItem;
use Somethingwithproof\Cache\Queue\QueueService;
use Somethingwithproof\Cache\Ui\CsrfGuard;
use Somethingwithproof\Cache\Ui\PagesRenderer;

final class SecurityBoundaryTest extends TestCase {
	public function testZeroPhpSerializeOrUnserializeCallsInProductionCode(): void {
		$root  = dirname(__DIR__, 2);
		$files = [
			$root . '/setup.php',
			$root . '/cache_view.php',
			$root . '/cache_operator.php',
			$root . '/cache_admin.php',
			$root . '/cli/cache.php',
		];

		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib'));

		foreach ($iterator as $entry) {
			if ($entry instanceof \SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
				$files[] = $entry->getPathname();
			}
		}

		foreach ($files as $file) {
			$source = file_get_contents($file);
			$this->assertIsString($source);
			$tokens = token_get_all($source);
			$count  = count($tokens);

			for ($i = 0; $i < $count; $i++) {
				$tok = $tokens[$i];

				if (!is_array($tok) || $tok[0] !== T_STRING) {
					continue;
				}

				if (!in_array(strtolower($tok[1]), ['serialize', 'unserialize'], true)) {
					continue;
				}

				for ($j = $i + 1; $j < $count; $j++) {
					if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
						continue;
					}

					$this->assertNotSame('(', $tokens[$j], 'Forbidden PHP serialize()/unserialize() call detected in ' . $file);

					break;
				}
			}
		}
	}

	public function testJsonCodecRejectsArbitraryPhpObjectsAndOversizedPayloads(): void {
		$arbitrary = new \DateTimeImmutable();

		try {
			JsonCodec::encode($arbitrary, 4096);
			$this->fail('Expected CacheException for non-JsonSerializable object.');
		} catch (CacheException $error) {
			$this->assertStringContainsString('Cannot cache arbitrary PHP object', $error->getMessage());
		}

		$service = new CacheService(new MemoryDriver(50), 60, 1024);

		$this->expectException(CacheException::class);
		$this->expectExceptionMessage('exceeds configured limit');
		$service->set('big', str_repeat('A', 2000));
	}

	public function testValidatorBlocksInjectionControlCharsAndReservedLockGroup(): void {
		$badKeys = [
			'',
			"key\r\nFLUSHALL\r\n",
			"key\0null",
			'key with spaces',
			str_repeat('a', 192),
		];

		foreach ($badKeys as $badKey) {
			try {
				CacheKeyValidator::validateKey($badKey);
				$this->fail('Expected CacheException for bad key.');
			} catch (CacheException) {
				$this->addToAssertionCount(1);
			}
		}

		try {
			CacheKeyValidator::validateGroup('__locks');
			$this->fail('Expected CacheException for reserved __locks group.');
		} catch (CacheException $error) {
			$this->assertStringContainsString('reserved', $error->getMessage());
		}

		try {
			CacheKeyValidator::validateTags(["device:1\r\nDEL *"]);
			$this->fail('Expected CacheException for RESP injection in tag.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		$tooMany = [];

		for ($i = 0; $i < CacheKeyValidator::MAX_TAGS + 1; $i++) {
			$tooMany[] = 'tag:' . $i;
		}

		try {
			CacheKeyValidator::validateTags($tooMany);
			$this->fail('Expected CacheException when exceeding MAX_TAGS.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(CacheException::class);
		CacheKeyValidator::validateTtl(-1);
	}

	public function testQueueEnforcesPayloadLimitAndLeaseFencing(): void {
		$queue = new QueueService(new MemoryQueueStorage(), 30, 3, 1024);

		try {
			$queue->enqueue('q1', ['blob' => str_repeat('X', 2000)]);
			$this->fail('Expected CacheException for oversized queue payload.');
		} catch (CacheException $error) {
			$this->assertStringContainsString('exceeds configured limit', $error->getMessage());
		}

		$id      = $queue->enqueue('q1', ['job' => 'ok']);
		$claimed = $queue->claim('q1', 30);
		$this->assertNotNull($claimed);

		// Forged lease token cannot complete, fail, or checkpoint the leased item.
		$this->assertFalse($queue->complete($id, str_repeat('0', 32)));
		$this->assertSame('', $queue->fail($id, str_repeat('0', 32), 'spoofed'));
		$this->assertFalse($queue->checkpoint($id, str_repeat('0', 32), ['offset' => 1]));
		$this->assertTrue($queue->complete($id, $claimed->leaseToken));
	}

	public function testCsrfGuardAndThreeTierRbacEnforcement(): void {
		$session = [];
		$token   = CsrfGuard::token($session);

		$this->assertSame(64, strlen($token));
		$this->assertTrue(CsrfGuard::verify($session, $token));
		$this->assertFalse(CsrfGuard::verify($session, ''));
		$this->assertFalse(CsrfGuard::verify($session, str_repeat('a', 64)));
		$this->assertFalse(CsrfGuard::verify([], $token));

		// Test 3-tier RBAC inheritance (Admin > Operator > View) and unauthenticated denial.
		$GLOBALS['user_auth_realm_filenames'] = [
			'cache_view.php'     => 201,
			'cache_operator.php' => 202,
			'cache_admin.php'    => 203,
		];
		$GLOBALS['__cache_enabled'] = true;

		// Unauthenticated request to cache_operator.php is denied.
		$_SESSION                         = [];
		$GLOBALS['__cache_current_page']  = 'cache_operator.php';
		$GLOBALS['__cache_allowed_realms'] = [];
		$this->assertFalse(cache_page_allowed());

		// View-only user allowed on cache_view.php, denied on cache_operator.php and cache_admin.php.
		$_SESSION                         = ['sess_user_id' => 5];
		$GLOBALS['__cache_allowed_realms'] = [201];
		$GLOBALS['__cache_current_page']  = 'cache_view.php';
		$this->assertTrue(cache_page_allowed());
		$GLOBALS['__cache_current_page']  = 'cache_operator.php';
		$this->assertFalse(cache_page_allowed());
		$GLOBALS['__cache_current_page']  = 'cache_admin.php';
		$this->assertFalse(cache_page_allowed());

		// Admin user inherits Operator and View realms.
		$GLOBALS['__cache_allowed_realms'] = [203];
		$GLOBALS['__cache_current_page']  = 'cache_view.php';
		$this->assertTrue(cache_page_allowed());
		$GLOBALS['__cache_current_page']  = 'cache_operator.php';
		$this->assertTrue(cache_page_allowed());

		$_SESSION = [];
	}

	public function testConfigRejectsRawRedisSecretsAndUiEscapesXss(): void {
		$errors = ConfigSchema::validate(['cache_redis_secret_ref' => 's3cr3t-password']);
		$this->assertNotEmpty($errors);
		$this->assertSame([], ConfigSchema::validate(['cache_redis_secret_ref' => 'env:CACTI_REDIS_AUTH']));
		$this->assertSame([], ConfigSchema::validate(['cache_redis_secret_ref' => 'vault:cache:redis_pass']));

		$xssItem = new QueueItem(
			1,
			'q<script>',
			[],
			QueueItem::STATUS_DEAD,
			0,
			0,
			'',
			3,
			3,
			null,
			null,
			'<script>alert(1)</script>',
			0,
			0
		);
		$session = [];
		$html    = PagesRenderer::renderOperatorConsole(
			CsrfGuard::hiddenField($session),
			[$xssItem],
			['<img src=x onerror=alert(1)>'],
			['<b>bad</b>']
		);

		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
	}
}
