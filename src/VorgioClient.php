<?php

declare(strict_types=1);

namespace Vorgio;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Vorgio\Exception\VorgioApiException;
use Vorgio\Exception\VorgioException;
use Vorgio\Exception\VorgioRateLimitedException;
use Vorgio\Exception\VorgioValidationException;
use Vorgio\Resource\Checkouts;
use Vorgio\Resource\Clients;
use Vorgio\Resource\Invoices;
use Vorgio\Util\Uuid;

/**
 * Entry-point for the Vorgio SDK.
 *
 * <code>
 * $vorgio = new VorgioClient(
 *     token: 'act_…',
 *     baseUrl: 'https://app.vorgio.example',
 * );
 *
 * $checkout = $vorgio->checkouts()->create([...]);
 * </code>
 *
 * The client is intentionally thin — it forwards JSON, attaches auth +
 * idempotency headers, decodes the response, and turns 4xx/5xx into typed
 * exceptions. No retries, no caching, no client-side validation.
 */
class VorgioClient
{
    public const VERSION = '0.1.0';

    /**
     * Default request timeout in seconds. Overridable per call via the
     * `timeout` option.
     */
    public const DEFAULT_TIMEOUT = 30.0;

    private ClientInterface $http;

    public function __construct(
        public readonly string $token,
        public readonly string $baseUrl = 'https://app.vorgio.example',
        ?ClientInterface $httpClient = null,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly string $apiVersion = 'v1',
    ) {
        if (trim($token) === '') {
            throw new VorgioException('Vorgio API token cannot be empty.');
        }

        $this->http = $httpClient ?? new GuzzleClient([
            'timeout' => $timeout,
            'connect_timeout' => 10.0,
            'http_errors' => false,
        ]);
    }

    public function checkouts(): Checkouts
    {
        return new Checkouts($this);
    }

    public function invoices(): Invoices
    {
        return new Invoices($this);
    }

    public function clients(): Clients
    {
        return new Clients($this);
    }

    /**
     * Perform an authenticated request and return the decoded body.
     *
     * @param  array<string, mixed>|null  $body  JSON body for POST/PATCH/PUT
     * @param  array<string, mixed>  $query  Query string parameters
     * @param  array<string, string>  $headers  Extra request headers
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        array $headers = [],
    ): array {
        $method = strtoupper($method);

        $headers = array_change_key_case($headers, CASE_LOWER);

        $finalHeaders = [
            'authorization' => 'Bearer '.$this->token,
            'accept' => 'application/json',
            'user-agent' => 'vorgio-php/'.self::VERSION.' php/'.PHP_VERSION,
        ];

        // Auto-attach an Idempotency-Key for state-changing methods unless
        // the caller already provided one.
        if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)
            && ! isset($headers['idempotency-key'])) {
            $finalHeaders['idempotency-key'] = Uuid::v7();
        }

        foreach ($headers as $name => $value) {
            $finalHeaders[$name] = $value;
        }

        $options = [
            'headers' => $finalHeaders,
            'query' => $query,
        ];

        if ($body !== null) {
            $finalHeaders['content-type'] = $finalHeaders['content-type'] ?? 'application/json';
            $options['headers'] = $finalHeaders;
            $options['body'] = json_encode(
                $body,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        $url = $this->buildUrl($path);

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (ConnectException $e) {
            throw new VorgioException(
                'Could not reach Vorgio at '.$url.': '.$e->getMessage(),
                0,
                $e,
            );
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response === null) {
                throw new VorgioException('Vorgio request failed: '.$e->getMessage(), 0, $e);
            }
        } catch (GuzzleException $e) {
            throw new VorgioException('Vorgio request failed: '.$e->getMessage(), 0, $e);
        }

        return $this->parseResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $requestId = $response->getHeaderLine('X-Request-Id') ?: null;

        $decoded = [];
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (! is_array($decoded)) {
                $decoded = [];
            }
        }

        if ($status >= 200 && $status < 300) {
            return $decoded;
        }

        $title = isset($decoded['title']) && is_string($decoded['title'])
            ? $decoded['title']
            : ('HTTP '.$status);

        $detail = isset($decoded['detail']) && is_string($decoded['detail'])
            ? $decoded['detail']
            : '';

        $message = $detail !== '' ? $title.': '.$detail : $title;

        if ($status === 429) {
            $retryAfter = (int) $response->getHeaderLine('Retry-After');
            if ($retryAfter <= 0) {
                $retryAfter = 60;
            }

            throw new VorgioRateLimitedException(
                $message,
                $retryAfter,
                $decoded,
                $rawBody,
                $requestId,
            );
        }

        if ($status === 422) {
            $errors = [];
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                /** @var array<string, array<int, string>> $errors */
                $errors = $decoded['errors'];
            }

            throw new VorgioValidationException(
                $message,
                $errors,
                $decoded,
                $rawBody,
                $requestId,
            );
        }

        throw new VorgioApiException($message, $status, $decoded, $rawBody, $requestId);
    }

    private function buildUrl(string $path): string
    {
        $base = rtrim($this->baseUrl, '/');
        $path = '/'.ltrim($path, '/');

        if (! str_starts_with($path, '/api/')) {
            $path = '/api/'.$this->apiVersion.$path;
        }

        return $base.$path;
    }

    /**
     * Expose the underlying HTTP client (for tests, custom middleware, etc.).
     */
    public function http(): ClientInterface
    {
        return $this->http;
    }
}
