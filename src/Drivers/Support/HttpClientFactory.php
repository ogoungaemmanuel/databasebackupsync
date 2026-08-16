<?php

namespace DatabaseBackupSync\Drivers\Support;

use DatabaseBackupSync\Support\RetryPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpClientFactory
{
    /**
     * Build a Guzzle client with automatic retry for transient failures
     * (connection errors, 429 rate limits, 5xx) using exponential backoff
     * and honoring Retry-After headers.
     *
     * @param  array<string, mixed>  $config
     */
    public static function make(array $config = []): Client
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?\Throwable $exception = null): bool {
                if ($retries >= (int) ($config['max_retries'] ?? 5)) {
                    return false;
                }

                if ($exception instanceof ConnectException) {
                    return true;
                }

                if ($response !== null && ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500)) {
                    return true;
                }

                return false;
            },
            function (int $retries, ?ResponseInterface $response = null): int {
                if ($response !== null && $response->hasHeader('Retry-After')) {
                    return RetryPolicy::retryAfterSeconds($response->getHeaderLine('Retry-After'), 5) * 1000;
                }

                return RetryPolicy::delay($retries + 1, (int) ($config['backoff_base_ms'] ?? 1000), (int) ($config['backoff_max_ms'] ?? 30000));
            }
        ));

        return new Client([
            'handler' => $stack,
            'timeout' => (float) ($config['timeout'] ?? 300),
            'connect_timeout' => (float) ($config['connect_timeout'] ?? 10),
            'http_errors' => true,
            'allow_redirects' => ['max' => 5, 'strict' => false, 'referer' => false, 'protocols' => ['https', 'http']],
        ]);
    }

    /**
     * Extract a JSON body from a response, tolerating empty bodies.
     *
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Extract the error message from a Guzzle request exception.
     */
    public static function errorMessage(RequestException $e): string
    {
        $response = $e->getResponse();

        if ($response === null) {
            return $e->getMessage();
        }

        $data = self::json($response);

        return (string) ($data['error']['message'] ?? $data['error_description'] ?? $data['message'] ?? $e->getMessage());
    }
}
