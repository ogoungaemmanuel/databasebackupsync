<?php

namespace DatabaseBackupSync\Tests\Unit;

use DatabaseBackupSync\Support\AtomicFile;
use DatabaseBackupSync\Tests\TestCase;

class AtomicFileTest extends TestCase
{
    public function test_move_creates_the_destination_directory(): void
    {
        $from = $this->tmpDir.'/source.txt';
        $to = $this->tmpDir.'/nested/deeper/target.txt';

        file_put_contents($from, 'hello');

        AtomicFile::move($from, $to);

        $this->assertFileExists($to);
        $this->assertSame('hello', file_get_contents($to));
        $this->assertFileDoesNotExist($from);
    }

    public function test_move_overwrites_an_existing_file(): void
    {
        $from = $this->tmpDir.'/source.txt';
        $to = $this->tmpDir.'/target.txt';

        file_put_contents($from, 'new');
        file_put_contents($to, 'old');

        AtomicFile::move($from, $to);

        $this->assertSame('new', file_get_contents($to));
        $this->assertFileDoesNotExist($from);
    }

    public function test_write_creates_the_destination_directory(): void
    {
        $path = $this->tmpDir.'/nested/deeper/manifest.json';

        AtomicFile::write($path, '{"ok":true}');

        $this->assertFileExists($path);
        $this->assertSame('{"ok":true}', file_get_contents($path));
    }
}
