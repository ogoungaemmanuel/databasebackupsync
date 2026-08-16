<?php

namespace DatabaseBackupSync\Support;

class PruneResult
{
    /**
     * @param  array<int, array{driver: string, path: string, size: int}>  $pruned
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public readonly array $pruned,
        public readonly array $errors,
    ) {
    }

    public function prunedCount(): int
    {
        return count($this->pruned);
    }

    public function prunedBytes(): int
    {
        return array_sum(array_column($this->pruned, 'size'));
    }
}
