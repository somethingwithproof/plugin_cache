<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# plugin_cache Performance Profile

This document records the algorithmic audit and PHP runtime benchmarks for `plugin_cache` core hot paths (`MemoryDriver` S3-FIFO, `CacheService` XFetch and tag validation, `CacheKeyValidator`, `JsonCodec`, `RedisDriver` RESP2 parser, `MemoryQueueStorage`, `MariaDbQueueStorage`, and `QueueService`).

## Benchmark Environment

- Runtime: PHP 8.6.0 CLI (NTS, Zend OPcache enabled) on macOS arm64
- Timing primitive: monotonic `hrtime(true)` (wall time in milliseconds)
- Memory primitive: `memory_get_peak_usage(true)` per isolated process

## Hotspots Identified and Fixed

1. **`MemoryQueueStorage` quadratic enqueue and claim ($O(N^2) \to O(N \log N)$)**
   - Previous behavior: `enqueue()` scanned all `$this->rows` linearly on every idempotency check, and `claim()` scanned all `$this->rows` twice (once inside `reclaimExpiredLeases()`, once to find the minimum `(available_at, id)` pending item). For a 10,000-item `QueueService::runBatch()`, that executed ~200 million row inspections (7.66 seconds).
   - Fix: added an $O(1)$ `$idempotencyIndex` map (`"$queueName\0$idempotencyKey" => $id`), an $O(L)$ `$leasedByQueue` active-lease index so `reclaimExpiredLeases()` scans only leased rows, and per-queue binary min-heaps (`$pendingHeaps`) mutated in place by reference (`&$this->pendingHeaps[$queueName]`) to avoid Zend copy-on-write array duplication. `QueueService::runBatch()` also avoids re-validating `$queueName` per iteration and eliminates default clock closure dispatch overhead.
   - `MariaDbQueueStorage::reclaimExpiredLeases()` was also consolidated from two `UPDATE` statements into a single atomic `UPDATE` using `CASE WHEN attempts >= max_attempts`, halving DB round-trips during claim and poller maintenance.

2. **`MemoryDriver` S3-FIFO verification and hot-path overhead**
   - Queue operations verified: `smallQueue`, `mainQueue`, and `ghostQueue` use PHP associative ordered hash maps (`array<string, true>`) with $O(1)$ amortized `array_key_first()` + `unset()` (zero $O(N)$ `array_shift()` calls).
   - Fixes: eliminated per-operation `hash('sha256', ...)` in `MemoryDriver` in favor of collision-free in-process `"$group\0$key"` keys, avoided default clock closure invocation overhead on every `getRaw()` / `setRaw()`, and fixed an over-eviction edge case in `evictSmall()` so promoting a hit item stops once both total capacity and small-queue capacity have room for the pending insert.

3. **`CacheService::remember()` and `CacheKeyValidator` validation overhead**
   - Previous behavior: `remember()` validated key, group, tags, and TTL with PCRE `preg_match()`, then re-validated them inside `readEnvelope()` and `writeEnvelope()`, sampled `microtime(true)` twice on hits, and called OS-entropy `random_int()` for non-cryptographic XFetch threshold checks.
   - Fix: replaced PCRE regexes in `CacheKeyValidator` with byte-equivalent C-level `strlen()` + `strspn()` checks, introduced internal `readEnvelopeValidated()` and `writeEnvelopeValidated()` helpers to eliminate duplicate validation, reused a single clock sample per `remember()` call, and used `mt_rand()` for probabilistic XFetch decisions while preserving `random_bytes(16)` for single-flight lock tokens.

4. **`RedisDriver` buffered RESP2 framing and `JsonCodec`**
   - Previous behavior: `readLine()` called `fgets()` per frame, `readExact()` concatenated chunks and sliced off trailing CRLF with a full copy, and `writeAll()` called `substr()` even when `$written === 0`.
   - Fix: replaced per-frame socket calls with a 64 KiB buffered reader (`$readBuffer`, `$readOffset`, `$readLength`) using $O(1)$ offset advancement and amortized compaction on refill, avoided `substr()` copies on the normal single-write path, and eliminated unnecessary array key copying in `JsonCodec::decodeArray()`.

## Measured Before / After Results

| Benchmark Workload | Before (ms) | After (ms) | Speedup | Peak Memory (Before -> After) |
| --- | ---: | ---: | ---: | --- |
| `MemoryDriver` S3-FIFO 100k `set`/`get`/evict ops (capacity 2,000) | 78.69 | 43.69 | 1.80x | 4.00 MiB -> 4.00 MiB |
| `MemoryDriver` S3-FIFO 100k Zipfian + sequential scan (69.17% hit ratio) | 74.44 | 26.19 | 2.84x | 8.02 MiB -> 8.02 MiB |
| `CacheService::remember()` 50k tagged XFetch hits | 130.10 | 106.01 | 1.23x | 4.00 MiB -> 4.00 MiB |
| `RedisDriver` RESP2 200x64 `MGET` (12,800 bulk frames over socket pair) | 6.33 | 5.98 | 1.06x | 4.00 MiB -> 4.00 MiB |
| `QueueService::enqueue()` 10,000 idempotent items | 1,203.06 | 8.93 | 134.72x | 10.00 MiB -> 14.00 MiB |
| `QueueService::runBatch()` drain 10,000 items | 7,655.10 | 27.74 | 275.96x | 10.00 MiB -> 14.00 MiB |

## Complexity Summary

| Component | Target Complexity | Notes |
| --- | --- | --- |
| `MemoryDriver` S3-FIFO `getRaw` / `setRaw` / evict | $O(1)$ amortized | Ordered PHP hash tables (`array_key_first` + `unset`), zero `array_shift()` |
| `CacheService::remember()` | $O(T)$ | $T \le 64$ tags checked in one batch `getTagChecksums()` call |
| `RedisDriver` RESP2 framing | $O(B)$ | 64 KiB buffered reads with offset cursor and amortized compaction |
| `MemoryQueueStorage::enqueue()` | $O(\log N)$ | $O(1)$ idempotency lookup + $O(\log N)$ in-place binary min-heap push |
| `MemoryQueueStorage::claim()` | $O(L + \log N)$ | Scans only active leases $L$ + $O(\log N)$ min-heap pop |
