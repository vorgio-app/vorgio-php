<?php

declare(strict_types=1);

namespace Vorgio\Util;

use RuntimeException;

/**
 * Tiny UUIDv7 generator.
 *
 * UUIDv7 is time-ordered (48-bit Unix-ms timestamp prefix) which makes it a
 * great fit for idempotency keys: two requests fired in the same millisecond
 * still get distinct values, but keys generated minutes apart sort
 * lexically. RFC 9562, layout 7.
 *
 * Implemented inline so the SDK pulls no UUID dependency.
 */
final class Uuid
{
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
