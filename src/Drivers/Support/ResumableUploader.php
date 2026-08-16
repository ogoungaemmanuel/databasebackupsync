<?php

namespace DatabaseBackupSync\Drivers\Support;

use DatabaseBackupSync\Exceptions\UploadFailedException;
use DatabaseBackupSync\Support\RateLimiter;
use DatabaseBackupSync\Support\RetryPolicy;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

/**
 * Chunked resumable upload (Google Drive / OneDrive protocol):
 * PUT chunks with Content-Range headers; 308 = continue, 200/201 = complete,
 * 429/5xx = back off (honoring Retry-After) and re-query progress.
 */
class ResumableUploader
{
    protected ?float $lastCall = null;

    public function __construct(
        protected Client $http,
        protected string $sessionUri,
        protected string $localPath,
        protected int $chunkSize,
        protected int $maxAttempts = 5,
        protected int $requestsPerSecond = 5,
    ) {
    }

    /**
     * @return array{size: int, checksum: ?string, id: ?string}
     */
    public function upload(): array
    {
        $total = filesize($this->localPath);
        $handle = fopen($this->localPath, 'rb');

        if ($handle === false) {
            throw new UploadFailedException('Unable to open local file for resumable upload.', 'resumable', $this->localPath);
        }

        try {
            $offset = $this->queryProgress();
            fseek($handle, $offset);
            $attempt = 0;

            while ($offset < $total) {
                RateLimiter::throttle($this->requestsPerSecond, $this->lastCall);

                $chunk = fread($handle, $this->chunkSize);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $end = min($offset + strlen($chunk) - 1, $total - 1);
                $contentRange = sprintf('bytes %d-%d/%d', $offset, $end, $total);

                $response = $this->sendChunk($contentRange, $chunk);

                if ($response === null) {
                    // Transient failure; back off and re-query progress.
                    $attempt++;
                    if (! RetryPolicy::canRetry($attempt, $this->maxAttempts)) {
                        throw new UploadFailedException(
                            "Resumable upload exceeded {$this->maxAttempts} attempts.",
                            'resumable',
                            $this->localPath,
                            $attempt
                        );
                    }
                    usleep(RetryPolicy::delay($attempt, 1000, 30000) * 1000);
                    $offset = $this->queryProgress();
                    fseek($handle, $offset);
                    continue;
                }

                $status = $response->getStatusCode();

                if ($status === 200 || $status === 201) {
                    $data = HttpClientFactory::json($response);

                    return [
                        'size' => $total,
                        'checksum' => (string) ($data['md5Checksum'] ?? $data['sha1Hash'] ?? $data['file']['hashes']['sha1Hash'] ?? '') ?: null,
                        'id' => isset($data['id']) ? (string) $data['id'] : null,
                    ];
                }

                if ($status === 308) {
                    $offset = $this->nextOffset($response, $offset, strlen($chunk));
                    fseek($handle, $offset);
                    continue;
                }

                throw new UploadFailedException(
                    sprintf('Resumable upload returned unexpected HTTP %d.', $status),
                    'resumable',
                    $this->localPath
                );
            }

            return ['size' => $total, 'checksum' => null, 'id' => null];
        } finally {
            fclose($handle);
        }
    }

    protected function sendChunk(string $contentRange, string $chunk): ?ResponseInterface
    {
        try {
            return $this->http->put($this->sessionUri, [
                'headers' => [
                    'Content-Range' => $contentRange,
                    'Content-Length' => (string) strlen($chunk),
                ],
                'body' => $chunk,
            ]);
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if ($response === null) {
                return null; // connection-level failure → retry
            }

            $status = $response->getStatusCode();

            if ($status === 429 || $status >= 500) {
                $retryAfter = RetryPolicy::retryAfterSeconds($response->getHeaderLine('Retry-After'), 5);
                usleep($retryAfter * 1000000);

                return null;
            }

            if ($status === 308) {
                return $response;
            }

            throw new UploadFailedException(
                'Resumable upload failed: '.HttpClientFactory::errorMessage($e),
                'resumable',
                $this->localPath
            );
        }
    }

    protected function nextOffset(ResponseInterface $response, int $currentOffset, int $chunkLength): int
    {
        $range = $response->getHeaderLine('Range');

        if (preg_match('/bytes=0-(\d+)/', $range, $m)) {
            return (int) $m[1] + 1;
        }

        return $currentOffset + $chunkLength;
    }

    protected function queryProgress(): int
    {
        try {
            $response = $this->http->put($this->sessionUri, [
                'headers' => ['Content-Range' => 'bytes */*', 'Content-Length' => '0'],
            ]);

            if ($response->getStatusCode() === 308) {
                $range = $response->getHeaderLine('Range');

                if (preg_match('/bytes=0-(\d+)/', $range, $m)) {
                    return (int) $m[1] + 1;
                }
            }
        } catch (RequestException) {
            // Session may have expired; caller recreates it.
        }

        return 0;
    }
}
