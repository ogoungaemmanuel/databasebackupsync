<?php

namespace DatabaseBackupSync\Support;

class FilenameGenerator
{
    /**
     * @param  array{prefix?: string, date_format?: string}  $config
     */
    public static function make(?string $connection, array $config, bool $encrypted): string
    {
        $prefix = $config['prefix'] ?? 'backup';
        $format = $config['date_format'] ?? 'Y-m-d_H-i-s';
        $connection = $connection !== null ? str_replace(['.', '\\', '/', ' '], '-', $connection) : 'default';

        $name = sprintf(
            '%s-%s-%s-%s.sql',
            $prefix,
            $connection,
            date($format),
            substr(bin2hex(random_bytes(4)), 0, 8)
        );

        if ($encrypted) {
            $name .= '.enc';
        }

        return $name;
    }
}
