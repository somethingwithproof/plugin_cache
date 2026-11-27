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

namespace Somethingwithproof\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cache\Cache\ApcuDriver;
use Somethingwithproof\Cache\Cache\CacheDriver;
use Somethingwithproof\Cache\Cache\CacheException;
use Somethingwithproof\Cache\Cache\CacheKeyValidator;
use Somethingwithproof\Cache\Cache\CacheService;
use Somethingwithproof\Cache\Cache\JsonCodec;
use Somethingwithproof\Cache\Cache\MariaDbDriver;
use Somethingwithproof\Cache\Cache\MemoryDriver;
use Somethingwithproof\Cache\Cache\RedisDriver;
use Somethingwithproof\Cache\Config\ConfigSchema;
use Somethingwithproof\Cache\Db\DbAdapter;
use Somethingwithproof\Cache\Integration\CascProvider;
use Somethingwithproof\Cache\Integration\DoctorChecks;
use Somethingwithproof\Cache\Queue\MemoryQueueStorage;
use Somethingwithproof\Cache\Queue\QueueService;
use Somethingwithproof\Cache\Settings;
use Somethingwithproof\Cache\Ui\PagesRenderer;

final class EdgeCasesAndCoverageTest extends TestCase {
	protected function tearDown(): void {
		cache_reset_runtime();
		$GLOBALS['__cache_options']        = [];
		$GLOBALS['__cache_allowed_realms'] = [];
		$GLOBALS['__cache_enabled']        = true;
		$GLOBALS['__cache_current_page']   = 'index.php';
	}

	public function testDoctorChecksWarningAndCriticalBranches(): void {
		$unhealthyDriver = new class () implements CacheDriver {
			public function name(): string {
				return 'redis';
			}

			public function getRaw(string $group, string $key): ?string {
				return null;
			}

			public function setRaw(string $group, string $key, string $payload, int $ttlSeconds, array $tags = []): bool {
				return false;
			}

			public function delete(string $group, string $key): bool {
				return false;
			}

			public function flushGroup(string $group): int {
				return 0;
			}

			public function flushAll(): int {
				return 0;
			}

			public function pruneExpired(?int $now = null): int {
				return 0;
			}

			public function getTagChecksums(array $tags): array {
				return [];
			}

			public function incrementTags(array $tags): array {
				return [];
			}

			public function acquireLock(string $lockKey, string $token, int $ttlSeconds): bool {
				return false;
			}

			public function releaseLock(string $lockKey, string $token): bool {
				return false;
			}

			public function groupCounts(): array {
				return [];
			}

			public function metrics(): array {
				return [
					'driver'  => 'redis',
					'healthy' => false,
					'entries' => 100,
					'expired' => 1500,
					'bytes'   => 1024,
					'details' => [],
				];
			}

			public function ping(): bool {
				return false;
			}
		};

		$cache = new CacheService($unhealthyDriver);
		$queue = new QueueService(new MemoryQueueStorage());

		// One dead-letter item triggers warning.
		$queue->enqueue('q1', ['a' => 1], null, 1);
		$item = $queue->claim('q1', 10);
		$this->assertNotNull($item);
		$queue->fail($item->id, $item->leaseToken, 'boom');

		$checks = DoctorChecks::buildChecks(static fn (): CacheService => $cache, static fn (): QueueService => $queue);
		$this->assertSame('critical', ($checks[0]['run'])()['status']);
		$this->assertSame('warning', ($checks[1]['run'])()['status']);
		$this->assertSame('warning', ($checks[2]['run'])()['status']);

		// 50+ dead-letter items escalate queue health to critical.
		for ($i = 0; $i < 50; $i++) {
			$queue->enqueue('q1', ['i' => $i], null, 1);
			$next = $queue->claim('q1', 10);
			$this->assertNotNull($next);
			$queue->fail($next->id, $next->leaseToken, 'fail');
		}

		$this->assertSame('critical', ($checks[2]['run'])()['status']);

		// Exception during driver ping is caught and reported as critical.
		$throwingChecks = DoctorChecks::buildChecks(
			static function (): CacheService {
				throw new \RuntimeException('connection refused');
			},
			static fn (): QueueService => $queue
		);
		$this->assertSame('critical', ($throwingChecks[0]['run'])()['status']);
	}

