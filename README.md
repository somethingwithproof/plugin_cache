# plugin_cache

Standalone Object Cache, Transients, Cache Tags, and Resumable Batch Queue plugin for Cacti 1.2.x with zero Cacti core patches, zero runtime Composer dependencies, and zero cross-plugin PHP imports at boot.

## Why this plugin exists

Cacti 1.2 plugins repeatedly re-implement ad-hoc database caching, polling throttles, and long-running background sweeps without shared stampede protection, tag-based invalidation, or crash-safe resumable job state. `plugin_cache` packages WordPress-style Object Cache and Transients, Drupal-style Cache Tags, and a resumable MariaDB Queue/Batch engine inside `plugins/cache/` so operators and sibling plugins can opt in without modifying Cacti core.

## Capabilities

- **Pluggable Object Cache & Transients (`lib/Cache/`)**: Unified `CacheDriver` contract with four drivers:
  - `MariaDbDriver`: Indexed `plugin_cache_entries` (`cache_group`, `expires_at`) and monotonic `plugin_cache_tags` tables.
  - `ApcuDriver`: Shared-memory APCu backend with O(1) group and namespace generation counters so flushes never wipe unrelated applications.
  - `RedisDriver`: Pure-PHP RESP2 client over `tcp://` or `tls://` sockets (peer certificate verification, `AUTH`, `SELECT`, timeouts, prefix-scoped `SCAN` flushes) with zero PECL `ext-redis` dependency.
  - `MemoryDriver`: Bounded in-process store using **S3-FIFO** eviction (small probation queue `S` at 10% capacity, main queue `M` at 90%, ghost queue `G` for evicted keys, and 2-bit frequency counters) to filter one-hit-wonders and resist sequential scan pollution.
- **Stampede Protection with XFetch (`CacheService::remember()`)**: Combines single-flight backend locks (`INSERT IGNORE`, `apcu_add`, or Redis `SET NX EX`) with **XFetch** probabilistic early expiration. Each `remember()` write records recomputation duration `delta`; on subsequent reads, early refresh triggers when `now - delta * beta * ln(rand) >= expiresAt`, refreshing under the single-flight lock while concurrent readers receive the still-fresh cached value without blocking.
- **Drupal-Style Cache Tags (`invalidateTags()`)**: Stores per-tag checksum snapshots in each JSON envelope and increments monotonic tag counters on invalidation, providing O(1) tag invalidation across every driver (for example, `invalidateTags(['device:42', 'plugin:redfish'])`).
- **Safe JSON-Only Serialization (`JsonCodec`)**: All cache envelopes and queue payloads use `json_encode` / `json_decode` with depth 32 and configurable byte limits (`cache_max_payload_bytes`). PHP `serialize()` and `unserialize()` are never called anywhere in the plugin.
- **Resumable Queue & Batch Engine (`lib/Queue/`)**: Backed by `plugin_cache_queue` with idempotency keys, fenced lease tokens (`lease_token`, `lease_until`), automatic expired-lease reclamation, exponential backoff retries, dead-letter inspection and retry, and `cursor_json` checkpointing for multi-chunk jobs spanning 100k+ fleet targets.
- **Strict Config vs. State Separation (`schema/cache.schema.yaml`)**: Declarative configuration (driver selection, TTLs, XFetch beta, S3-FIFO capacity, Redis host/TLS/secret reference, queue defaults) is separated from operational State (`plugin_cache_entries`, `plugin_cache_tags`, `plugin_cache_queue`, runtime counters), which is never exported.
- **3-Tier RBAC Console UI & CLI**: `cache_view.php` (View), `cache_operator.php` (Operator with CSRF-protected flush, tag invalidation, prune, and dead-letter triage), `cache_admin.php` (Admin configuration and live driver probe), and `cli/cache.php`.
- **Decoupled Ecosystem Hooks (`lib/Integration/`)**: Registers `casc_register_providers` (`slug: cache`, config only), `doctor_register_checks` and `doctor_register_debug_info`, `abilities_register` (`cache-get-stats`, `cache-list-groups`, `cache-queue-status`), and `api_register_resources` (`cacheGroups`, `cacheQueues`) without boot-time cross-plugin class imports.

## Academic Primitives

