<?php

namespace DatabaseBackupSync\Encryption;

use DatabaseBackupSync\Exceptions\EncryptionException;
use Illuminate\Contracts\Container\Container;

class EncryptionManager
{
    public function __construct(protected Container $app)
    {
    }

    /**
     * Encrypt a file into $tempDir, returning the new path.
     */
    public function encryptFile(string $inputPath, string $tempDir): string
    {
        $config = $this->app['config']->get('database-backup.encryption', []);
        $output = $tempDir.'/'.basename($inputPath).'.enc';

        if (! empty($config['gpg']['enabled'])) {
            $gpg = new GpgEncryptor($config['gpg']);

            return $gpg->encrypt($inputPath, $output);
        }

        $key = $config['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw EncryptionException::missingKey();
        }

        $encryptor = new Encryptor($key, (int) ($config['chunk_size'] ?? 1048576));

        return $encryptor->encrypt($inputPath, $output);
    }

    /**
     * Decrypt a file into $tempDir, returning the new path.
     */
    public function decryptFile(string $inputPath, string $tempDir): string
    {
        $config = $this->app['config']->get('database-backup.encryption', []);
        $output = $tempDir.'/'.basename($inputPath).'.dec';

        if (! empty($config['gpg']['enabled'])) {
            $gpg = new GpgEncryptor($config['gpg']);

            return $gpg->decrypt($inputPath, $output);
        }

        $key = $config['key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw EncryptionException::missingKey();
        }

        $encryptor = new Encryptor($key, (int) ($config['chunk_size'] ?? 1048576));

        return $encryptor->decrypt($inputPath, $output);
    }
}
