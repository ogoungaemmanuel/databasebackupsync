<?php

namespace DatabaseBackupSync\Encryption;

use DatabaseBackupSync\Exceptions\DumpFailedException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * GPG encryption via the gpg binary. Supports symmetric (passphrase) and
 * recipient (public key) modes. Requires the gpg binary on PATH.
 */
class GpgEncryptor
{
    /**
     * @param  array{binary?: string, recipients?: array<int, string>, passphrase?: string, cipher_algo?: string}  $config
     */
    public function __construct(protected array $config)
    {
    }

    public function encrypt(string $inputPath, string $outputPath): string
    {
        $binary = $this->binary();
        $recipients = $this->config['recipients'] ?? [];

        if ($recipients !== []) {
            $command = sprintf(
                '%s --batch --yes --encrypt --trust-model always %s --output %s %s',
                escapeshellarg($binary),
                implode(' ', array_map(fn ($r) => '--recipient '.escapeshellarg($r), $recipients)),
                escapeshellarg($outputPath),
                escapeshellarg($inputPath)
            );
        } else {
            $passphraseFile = $this->writePassphraseFile();
            $command = sprintf(
                '%s --batch --yes --symmetric --cipher-algo %s --passphrase-file %s --output %s %s',
                escapeshellarg($binary),
                escapeshellarg($this->config['cipher_algo'] ?? 'AES256'),
                escapeshellarg($passphraseFile),
                escapeshellarg($outputPath),
                escapeshellarg($inputPath)
            );
            @unlink($passphraseFile);
        }

        $this->run($command);

        return $outputPath;
    }

    public function decrypt(string $inputPath, string $outputPath): string
    {
        $binary = $this->binary();
        $passphrase = $this->config['passphrase'] ?? null;
        $passphraseFile = null;

        if ($passphrase !== null && $passphrase !== '') {
            $passphraseFile = $this->writePassphraseFile();
        }

        $command = sprintf(
            '%s --batch --yes --decrypt %s --output %s %s',
            escapeshellarg($binary),
            $passphraseFile !== null ? '--passphrase-file '.escapeshellarg($passphraseFile) : '',
            escapeshellarg($outputPath),
            escapeshellarg($inputPath)
        );

        try {
            $this->run($command);
        } finally {
            if ($passphraseFile !== null) {
                @unlink($passphraseFile);
            }
        }

        return $outputPath;
    }

    protected function binary(): string
    {
        return (string) ($this->config['binary'] ?? 'gpg');
    }

    protected function writePassphraseFile(): string
    {
        $passphrase = (string) ($this->config['passphrase'] ?? '');

        if ($passphrase === '') {
            throw new RuntimeException('GPG symmetric encryption requires a passphrase (DB_BACKUP_GPG_PASSPHRASE) or recipients.');
        }

        $file = tempnam(sys_get_temp_dir(), 'dbsync-gpg-');
        file_put_contents($file, $passphrase);
        @chmod($file, 0600);

        return $file;
    }

    protected function run(string $command): void
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new DumpFailedException(sprintf(
                "GPG operation failed.\nCommand: %s\nExit code: %d\nError: %s",
                $command,
                $process->getExitCode() ?? -1,
                trim($process->getErrorOutput())
            ));
        }
    }
}
