<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\EncryptionService;

class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EncryptionService::class);
    }

    public function test_encrypt_and_decrypt_returns_original_value(): void
    {
        $plaintext = 'Sensitive Data 123!';
        $encrypted = $this->service->encrypt($plaintext);

        $this->assertNotEquals($plaintext, $encrypted);
        $this->assertEquals($plaintext, $this->service->decrypt($encrypted));
    }

    public function test_encrypt_produces_different_ciphertexts_for_same_input(): void
    {
        $plaintext = 'Same value';
        $encrypted1 = $this->service->encrypt($plaintext);
        $encrypted2 = $this->service->encrypt($plaintext);

        // Different IVs should produce different ciphertexts
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to the same value
        $this->assertEquals($plaintext, $this->service->decrypt($encrypted1));
        $this->assertEquals($plaintext, $this->service->decrypt($encrypted2));
    }

    public function test_decrypt_with_invalid_data_throws_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->decrypt('not-valid-encrypted-data');
    }

    public function test_hash_produces_consistent_output(): void
    {
        $value = 'test value';
        $hash1 = $this->service->hash($value);
        $hash2 = $this->service->hash($value);

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 hex length
    }

    public function test_integrity_hash_is_deterministic(): void
    {
        $data = 'document content';
        $hash1 = $this->service->computeIntegrityHash($data);
        $hash2 = $this->service->computeIntegrityHash($data);

        $this->assertEquals($hash1, $hash2);
    }
}
