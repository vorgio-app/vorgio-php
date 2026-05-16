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
use Vorgio\Resource\Subscriptions;
use Vorgio\Support\RetryPolicy;
use Vorgio\Util\Uuid;

/**
 * Entry-point for the Vorgio SDK.
 *
 * <code>
 * $vorgio = new VorgioClient(
 *     token: 'act_…',
 *     baseUrl: 'https://vorgio.app',
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
    public const VERSION = '0.2.0';

    /**
     * Default request timeout in seconds. Overridable per call via the
     * `timeout` option.
     */
    public const DEFAULT_TIMEOUT = 30.0;

    private ClientInterface $http;

    public readonly RetryPolicy $retry;

    public function __construct(
        public readonly string $token,
        public readonly string $baseUrl = 'https://vorgio.app',
        ?ClientInterface $httpClient = null,
        public readonly float $timeout = self::DEFAULT_TIMEOUT,
        public readonly string $apiVersion = 'v1',
        ?RetryPolicy $retry = null,
    ) {
        if (trim($token) === '') {
            throw new VorgioException('Vorgio API token cannot be empty.');
        }

        $this->http = $httpClient ?? new GuzzleClient([
            'timeout' => $timeout,
            'connect_timeout' => 10.0,
            'http_errors' => false,
        ]);

        $this->retry = $retry ?? new RetryPolicy();
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

    public function subscriptions(): Subscriptions
    {
        return new Subscriptions($this);
    }

    /**
     * Perform an authenticated request and return the decoded JSON body.
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
        $response = $this->sendRequest($method, $path, $body, $query, $headers);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $this->throwForResponse($response);
        }

        $rawBody = (string) $response->getBody();
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Perform an authenticated request and return the raw response, without
     * JSON decoding. Useful for binary endpoints like
     * `GET /v1/invoices/{id}/pdf`.
     *
     * @param  array<string, mixed>  $query  Query string parameters
     * @param  array<string, string>  $headers  Extra request headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function requestRaw(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
    ): array {
        $response = $this->sendRequest($method, $path, null, $query, $headers);
        $status = $response->getStatusCode();

        // 304 Not Modified is a legitimate non-error response for ETag-aware
        // endpoints, so don't lump it in with 4xx/5xx.
        if ($status >= 400) {
            $this->throwForResponse($response);
        }

        $headerMap = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headerMap[strtolower($name)] = $values[0] ?? '';
        }

        return [
            'status' => $status,
            'headers' => $headerMap,
            'body' => (string) $response->getBody(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    private function sendRequest(
        string $method,
        string $path,
        ?array $body,
        array $query,
        array $headers,
    ): ResponseInterface {
        $method = strtoupper($method);

        $headers = array_change_key_case($headers, CASE_LOWER);

        $finalHeaders = [
            'authorization' => 'Bearer '.$this->token,
            'accept' => 'application/json',
            'user-agent' => 'vorgio-php/'.self::VERSION.' php/'.PHP_VERSION,
        ];

        // Auto-attach an Idempotency-Key for state-changing methods unless
        // the caller already provided one. Computed once outside the retry
        // loop so every attempt sends the same key — that's exactly what
        // lets the Vorgio middleware replay the cached 2xx.
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

        return $this->dispatchWithRetry($method, $url, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function dispatchWithRetry(string $method, string $url, array $options): ResponseInterface
    {
        $attempt = 0;
        $lastResponse = null;
        $lastTransportError = null;

        while (true) {
            try {
                $response = $this->http->request($method, $url, $options);
                $status = $response->getStatusCode();

                if (! $this->retry->shouldRetry($status, $attempt)) {
                    return $response;
                }

                $lastResponse = $response;
            } catch (ConnectException $e) {
                if (! $this->retry->shouldRetry(null, $attempt)) {
                    throw new VorgioException(
                        'Could not reach Vorgio at '.$url.': '.$e->getMessage(),
                        0,
                        $e,
                    );
                }

                $lastTransportError = $e;
            } catch (RequestException $e) {
                // RequestException with a response: treat like a normal HTTP
                // result and let the retry policy decide.
                $response = $e->getResponse();
                if ($response === null) {
                    throw new VorgioException('Vorgio request failed: '.$e->getMessage(), 0, $e);
                }

                if (! $this->retry->shouldRetry($response->getStatusCode(), $attempt)) {
                    return $response;
                }

                $lastResponse = $response;
            } catch (GuzzleException $e) {
                throw new VorgioException('Vorgio request failed: '.$e->getMessage(), 0, $e);
            }

            $this->sleep($this->retry->delayMs($attempt));
            $attempt++;

            // Defensive: if shouldRetry returned true but we somehow exceeded
            // the cap, surface whatever we last saw rather than looping.
            if ($attempt > $this->retry->maxAttempts()) {
                if ($lastResponse !== null) {
                    return $lastResponse;
                }

                throw new VorgioException(
                    'Could not reach Vorgio at '.$url.' after '.$this->retry->maxAttempts().' retries.',
                    0,
                    $lastTransportError,
                );
            }
        }
    }

    /**
     * Sleep hook — separated so tests can subclass and stub it out.
     */
    protected function sleep(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function throwForResponse(ResponseInterface $response): never
    {
        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $requestId = $response->getHeaderLine('X-Request-Id') ?: null;

        $decoded = [];
        if ($rawBody !== '') {
            $candidate = json_decode($rawBody, true);
            if (is_array($candidate)) {
                $decoded = $candidate;
            }
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
