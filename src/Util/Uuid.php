<?php

declare(strict_types=1);

namespace Vorgio\Util;

use InvalidArgumentException;
use RuntimeException;

/**
 * Tiny UUID generator (v7 + v5).
 *
 * UUIDv7 is time-ordered (48-bit Unix-ms timestamp prefix) which makes it a
 * great fit for idempotency keys: two requests fired in the same millisecond
 * still get distinct values, but keys generated minutes apart sort
 * lexically. RFC 9562, layout 7.
 *
 * UUIDv5 is a deterministic SHA-1 hash over (namespace || name). The SDK
 * uses it to derive stable per-purpose idempotency sub-keys and position
 * UUIDs from a single caller-supplied operation id, so that retries — even
 * those crossing midnight or queue boundaries — produce byte-identical
 * request bodies.
 *
 * Implemented inline so the SDK pulls no UUID dependency.
 */
final class Uuid
{
    /**
     * Stable namespace UUID under which all Vorgio operation-id derivations
     * are computed. Treat as a constant of the protocol: changing it would
     * silently invalidate every previously-issued idempotency key.
     */
    public const NAMESPACE_VORGIO_OP = '7c8e5e4a-9c5d-5a8e-9a0a-1f5b2d3c4e9f';

    /**
     * Generate a fresh UUIDv7 in canonical 8-4-4-4-12 form.
     */
    public static function v7(): string
    {
        $unixMs = (int) floor(microtime(true) * 1000);

        $bytes = random_bytes(16);

        // Big-endian 48-bit timestamp into bytes 0-5.
        $bytes[0] = chr(($unixMs >> 40) & 0xFF);
        $bytes[1] = chr(($unixMs >> 32) & 0xFF);
        $bytes[2] = chr(($unixMs >> 24) & 0xFF);
        $bytes[3] = chr(($unixMs >> 16) & 0xFF);
        $bytes[4] = chr(($unixMs >> 8) & 0xFF);
        $bytes[5] = chr($unixMs & 0xFF);

        // Version 7 in the high nibble of byte 6.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);

        // RFC 4122 variant in the high two bits of byte 8.
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format($bytes);
    }

    /**
     * Generate a deterministic UUIDv5 (SHA-1 of namespace || name).
     *
     * @param  string  $namespace  Canonical UUID string of the namespace.
     * @param  string  $name  Arbitrary UTF-8 input.
     */
    public static function v5(string $namespace, string $name): string
    {
        $nsBytes = self::parseToBytes($namespace);
        $hash = sha1($nsBytes.$name, true);
        $bytes = substr($hash, 0, 16);

        // Version 5 in the high nibble of byte 6.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);

        // RFC 4122 variant in the high two bits of byte 8.
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return self::format($bytes);
    }

    /**
     * Extract the embedded ms-since-epoch timestamp from a UUIDv7.
     *
     * Useful for "snapshot now" derivations that must remain stable across
     * retries: the SDK reads the timestamp from the persisted operation id
     * instead of calling {@see microtime()} on each attempt.
     */
    public static function extractTimestampMs(string $uuidV7): int
    {
        $bytes = self::parseToBytes($uuidV7);

        if (((ord($bytes[6]) & 0xF0) >> 4) !== 7) {
            throw new InvalidArgumentException('UUID is not version 7.');
        }

        return (ord($bytes[0]) << 40)
            | (ord($bytes[1]) << 32)
            | (ord($bytes[2]) << 24)
            | (ord($bytes[3]) << 16)
            | (ord($bytes[4]) << 8)
            | ord($bytes[5]);
    }

    /**
     * Parse a canonical UUID string into 16 raw bytes.
     */
    private static function parseToBytes(string $uuid): string
    {
        $hex = str_replace('-', '', $uuid);

        if (strlen($hex) !== 32 || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException(sprintf('Invalid UUID: %s', $uuid));
        }

        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new InvalidArgumentException(sprintf('Invalid UUID: %s', $uuid));
        }

        return $bytes;
    }

    /**
     * Format a 16-byte string as a canonical 8-4-4-4-12 UUID.
     */
    private static function format(string $bytes): string
    {
        $hex = bin2hex($bytes);

        if (strlen($hex) !== 32) {
            throw new RuntimeException('UUID generation failed.');
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
