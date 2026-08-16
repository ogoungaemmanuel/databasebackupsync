<?php

namespace DatabaseBackupSync\Support;

class Manifest
{
    public const VERSION = 1;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data)
    {
    }

    public static function create(
        string $file,
        int $size,
        string $checksum,
        ?string $connection,
        bool $encrypted,
        string $dumper,
    ): self {
        return new self([
            'version' => self::VERSION,
            'file' => $file,
            'size' => $size,
            'sha256' => $checksum,
            'connection' => $connection,
            'encrypted' => $encrypted,
            'dumper' => $dumper,
            'created_at' => date('c'),
            'generator' => 'database-backup-sync',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new \RuntimeException('Invalid backup manifest JSON.');
        }

        return new self($data);
    }
}
