<?php

namespace DatabaseBackupSync\Drivers;

use DatabaseBackupSync\Drivers\Support\GoogleDriveAuth;
use DatabaseBackupSync\Drivers\Support\HttpClientFactory;
use DatabaseBackupSync\Drivers\Support\RemoteFile;
use DatabaseBackupSync\Drivers\Support\ResumableUploader;
use DatabaseBackupSync\Drivers\Support\UploadResult;
use DatabaseBackupSync\Support\Checksum;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * Google Drive driver (Drive v3 API) with resumable uploads.
 *
 * Auth: service account (JWT, recommended) or OAuth2 refresh token.
 * Uploads use the resumable session protocol; the file only becomes visible
 * when the final chunk completes, so uploads are atomic.
 */
class GoogleDriveDriver extends AbstractDriver
{
    protected Client $http;

    protected GoogleDriveAuth $auth;

    public function __construct(string $name, array $config, ?Client $http = null, ?GoogleDriveAuth $auth = null)
    {
        parent::__construct($name, $config);
        $this->http = $http ?? HttpClientFactory::make([
            'timeout' => (int) $this->config('timeout', 300),
            'max_retries' => 5,
        ]);
        $this->auth = $auth ?? new GoogleDriveAuth($config);
    }

    public function upload(string $localPath, string $remotePath, array $options = []): UploadResult
    {
        $started = microtime(true);
        $size = filesize($localPath);

        // 1. Create the resumable session.
        $response = $this->http->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->auth->accessToken(),
                'X-Upload-Content-Type' => 'application/octet-stream',
                'X-Upload-Content-Length' => (string) $size,
            ],
            'json' => [
                'name' => basename($remotePath),
                'parents' => $this->folderId() !== null ? [$this->folderId()] : [],
            ],
        ]);

        $sessionUri = $response->getHeaderLine('Location');

        if ($sessionUri === '') {
            throw new RuntimeException('Google Drive resumable upload did not return a session URI.');
        }

        // 2. Upload chunks (resumable).
        $uploader = new ResumableUploader(
            $this->http,
            $sessionUri,
            $localPath,
            (int) $this->config('chunk_size', 8388608),
            maxAttempts: 5,
            requestsPerSecond: (int) $this->config('requests_per_second', 5),
        );

        $result = $uploader->upload();

        // 3. Verify integrity via the file metadata.
        $checksum = Checksum::hashFile($localPath);
        $remoteChecksum = $this->fileChecksum($result['id']);

        if ($remoteChecksum !== null && ! Checksum::verify($remoteChecksum, $checksum)) {
            throw new RuntimeException(sprintf(
                'Google Drive integrity check failed for [%s]: local %s != remote %s',
                $remotePath,
                $checksum,
                $remoteChecksum
            ));
        }

        if (! empty($options['manifest'])) {
            $this->storeManifest($remotePath, $options['manifest']);
        }

        return new UploadResult(
            driver: $this->name,
            path: $remotePath,
            size: $size,
            checksum: $checksum,
            multipart: true,
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function download(string $remotePath, string $localPath): string
    {
        $id = $this->findFileId($remotePath);

        if ($id === null) {
            throw new RuntimeException("Google Drive file [{$remotePath}] not found.");
        }

        $this->http->get("https://www.googleapis.com/drive/v3/files/{$id}?alt=media", [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'sink' => $localPath,
        ]);

        return $localPath;
    }

    public function delete(string $remotePath): bool
    {
        $id = $this->findFileId($remotePath);

        if ($id === null) {
            return false;
        }

        $this->http->delete("https://www.googleapis.com/drive/v3/files/{$id}", [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
        ]);

        return true;
    }

    public function exists(string $remotePath): bool
    {
        return $this->findFileId($remotePath) !== null;
    }

    public function list(string $prefix = ''): array
    {
        $files = [];
        $pageToken = null;

        do {
            $query = [
                'q' => sprintf("'%s' in parents and trashed = false", $this->folderId() ?? 'root'),
                'fields' => 'nextPageToken,files(id,name,size,modifiedTime,md5Checksum)',
                'pageSize' => 1000,
                'orderBy' => 'modifiedTime desc',
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->http->get('https://www.googleapis.com/drive/v3/files', [
                'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
                'query' => $query,
            ]);

            $data = HttpClientFactory::json($response);
            $pageToken = $data['nextPageToken'] ?? null;

            foreach ($data['files'] ?? [] as $file) {
                $files[] = new RemoteFile(
                    path: (string) $file['name'],
                    size: (int) ($file['size'] ?? 0),
                    lastModified: isset($file['modifiedTime']) ? strtotime((string) $file['modifiedTime']) : null,
                    checksum: $file['md5Checksum'] ?? null,
                );
            }
        } while ($pageToken !== null);

        return $files;
    }

    public function size(string $remotePath): int
    {
        $id = $this->findFileId($remotePath);

        if ($id === null) {
            return 0;
        }

        $data = $this->fileMetadata($id);

        return (int) ($data['size'] ?? 0);
    }

    public function checksum(string $remotePath): ?string
    {
        $id = $this->findFileId($remotePath);

        if ($id === null) {
            return null;
        }

        return $this->fileChecksum($id);
    }

    public function rename(string $from, string $to): bool
    {
        $id = $this->findFileId($from);

        if ($id === null) {
            return false;
        }

        $this->http->patch("https://www.googleapis.com/drive/v3/files/{$id}", [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'json' => ['name' => basename($to)],
        ]);

        return true;
    }

    protected function storeManifest(string $remotePath, array $manifest): void
    {
        $this->http->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=media', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->auth->accessToken(),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'name' => basename($remotePath).'.manifest.json',
                'parents' => $this->folderId() !== null ? [$this->folderId()] : [],
            ],
            'body' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected function folderId(): ?string
    {
        $id = $this->config('folder_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function findFileId(string $remotePath): ?string
    {
        $response = $this->http->get('https://www.googleapis.com/drive/v3/files', [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'query' => [
                'q' => sprintf(
                    "'%s' in parents and trashed = false and name = %s",
                    $this->folderId() ?? 'root',
                    json_encode(basename($remotePath))
                ),
                'fields' => 'files(id,name)',
                'pageSize' => 1,
            ],
        ]);

        $data = HttpClientFactory::json($response);

        return isset($data['files'][0]['id']) ? (string) $data['files'][0]['id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fileMetadata(string $id): array
    {
        $response = $this->http->get("https://www.googleapis.com/drive/v3/files/{$id}", [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'query' => ['fields' => 'id,name,size,md5Checksum'],
        ]);

        return HttpClientFactory::json($response);
    }

    protected function fileChecksum(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        try {
            $data = $this->fileMetadata($id);

            return isset($data['md5Checksum']) ? (string) $data['md5Checksum'] : null;
        } catch (RequestException) {
            return null;
        }
    }
}