- **XFetch Probabilistic Early Expiration**: Implemented in `CacheService::remember()` based on Vattani, A., Chierichetti, F., & Lowenstein, K. (2015), "Optimal Probabilistic Cache Stampede Prevention," *Proceedings of the VLDB Endowment*, 8(8), 886-897. Controlled via `cache_xfetch_beta` (default `1.0`; set `0.0` to disable).
- **S3-FIFO Eviction Policy**: Implemented in `MemoryDriver` based on Yang, J., Zhang, Y., Qiu, Z., Yue, Y., & Vinayak, R. (2023), "FIFO Queues are All You Need for Cache Eviction," *Proceedings of the 29th Symposium on Operating Systems Principles (ACM SOSP '23)*. Controlled via `cache_memory_capacity` (default `1000`).

## Requirements

- Cacti `1.2.31` or later on the `1.2.x` branch
- PHP `8.2` or later with `ext-json` (and `ext-pdo_mysql` via Cacti for MariaDB storage)
- Entry points (`setup.php`, `index.php`, `include/autoload.php`, `cache_*.php`, `cli/cache.php`) parse cleanly on PHP `8.0` so unsupported hosts receive a logged refusal rather than a fatal parse error

## Installation

1. Clone or unpack into `<cacti_root>/plugins/cache`.
2. Install and enable via CLI or Console:

```bash
php cli/plugin_manage.php --plugin=cache --install --enable
```

3. Assign user permissions using the three-tier RBAC realms (`Cache View`, `Cache Operator`, `Cache Admin`).

## Soft-Coupling Helper API for Sibling Plugins

Sibling plugins can consume `plugin_cache` optionally while staying 100% standalone when it is absent:

```php
if (function_exists('cacti_cache_remember')) {
	$inventory = cacti_cache_remember(
		'host:42:inventory',
		300,
		static fn (): array => fetch_device_inventory(42),
		'inventory',
		['device:42', 'plugin:redfish']
	);
} else {
	$inventory = fetch_device_inventory(42);
}

if (function_exists('cacti_cache_invalidate_tags')) {
	cacti_cache_invalidate_tags(['device:42']);
}
```

Procedural helpers exposed by `setup.php`:
- `cacti_cache_get(string $key, string $group = 'default', mixed $default = null): mixed`
- `cacti_cache_set(string $key, mixed $value, ?int $ttl = null, string $group = 'default', array $tags = []): bool`
- `cacti_cache_delete(string $key, string $group = 'default'): bool`
- `cacti_cache_invalidate_tags(array $tags): int`
- `cacti_cache_remember(string $key, ?int $ttl, callable $callback, string $group = 'default', array $tags = []): mixed`

## CLI Usage

```bash
# Inspect driver health, hit ratio, entry counts, and S3-FIFO / XFetch counters
php plugins/cache/cli/cache.php stats --format=text
php plugins/cache/cli/cache.php stats --format=json

# Flush a single group or all cache entries
php plugins/cache/cli/cache.php flush --group=devices
php plugins/cache/cli/cache.php flush --all --format=json

# Invalidate Drupal-style cache tags
php plugins/cache/cli/cache.php invalidate-tag device:42 plugin:redfish

# Prune expired cache rows, reclaim expired queue leases, and purge old completed queue items
php plugins/cache/cli/cache.php prune --completed-age=86400

# Inspect batch queue depths and execute bounded batch chunks
php plugins/cache/cli/cache.php queue-status --format=json
php plugins/cache/cli/cache.php queue-run --queue=cache_maintenance --limit=50 --time-budget=25
```

## Security Boundaries

- **No PHP Deserialization**: `JsonCodec` enforces strict JSON encoding/decoding with a depth ceiling of 32 and rejects arbitrary non-`JsonSerializable` PHP objects. A security test scans all production PHP files to assert zero `serialize()` or `unserialize()` calls.
- **Input & Payload Bounds**: `CacheKeyValidator` constrains keys, groups, tags, queue names, and idempotency keys to safe ASCII patterns (blocking CRLF/RESP injection and null bytes), blocks external access to the reserved `__locks` group, and enforces `cache_max_payload_bytes`.
- **Secret Handling**: `cache_redis_secret_ref` accepts only symbolic references (`env:VAR` or `vault:plugin:slot`). Raw passwords are rejected by `ConfigSchema::validate()`, never persisted in `settings`, and never emitted by CasC exports or Doctor debug info.
- **Authorization & CSRF**: Console pages enforce three-tier RBAC (`Cache Admin > Cache Operator > Cache View`) through `cache_auth_alternate_realms()` and `cache_page_start()`, and Operator/Admin POST actions verify a session-bound 256-bit CSRF token (`CsrfGuard`) in constant time alongside Cacti's `csrf-magic`.

## Development & Testing

Runtime code has zero Composer dependencies. Development tooling (`phpunit/phpunit` and `phpstan/phpstan`) is installed via `composer install`:

```bash
composer install
composer test
CACTI_PATH=/path/to/cacti-1.2.31 composer phpstan
```

CI enforces:
- PHP 8.0 syntax lint on all entry points
- PHP 8.2, 8.3, 8.4, and 8.5 unit, security, and 100% PHPDoc coverage tests (`tests/Quality/PhpDocCoverageTest.php`)
- PHPStan Level 9 with 0 errors against Cacti `release/1.2.31`
- Measured `pcov` `clover.xml` line coverage of at least 85%
- Live MariaDB 11.8 integration tests and CLI smoke tests on Cacti `release/1.2.31` and `1.2.x`

## License

GNU General Public License v2.0 or later (`GPL-2.0-or-later`). See [LICENSE](LICENSE).
