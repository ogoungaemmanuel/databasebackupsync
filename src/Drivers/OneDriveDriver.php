<?php

namespace DatabaseBackupSync\Drivers;

use DatabaseBackupSync\Drivers\Support\HttpClientFactory;
use DatabaseBackupSync\Drivers\Support\OneDriveAuth;
use DatabaseBackupSync\Drivers\Support\RemoteFile;
use DatabaseBackupSync\Drivers\Support\ResumableUploader;
use DatabaseBackupSync\Drivers\Support\UploadResult;
use DatabaseBackupSync\Support\Checksum;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * OneDrive driver (Microsoft Graph API) with resumable uploads.
 *
 * Auth: client credentials (app-only, recommended) or authorization code.
 * Uploads use createUploadSession; the item only becomes visible when the
 * final chunk completes, so uploads are atomic.
 */
class OneDriveDriver extends AbstractDriver
{
    protected Client $http;

    protected OneDriveAuth $auth;

    public function __construct(string $name, array $config, ?Client $http = null, ?OneDriveAuth $auth = null)
    {
        parent::__construct($name, $config);
        $this->http = $http ?? HttpClientFactory::make([
            'timeout' => (int) $this->config('timeout', 300),
            'max_retries' => 5,
        ]);
        $this->auth = $auth ?? new OneDriveAuth($config);
    }

    public function upload(string $localPath, string $remotePath, array $options = []): UploadResult
    {
        $started = microtime(true);
        $size = filesize($localPath);
        $itemPath = $this->itemPath($remotePath);

        // 1. Create the upload session.
        $response = $this->http->post($this->graph("root:/{$itemPath}:/createUploadSession"), [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'json' => [
                'item' => ['@microsoft.graph.conflictBehavior' => 'replace'],
            ],
        ]);

        $data = HttpClientFactory::json($response);
        $uploadUrl = (string) ($data['uploadUrl'] ?? '');

        if ($uploadUrl === '') {
            throw new RuntimeException('OneDrive createUploadSession did not return an uploadUrl.');
        }

        // 2. Upload chunks (resumable).
        $uploader = new ResumableUploader(
            $this->http,
            $uploadUrl,
            $localPath,
            (int) $this->config('chunk_size', 10485760),
            maxAttempts: 5,
            requestsPerSecond: (int) $this->config('requests_per_second', 5),
        );

        $result = $uploader->upload();

        // 3. Verify integrity via the item's sha1Hash.
        $checksum = Checksum::hashFile($localPath);
        $remoteChecksum = $result['checksum'];

        if ($remoteChecksum !== null && ! Checksum::verify($remoteChecksum, $checksum)) {
            throw new RuntimeException(sprintf(
                'OneDrive integrity check failed for [%s]: local %s != remote %s',
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
        $this->http->get($this->graph("root:/{$this->itemPath($remotePath)}:/content"), [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'sink' => $localPath,
        ]);

        return $localPath;
    }

    public function delete(string $remotePath): bool
    {
        $id = $this->findItemId($remotePath);

        if ($id === null) {
            return false;
        }

        $this->http->delete($this->graph("items/{$id}"), [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
        ]);

        return true;
    }

    public function exists(string $remotePath): bool
    {
        return $this->findItemId($remotePath) !== null;
    }

    public function list(string $prefix = ''): array
    {
        $files = [];
        $url = $this->graph("root:/{$this->folderPath()}:/children?\$select=name,size,lastModifiedDateTime,file&\$top=999");

        while ($url !== null) {
            $response = $this->http->get($url, [
                'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            ]);

            $data = HttpClientFactory::json($response);

            foreach ($data['value'] ?? [] as $item) {
                if (isset($item['file'])) {
                    $files[] = new RemoteFile(
                        path: (string) $item['name'],
                        size: (int) ($item['size'] ?? 0),
                        lastModified: isset($item['lastModifiedDateTime']) ? strtotime((string) $item['lastModifiedDateTime']) : null,
                        checksum: $item['file']['hashes']['sha1Hash'] ?? null,
                    );
                }
            }

            $url = isset($data['@odata.nextLink']) ? (string) $data['@odata.nextLink'] : null;
        }

        return $files;
    }

    public function size(string $remotePath): int
    {
        $id = $this->findItemId($remotePath);

        if ($id === null) {
            return 0;
        }

        $data = $this->itemMetadata($id);

        return (int) ($data['size'] ?? 0);
    }

    public function checksum(string $remotePath): ?string
    {
        $id = $this->findItemId($remotePath);

        if ($id === null) {
            return null;
        }

        $data = $this->itemMetadata($id);

        return $data['file']['hashes']['sha1Hash'] ?? null;
    }

    public function rename(string $from, string $to): bool
    {
        $id = $this->findItemId($from);

        if ($id === null) {
            return false;
        }

        $this->http->patch($this->graph("items/{$id}"), [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            'json' => ['name' => basename($to)],
        ]);

        return true;
    }

    protected function storeManifest(string $remotePath, array $manifest): void
    {
        $this->http->put($this->graph("root:/{$this->itemPath($remotePath.'.manifest.json')}:/content"), [
            'headers' => [
                'Authorization' => 'Bearer '.$this->auth->accessToken(),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);
    }

    protected function graph(string $path): string
    {
        $drive = (string) $this->config('drive', 'me');

        return "https://graph.microsoft.com/v1.0/{$drive}/{$path}";
    }

    protected function folderPath(): string
    {
        return trim((string) $this->config('folder_path', 'backups'), '/');
    }

    protected function itemPath(string $remotePath): string
    {
        $folder = $this->folderPath();

        return $folder === '' ? basename($remotePath) : $folder.'/'.basename($remotePath);
    }

    protected function findItemId(string $remotePath): ?string
    {
        try {
            $response = $this->http->get($this->graph("root:/{$this->itemPath($remotePath)}"), [
                'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
            ]);

            $data = HttpClientFactory::json($response);

            return isset($data['id']) ? (string) $data['id'] : null;
        } catch (RequestException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemMetadata(string $id): array
    {
        $response = $this->http->get($this->graph("items/{$id}?\$select=id,name,size,file"), [
            'headers' => ['Authorization' => 'Bearer '.$this->auth->accessToken()],
        ]);

        return HttpClientFactory::json($response);
    }
}
