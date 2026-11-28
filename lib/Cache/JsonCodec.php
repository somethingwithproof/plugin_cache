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

namespace Somethingwithproof\Cache\Cache;

/**
 * Strict JSON-only serializer for cache envelopes and queue payloads.
 *
 * Deliberately never uses PHP native serialize()/unserialize() to eliminate
 * object-injection and gadget-chain risks across shared cache backends.
 */
final class JsonCodec {
	/**
	 * Maximum JSON nesting depth accepted during encoding and decoding.
	 */
	public const MAX_DEPTH = 32;

	/**
	 * Encode a cache envelope with value, tag checksums, timestamps, and XFetch delta.
	 *
	 * @param mixed $value JSON-encodable scalar, array, null, or JsonSerializable value.
	 * @param array<string, int> $tagChecksums Tag invalidation checksums captured at write time.
	 * @param int $createdAt Unix timestamp when the entry was created.
	 * @param int $expiresAt Unix timestamp when the entry expires (0 = no expiration).
	 * @param float $deltaSeconds Recompute duration in seconds used by XFetch early expiration.
	 * @param int $maxPayloadBytes Maximum permitted encoded payload size in bytes.
	 * @return string JSON envelope string.
	 * @throws CacheException When the value cannot be JSON-encoded or exceeds maxPayloadBytes.
	 */
	public static function encodeEnvelope(
		mixed $value,
		array $tagChecksums,
		int $createdAt,
		int $expiresAt,
		float $deltaSeconds,
		int $maxPayloadBytes,
	): string {
		$envelope = [
			'v' => $value,
			't' => $tagChecksums,
			'c' => $createdAt,
			'e' => $expiresAt,
			'd' => max(0.0, $deltaSeconds),
		];

		return self::encode($envelope, $maxPayloadBytes);
	}

	/**
	 * Decode a stored JSON cache envelope into its typed fields.
	 *
	 * @param string $raw Raw JSON string returned by a cache driver.
	 * @return array{v: mixed, t: array<string, int>, c: int, e: int, d: float}|null Decoded envelope or null if corrupt.
	 */
	public static function decodeEnvelope(string $raw): ?array {
		try {
			$decoded = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}

		if (!is_array($decoded) || !array_key_exists('v', $decoded)) {
			return null;
		}

		$tags = [];

		if (isset($decoded['t']) && is_array($decoded['t'])) {
			foreach ($decoded['t'] as $tag => $checksum) {
				if (is_string($tag) && is_int($checksum) && $checksum >= 0) {
					$tags[$tag] = $checksum;
				}
			}
		}

		$createdAt = isset($decoded['c']) && is_int($decoded['c']) ? $decoded['c'] : 0;
		$expiresAt = isset($decoded['e']) && is_int($decoded['e']) ? $decoded['e'] : 0;
		$delta     = 0.0;

		if (isset($decoded['d']) && (is_int($decoded['d']) || is_float($decoded['d'])) && is_finite((float) $decoded['d'])) {
			$delta = max(0.0, (float) $decoded['d']);
		}

		return [
			'v' => $decoded['v'],
			't' => $tags,
			'c' => $createdAt,
			'e' => $expiresAt,
			'd' => $delta,
		];
	}

	/**
	 * Encode an arbitrary JSON-safe value while enforcing depth and byte bounds.
	 *
	 * @param mixed $data JSON-encodable value.
	 * @param int $maxPayloadBytes Maximum permitted encoded byte length.
	 * @return string JSON string.
	 * @throws CacheException When encoding fails or exceeds maxPayloadBytes.
	 */
	public static function encode(mixed $data, int $maxPayloadBytes): string {
		if (is_object($data) && !$data instanceof \JsonSerializable && !$data instanceof \stdClass) {
			throw new CacheException(sprintf('Cannot cache arbitrary PHP object of class "%s"; convert to array or implement JsonSerializable.', $data::class));
		}

		try {
			$encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, self::MAX_DEPTH);
		} catch (\JsonException $error) {
			throw new CacheException('JSON encoding failed: ' . $error->getMessage(), 0, $error);
		}

		if (strlen($encoded) > $maxPayloadBytes) {
			throw new CacheException(sprintf('Encoded payload size (%d bytes) exceeds configured limit (%d bytes).', strlen($encoded), $maxPayloadBytes));
		}

		return $encoded;
	}

	/**
	 * Decode a JSON object string into an associative array.
	 *
	 * @param string $raw JSON object string.
	 * @return array<string, mixed>
	 * @throws CacheException When JSON is malformed or does not decode to an array.
	 */
	public static function decodeArray(string $raw): array {
		try {
			$decoded = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
		} catch (\JsonException $error) {
			throw new CacheException('JSON decoding failed: ' . $error->getMessage(), 0, $error);
		}

		if (!is_array($decoded)) {
			throw new CacheException('Expected JSON object or array payload.');
		}

		if ($decoded === []) {
			return [];
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}
}
