<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Vorgio\Support\RetryPolicy;
use Vorgio\Tests\Laravel\TestCase;
use Vorgio\VorgioClient;

uses(TestCase::class)->in('Laravel');

/**
 * Build a {@see VorgioClient} backed by Guzzle's MockHandler.
 *
 * Returns a tuple `[VorgioClient, ArrayObject]` where the ArrayObject is the
 * history container the Guzzle history middleware writes into. Using an
 * ArrayObject (instead of a plain array) lets us return it through list
 * destructuring without losing the live binding the middleware writes through.
 *
 * @param  array<int, Response|\GuzzleHttp\Exception\GuzzleException>  $responses
 * @return array{0: VorgioClient, 1: ArrayObject<int, array>}
 */
function vorgioMockClient(array $responses, string $token = 'act_test', ?RetryPolicy $retry = null): array
{
    /** @var ArrayObject<int, array> $history */
    $history = new ArrayObject();

    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $http = new Client([
        'handler' => $stack,
        'http_errors' => false,
    ]);

    // Default to a no-retry policy so existing tests that queue a single
    // 5xx response still observe the expected failure. Retry-specific
    // tests pass a zero-backoff policy explicitly.
    $client = new VorgioClient(
        token: $token,
        baseUrl: 'https://vorgio.test',
        httpClient: $http,
        retry: $retry ?? RetryPolicy::disabled(),
    );

    return [$client, $history];
}

/**
 * Bind a Guzzle MockHandler-backed {@see VorgioClient} into the container
 * so trait calls (`app(VorgioClient::class)`) hit the mock. Mirrors the
 * non-Laravel `vorgioMockClient()` helper but with container wiring.
 *
 * @param  array<int, Response|\GuzzleHttp\Exception\GuzzleException>  $responses
 * @return ArrayObject<int, array>
 */
function vorgioBindMockClient(array $responses): ArrayObject
{
    /** @var ArrayObject<int, array> $history */
    $history = new ArrayObject();

    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $http = new Client(['handler' => $stack, 'http_errors' => false]);

    app()->instance(VorgioClient::class, new VorgioClient(
        token: 'act_test',
        baseUrl: 'https://vorgio.test',
        httpClient: $http,
        retry: RetryPolicy::disabled(),
    ));

    return $history;
}

/**
 * Convenience helper: build a JSON 2xx response.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function jsonResponse(int $status, array $body, array $headers = []): Response
{
    return new Response(
        $status,
        array_merge(['Content-Type' => 'application/json'], $headers),
        json_encode($body, JSON_THROW_ON_ERROR),
    );
}

/**
 * Convenience helper: build an RFC 7807 problem response.
 *
 * @param  array<string, mixed>  $extras
 * @param  array<string, string>  $headers
 */
function problemResponse(int $status, string $title, string $detail = '', array $extras = [], array $headers = []): Response
{
    return new Response(
        $status,
        array_merge(['Content-Type' => 'application/problem+json'], $headers),
        json_encode(array_merge([
            'type' => 'https://vorgio.example/problems/'.strtolower(str_replace(' ', '-', $title)),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $extras), JSON_THROW_ON_ERROR),
    );
}
