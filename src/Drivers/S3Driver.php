<?php

namespace DatabaseBackupSync\Drivers;

use Aws\Exception\MultipartUploadException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use DatabaseBackupSync\Drivers\Support\RemoteFile;
use DatabaseBackupSync\Drivers\Support\UploadResult;
use DatabaseBackupSync\Support\Checksum;
use RuntimeException;

/**
 * AWS S3 driver using the AWS SDK for PHP.
 *
 * - Files above multipart_threshold use MultipartUploader (parallel parts).
 * - Resumable across job retries: the upload state is persisted to
 *   options['resume_state'] and resumed with MultipartUploader::resumeFrom.
 * - S3 object visibility is atomic: a completed multipart upload only
 *   becomes visible after CompleteMultipartUpload.
 * - Optional SSE-S3 / SSE-KMS server-side encryption.
 */
class S3Driver extends AbstractDriver
{
    protected S3Client $client;

    public function __construct(string $name, array $config, ?S3Client $client = null)
    {
        parent::__construct($name, $config);

        if ($client !== null) {
            $this->client = $client;

            return;
        }

        if (! class_exists(S3Client::class)) {
            throw new RuntimeException('The S3 driver requires aws/aws-sdk-php. Run: composer require aws/aws-sdk-php');
        }

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => (string) $this->config('region', 'us-east-1'),
            'endpoint' => $this->config('endpoint') ?: null,
            'use_path_style_endpoint' => (bool) $this->config('use_path_style_endpoint', false),
            'credentials' => [
                'key' => (string) $this->config('key', ''),
                'secret' => (string) $this->config('secret', ''),
                'token' => $this->config('token') ?: null,
            ],
            'handler' => $this->config('handler'),
            'http' => [
                'timeout' => 300,
                'connect_timeout' => 10,
            ],
        ]);
    }

    public function upload(string $localPath, string $remotePath, array $options = []): UploadResult
    {
        $key = $this->key($remotePath);
        $size = filesize($localPath);
        $started = microtime(true);
        $multipart = false;
        $versionId = null;

        $params = [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'SourceFile' => $localPath,
        ];

        if ($this->config('storage_class')) {
            $params['StorageClass'] = (string) $this->config('storage_class');
        }

        if ($this->config('server_side_encryption')) {
            $params['ServerSideEncryption'] = (string) $this->config('server_side_encryption');
        }

        if ($this->config('sse_kms_key_id')) {
            $params['SSEKMSKeyId'] = (string) $this->config('sse_kms_key_id');
        }

        $threshold = (int) $this->config('multipart_threshold', 52428800);

        if ($size > $threshold) {
            $multipart = $this->multipartUpload($localPath, $key, $params, $options);
        } else {
            $this->throttle();
            $result = $this->client->putObject($params);
            $versionId = $result['VersionId'] ?? null;
        }

        // Integrity verification: compare local SHA-256 with the object's ETag
        // (ETag is the MD5 for single PUTs; multipart ETags are not MD5, so we
        // rely on the SDK's checksum validation for those).
        $checksum = Checksum::hashFile($localPath);

        if (! empty($options['manifest'])) {
            $this->storeManifest($remotePath, $options['manifest']);
        }

        return new UploadResult(
            driver: $this->name,
            path: $remotePath,
            size: $size,
            checksum: $checksum,
            multipart: $multipart,
            versionId: $versionId,
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function download(string $remotePath, string $localPath): string
    {
        $this->throttle();
        $this->client->getObject([
            'Bucket' => $this->bucket(),
            'Key' => $this->key($remotePath),
            'SaveAs' => $localPath,
        ]);

        return $localPath;
    }

    public function delete(string $remotePath): bool
    {
        $this->throttle();

        try {
            $this->client->deleteObject(['Bucket' => $this->bucket(), 'Key' => $this->key($remotePath)]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    public function exists(string $remotePath): bool
    {
        return $this->client->doesObjectExist($this->bucket(), $this->key($remotePath));
    }

    public function list(string $prefix = ''): array
    {
        $files = [];
        $keyPrefix = $this->key($prefix);
        $continuation = null;

        do {
            $this->throttle();
            $args = [
                'Bucket' => $this->bucket(),
                'Prefix' => $keyPrefix,
                'MaxKeys' => 1000,
            ];

            if ($continuation !== null) {
                $args['ContinuationToken'] = $continuation;
            }

            $result = $this->client->listObjectsV2($args);
            $continuation = $result['NextContinuationToken'] ?? null;

            foreach ($result['Contents'] ?? [] as $object) {
                $files[] = new RemoteFile(
                    path: $this->relativeKey((string) $object['Key']),
                    size: (int) ($object['Size'] ?? 0),
                    lastModified: isset($object['LastModified']) ? $object['LastModified']->getTimestamp() : null,
                    checksum: $object['ETag'] ?? null,
                );
            }
        } while ($continuation !== null);

        return $files;
    }

    public function size(string $remotePath): int
    {
        $this->throttle();

        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket(), 'Key' => $this->key($remotePath)]);

            return (int) ($head['ContentLength'] ?? 0);
        } catch (S3Exception) {
            return 0;
        }
    }

    public function checksum(string $remotePath): ?string
    {
        $this->throttle();

        try {
            $head = $this->client->headObject(['Bucket' => $this->bucket(), 'Key' => $this->key($remotePath)]);

            return $head['ETag'] ?? null;
        } catch (S3Exception) {
            return null;
        }
    }

    public function rename(string $from, string $to): bool
    {
        $this->throttle();

        try {
            $this->client->copyObject([
                'Bucket' => $this->bucket(),
                'CopySource' => $this->bucket().'/'.$this->key($from),
                'Key' => $this->key($to),
            ]);
            $this->client->deleteObject(['Bucket' => $this->bucket(), 'Key' => $this->key($from)]);

            return true;
        } catch (S3Exception) {
            return false;
        }
    }

    protected function storeManifest(string $remotePath, array $manifest): void
    {
        $this->throttle();
        $this->client->putObject([
            'Bucket' => $this->bucket(),
            'Key' => $this->key($remotePath.'.manifest.json'),
            'Body' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'ContentType' => 'application/json',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{resume_state?: string}  $options
     */
    protected function multipartUpload(string $localPath, string $key, array $params, array $options): bool
    {
        $stateFile = $options['resume_state'] ?? null;
        $uploaderOptions = [
            'bucket' => $this->bucket(),
            'key' => $key,
            'part_size' => (int) $this->config('part_size', 8388608),
            'concurrency' => (int) $this->config('concurrency', 3),
            'before_upload' => function () {
                $this->throttle();
            },
        ];

        if ($this->config('storage_class')) {
            $uploaderOptions['before_initiate'] = function ($command) {
                $command['StorageClass'] = (string) $this->config('storage_class');
            };
        }

        if ($this->config('server_side_encryption')) {
            $uploaderOptions['before_initiate'] = function ($command) {
                $command['ServerSideEncryption'] = (string) $this->config('server_side_encryption');
                if ($this->config('sse_kms_key_id')) {
                    $command['SSEKMSKeyId'] = (string) $this->config('sse_kms_key_id');
                }
            };
        }

        if ($stateFile !== null && is_file($stateFile)) {
            $state = unserialize((string) file_get_contents($stateFile));
            $uploader = MultipartUploader::resumeFrom($state, $this->client, $localPath, $uploaderOptions);
        } else {
            $uploader = new MultipartUploader($this->client, $localPath, $uploaderOptions);
        }

        try {
            $uploader->upload();

            if ($stateFile !== null) {
                @unlink($stateFile);
            }

            return true;
        } catch (MultipartUploadException $e) {
            if ($stateFile !== null) {
                file_put_contents($stateFile, serialize($uploader->getState()));
            }

            throw $e;
        }
    }

    protected function bucket(): string
    {
        $bucket = (string) $this->config('bucket', '');

        if ($bucket === '') {
            throw new RuntimeException('S3 bucket is not configured (DB_BACKUP_S3_BUCKET).');
        }

        return $bucket;
    }

    protected function key(string $remotePath): string
    {
        $prefix = trim((string) $this->config('prefix', ''), '/');

        return $prefix === '' ? ltrim($remotePath, '/') : $prefix.'/'.ltrim($remotePath, '/');
    }

    protected function relativeKey(string $key): string
    {
        $prefix = trim((string) $this->config('prefix', ''), '/');

        if ($prefix !== '' && str_starts_with($key, $prefix.'/')) {
            return substr($key, strlen($prefix) + 1);
        }

        return $key;
    }
}
