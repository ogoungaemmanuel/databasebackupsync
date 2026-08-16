<?php

namespace DatabaseBackupSync\Tests\Unit;

use DatabaseBackupSync\Encryption\Encryptor;
use DatabaseBackupSync\Exceptions\EncryptionException;
use DatabaseBackupSync\Tests\TestCase;

class EncryptorTest extends TestCase
{
    protected function key(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function test_roundtrip_small_file(): void
    {
        $plain = $this->tmpDir.'/plain.txt';
        file_put_contents($plain, 'hello database backup '.str_repeat('x', 1000));

        $enc = new Encryptor($this->key());
        $encrypted = $enc->encrypt($plain, $this->tmpDir.'/plain.enc');
        $decrypted = $enc->decrypt($encrypted, $this->tmpDir.'/plain.dec');

        $this->assertTrue(Encryptor::isEncrypted($encrypted));
        $this->assertNotSame(file_get_contents($plain), file_get_contents($encrypted));
        $this->assertSame(file_get_contents($plain), file_get_contents($decrypted));
    }

    public function test_roundtrip_large_file_multiple_chunks(): void
    {
        $plain = $this->tmpDir.'/large.txt';
        file_put_contents($plain, random_bytes(3 * 1024 * 1024)); // 3 MB, chunk size 1 MB

        $enc = new Encryptor($this->key(), 1048576);
        $encrypted = $enc->encrypt($plain, $this->tmpDir.'/large.enc');
        $decrypted = $enc->decrypt($encrypted, $this->tmpDir.'/large.dec');

        $this->assertSame(filesize($plain), filesize($decrypted));
        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $decrypted));
    }

    public function test_tampered_ciphertext_is_detected(): void
    {
        $plain = $this->tmpDir.'/tamper.txt';
        file_put_contents($plain, str_repeat('A', 50000));

        $enc = new Encryptor($this->key());
        $encrypted = $enc->encrypt($plain, $this->tmpDir.'/tamper.enc');

        // Flip a byte in the middle of the ciphertext payload.
        $handle = fopen($encrypted, 'r+b');
        fseek($handle, 1000);
        $byte = fread($handle, 1);
        fseek($handle, 1000);
        fwrite($handle, $byte === 'A' ? 'B' : 'A');
        fclose($handle);

        $this->expectException(EncryptionException::class);
        $enc->decrypt($encrypted, $this->tmpDir.'/tamper.dec');
    }

    public function test_wrong_key_is_detected(): void
    {
        $plain = $this->tmpDir.'/key.txt';
        file_put_contents($plain, 'secret data');

        $enc = new Encryptor($this->key());
        $encrypted = $enc->encrypt($plain, $this->tmpDir.'/key.enc');

        $wrong = new Encryptor($this->key());
        $this->expectException(EncryptionException::class);
        $wrong->decrypt($encrypted, $this->tmpDir.'/key.dec');
    }

    public function test_non_encrypted_file_is_rejected(): void
    {
        $plain = $this->tmpDir.'/plain.sql';
        file_put_contents($plain, 'CREATE TABLE x;');

        $enc = new Encryptor($this->key());
        $this->expectException(EncryptionException::class);
        $enc->decrypt($plain, $this->tmpDir.'/out.dec');
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->expectException(EncryptionException::class);
        new Encryptor('not-base64-32-bytes');
    }

    public function test_empty_file_roundtrip(): void
    {
        $plain = $this->tmpDir.'/empty.txt';
        file_put_contents($plain, '');

        $enc = new Encryptor($this->key());
        $encrypted = $enc->encrypt($plain, $this->tmpDir.'/empty.enc');
        $decrypted = $enc->decrypt($encrypted, $this->tmpDir.'/empty.dec');

        $this->assertSame('', file_get_contents($decrypted));
    }
}