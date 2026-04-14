<?php

namespace App\Services;

class EncryptionService
{
    private string $key;
    private string $cipher = 'aes-256-gcm';

    public function __construct()
    {
        $hexKey = config('security.encryption_key');
        $this->key = $hexKey ? hex2bin($hexKey) : random_bytes(32);
    }

    /**
     * Encrypt a plaintext value.
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode(json_encode([
            'iv' => base64_encode($iv),
            'ct' => base64_encode($ciphertext),
            'tag' => base64_encode($tag),
        ]));
    }

    /**
     * Decrypt an encrypted value.
     */
    public function decrypt(string $encrypted): string
    {
        $data = json_decode(base64_decode($encrypted), true);

        if (!$data || !isset($data['iv'], $data['ct'], $data['tag'])) {
            throw new \RuntimeException('Invalid encrypted data format.');
        }

        $iv = base64_decode($data['iv']);
        $ciphertext = base64_decode($data['ct']);
        $tag = base64_decode($data['tag']);

        $plaintext = openssl_decrypt($ciphertext, $this->cipher, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Hash a value for comparison (non-reversible).
     */
    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Compute SHA-256 hash of data for integrity verification.
     */
    public function computeIntegrityHash(string $data): string
    {
        return hash('sha256', $data);
    }
}