	public function testCascProviderErrorPathsAndSetupDriverFactories(): void {
		$settings = Settings::fromRow([]);
		$this->assertFalse(CascProvider::register(new \stdClass(), static fn (): Settings => $settings, static fn (): bool => true));

		$desc     = CascProvider::descriptor(static fn (): Settings => $settings, static fn (): bool => true);
		$badPlan  = ($desc['plan'])(['cache_driver' => 'bogus'], []);
		$this->assertSame('error', $badPlan[0]['operation']);
		$badApply = ($desc['apply'])(['cache_driver' => 'bogus'], 'h', []);
		$this->assertFalse($badApply['ok']);

		// Test setup.php driver factory for all 4 driver types + default singleton wiring.
		$this->assertInstanceOf(MemoryDriver::class, cache_driver(Settings::fromRow(['cache_driver' => 'memory'])));
		$this->assertInstanceOf(ApcuDriver::class, cache_driver(Settings::fromRow(['cache_driver' => 'apcu'])));
		$this->assertInstanceOf(RedisDriver::class, cache_driver(Settings::fromRow(['cache_driver' => 'redis'])));
		$this->assertInstanceOf(MemoryDriver::class, cache_driver(Settings::fromRow(['cache_driver' => 'mariadb'])));

		cache_reset_runtime();
		$this->assertInstanceOf(CacheService::class, cache_service());
		$this->assertInstanceOf(QueueService::class, cache_queue_service());

		// Exercise setup.php ecosystem hooks with mock registrars and non-objects.
		cache_casc_register_providers('not-an-object');
		cache_abilities_register('not-an-object');

		$cascCaptured = null;
		$cascReg      = new class ($cascCaptured) {
			/** @var mixed */
			public mixed $d;

			/** @param mixed $ref */
			public function __construct(mixed &$ref) {
				$this->d = &$ref;
			}

			/** @param mixed $desc */
			public function register(mixed $desc): void {
				$this->d = $desc;
			}
		};
		cache_casc_register_providers($cascReg);
		$this->assertIsArray($cascCaptured);

		$mcpCount = 0;
		$mcpReg   = new class ($mcpCount) {
			/** @var int */
			public int $c;

			/** @param int $ref */
			public function __construct(int &$ref) {
				$this->c = &$ref;
			}

			/** @param string $name @param array<string, mixed> $def */
			public function register(string $name, array $def): void {
				$this->c++;
			}
		};
		cache_abilities_register($mcpReg);
		$this->assertSame(3, $mcpCount);

		// Exercise Operator realm inheritance and cache_page_start().
		$GLOBALS['user_auth_realm_filenames'] = [
			'cache_view.php'     => 201,
			'cache_operator.php' => 202,
			'cache_admin.php'    => 203,
		];
		$_SESSION                          = ['sess_user_id' => 9];
		$GLOBALS['__cache_allowed_realms'] = [202];
		$GLOBALS['__cache_current_page']   = 'cache_view.php';
		$this->assertTrue(cache_page_start());

		$GLOBALS['__cache_current_page'] = 'cache_admin.php';
		$this->assertFalse(cache_page_start());
		$_SESSION = [];
	}

