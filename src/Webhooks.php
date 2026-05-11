<?php

declare(strict_types=1);

namespace Vorgio;

use Vorgio\Exception\VorgioSignatureException;

/**
 * Helpers for verifying inbound Vorgio webhooks.
 *
 * The signature scheme matches the server (`app/Jobs/DispatchWebhook.php`):
 *
 *   Header:  `Vorgio-Signature: t=<unix>,v1=<hmac_sha256_hex>`
 *   Payload: `<unix>.<raw_request_body>`
 *   Algorithm: HMAC-SHA256 with the per-endpoint webhook secret.
 *
 * The API mirrors Stripe's `Webhook::constructEvent` deliberately — that's
 * what most plugin authors will already know.
 */
final class Webhooks
{
    public const DEFAULT_TOLERANCE = 300; // seconds

    /**
     * Verify the signature header, then parse + return the event.
     *
     * @param  string  $payload  Raw request body, byte-identical to what Vorgio signed.
     * @param  string  $sigHeader  Value of the `Vorgio-Signature` header.
     * @param  string  $secret  Per-endpoint secret stored when the webhook was created.
     * @param  int  $tolerance  Maximum acceptable clock skew in seconds (replay window).
     * @param  int|null  $now  Inject a fixed "current" Unix timestamp for testing; defaults to time().
     *
     * @throws VorgioSignatureException
     */
    public static function constructEvent(
        string $payload,
        string $sigHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): WebhookEvent {
        if ($secret === '') {
            throw new VorgioSignatureException('Webhook secret cannot be empty.');
        }

        [$timestamp, $signatures] = self::parseSignatureHeader($sigHeader);

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $matched = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw new VorgioSignatureException('Webhook signature mismatch.');
        }

        $age = ($now ?? time()) - (int) $timestamp;
        if ($age > $tolerance || $age < -$tolerance) {
            throw new VorgioSignatureException(
                'Webhook timestamp outside tolerance window ('.$age.'s).',
            );
        }

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            throw new VorgioSignatureException('Webhook payload is not valid JSON.');
        }

        return new WebhookEvent(
            id: (string) ($decoded['id'] ?? ''),
            type: (string) ($decoded['type'] ?? ''),
            createdAt: (string) ($decoded['created_at'] ?? ''),
            data: is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            metadata: is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
            rawPayload: $payload,
        );
    }

    /**
     * Lower-level helper. Compute the canonical signature header for a
     * given payload + timestamp + secret. Useful when emitting test webhooks
     * from your own fixtures.
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $hmac = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return 't='.$timestamp.',v1='.$hmac;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private static function parseSignatureHeader(string $header): array
    {
        $timestamp = '';
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }

            $key = substr($part, 0, $eq);
            $value = substr($part, $eq + 1);

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === '' || $signatures === [] || ! ctype_digit($timestamp)) {
            throw new VorgioSignatureException('Vorgio-Signature header is malformed.');
        }

        return [$timestamp, $signatures];
    }
}
