<?php

namespace DatabaseBackupSync\Drivers;

use DatabaseBackupSync\Drivers\Support\RemoteFile;
use DatabaseBackupSync\Drivers\Support\UploadResult;
use DatabaseBackupSync\Support\AtomicFile;
use DatabaseBackupSync\Support\Checksum;
use RuntimeException;

class LocalDriver extends AbstractDriver
{
    public function upload(string $localPath, string $remotePath, array $options = []): UploadResult
    {
        $this->throttle();
        $target = $this->absolutePath($remotePath);
        $started = microtime(true);

        AtomicFile::move($localPath, $target);

        if (! empty($options['manifest'])) {
            $this->storeManifest($remotePath, $options['manifest']);
        }

        return new UploadResult(
            driver: $this->name,
            path: $remotePath,
            size: filesize($target),
            checksum: Checksum::hashFile($target),
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );
    }

    public function download(string $remotePath, string $localPath): string
    {
        $this->throttle();
        $source = $this->absolutePath($remotePath);

        if (! is_file($source)) {
            throw new RuntimeException("Local backup [{$remotePath}] does not exist.");
        }

        AtomicFile::move($source, $localPath);

        return $localPath;
    }

    public function delete(string $remotePath): bool
    {
        $this->throttle();
        $path = $this->absolutePath($remotePath);

        if (! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    public function exists(string $remotePath): bool
    {
        return is_file($this->absolutePath($remotePath));
    }

    public function list(string $prefix = ''): array
    {
        $root = $this->absolutePath($prefix);
        $files = [];

        if (! is_dir($root)) {
            return $files;
        }

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->root()))), '/');
            $files[] = new RemoteFile(
                path: $relative,
                size: $file->getSize(),
                lastModified: $file->getMTime(),
                checksum: null,
            );
        }

        usort($files, fn (RemoteFile $a, RemoteFile $b) => ($b->lastModified ?? 0) <=> ($a->lastModified ?? 0));

        return $files;
    }

    public function size(string $remotePath): int
    {
        $path = $this->absolutePath($remotePath);

        return is_file($path) ? filesize($path) : 0;
    }

    public function checksum(string $remotePath): ?string
    {
        $path = $this->absolutePath($remotePath);

        return is_file($path) ? Checksum::hashFile($path) : null;
    }

    public function rename(string $from, string $to): bool
    {
        $this->throttle();
        $source = $this->absolutePath($from);
        $target = $this->absolutePath($to);

        if (! is_file($source)) {
            return false;
        }

        return @rename($source, $target);
    }

    protected function storeManifest(string $remotePath, array $manifest): void
    {
        AtomicFile::write(
            $this->absolutePath($remotePath.'.manifest.json'),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            (int) $this->config('permissions', 0640)
        );
    }

    protected function root(): string
    {
        return rtrim((string) $this->config('root', storage_path('app/database-backup')), '/\\');
    }

    protected function absolutePath(string $remotePath): string
    {
        $path = $this->root().'/'.ltrim(str_replace('\\', '/', $remotePath), '/');

        // Prevent path traversal.
        if (str_contains($path, '..')) {
            throw new RuntimeException("Invalid backup path [{$remotePath}].");
        }

        return $path;
    }
}