	public function testRedisDriverErrorModesAndUnhealthyMariaDbDriver(): void {
		// Stream factory failure.
		$failConn = new RedisDriver('::1', 6379, false, 0, 1.0, null, 'cacti:', static fn () => false);
		$this->assertFalse($failConn->ping());
		$this->assertFalse($failConn->metrics()['healthy']);
		$this->assertSame([], $failConn->getTagChecksums([]));

		// AUTH non-OK failure.
		$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($pair);
		fwrite($pair[1], "+NOPE\r\n");
		$badAuth = new RedisDriver('127.0.0.1', 6379, false, 0, 1.0, 'pw', 'cacti:', static fn () => $pair[0]);
		$this->assertFalse($badAuth->ping());
		fclose($pair[1]);

		// SELECT non-OK failure.
		$pair2 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($pair2);
		fwrite($pair2[1], "+NOPE\r\n");
		$badSel = new RedisDriver('127.0.0.1', 6379, false, 3, 1.0, null, 'cacti:', static fn () => $pair2[0]);
		$this->assertFalse($badSel->ping());
		fclose($pair2[1]);

		// Unexpected RESP2 prefix byte and wrong-token releaseLock.
		$pair3 = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
		$this->assertIsArray($pair3);
		fwrite($pair3[1], "\$5\r\nother\r\n!bad\r\n");
		$proto = new RedisDriver('127.0.0.1', 6379, false, 0, 1.0, null, 'cacti:', static fn () => $pair3[0]);
		$this->assertFalse($proto->releaseLock('lk', 'mine'));

		try {
			$proto->getRaw('default', 'k');
			$this->fail('Expected CacheException for invalid RESP2 prefix.');
		} catch (CacheException $error) {
			$this->assertStringContainsString('Unexpected RESP2 prefix', $error->getMessage());
		} finally {
			fclose($pair3[1]);
		}

		// MariaDbDriver unhealthy branch when DB returns false/null.
		$deadDb = new class () implements DbAdapter {
			public function execute(string $sql, array $params = []): bool {
				return false;
			}

			public function fetchCell(string $sql, array $params = []): mixed {
				return false;
			}

			public function fetchRow(string $sql, array $params = []): ?array {
				return null;
			}

			public function fetchAssoc(string $sql, array $params = []): array {
				return [];
			}

			public function lastInsertId(): int {
				return 0;
			}

			public function affectedRows(): int {
				return 0;
			}
		};
		$mdb = new MariaDbDriver($deadDb);
		$this->assertFalse($mdb->ping());
		$this->assertFalse($mdb->metrics()['healthy']);
		$this->assertFalse($mdb->delete('g', 'k'));
		$this->assertFalse($mdb->releaseLock('lk', 'tok'));
	}

	public function testJsonCodecValidatorsQueueErrorsAndDashboardTables(): void {
		try {
			JsonCodec::decodeArray('{invalid');
			$this->fail('Expected CacheException for malformed JSON.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		try {
			JsonCodec::decodeArray('"just-a-string"');
			$this->fail('Expected CacheException for non-array JSON.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		try {
			CacheKeyValidator::validateQueueName('bad queue!');
			$this->fail('Expected CacheException for invalid queue name.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		try {
			CacheKeyValidator::validateIdempotencyKey("bad\nkey");
			$this->fail('Expected CacheException for invalid idempotency key.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		$queue = new QueueService(new MemoryQueueStorage());

		try {
			$queue->enqueue('q', ['a' => 1], null, null, -5);
			$this->fail('Expected CacheException for negative delay.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		try {
			$queue->checkpoint(1, 'tok', ['a' => 1], -1);
			$this->fail('Expected CacheException for negative checkpoint delay.');
		} catch (CacheException) {
			$this->addToAssertionCount(1);
		}

		// Render View dashboard with non-empty groups and queues.
		$cache = new CacheService(new MemoryDriver(50));
		$cache->set('k', 'v', 60, 'devices');
		$queue->enqueue('sync', ['a' => 1]);
		$html = PagesRenderer::renderViewDashboard($cache->stats(), $queue->status());
		$this->assertStringContainsString('devices', $html);
		$this->assertStringContainsString('sync', $html);

		// ConfigSchema non-string and non-int type errors.
		$errs = ConfigSchema::validate([
			'cache_default_ttl' => 'not-int',
			'cache_xfetch_beta' => 'not-number',
			'cache_key_prefix'  => 123,
		]);
		$this->assertCount(3, $errs);
	}
}
