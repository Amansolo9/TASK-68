<?php

namespace App\Traits;

use App\Services\MaskingService;

trait HasMaskedFields
{
    /**
     * Get masked representation of the model for API responses.
     */
    public function toMaskedArray(?object $requestingUser = null): array
    {
        $data = $this->toArray();
        $maskingService = app(MaskingService::class);

        if (!property_exists($this, 'maskedFields') || empty($this->maskedFields)) {
            return $data;
        }

        foreach ($this->maskedFields as $displayField => $encryptedField) {
            if (isset($data[$encryptedField])) {
                unset($data[$encryptedField]);
            }
            // Add the display field with masked value
            $data[$displayField] = $maskingService->mask($displayField, $this->getDecryptedField($encryptedField), $requestingUser);
        }

        return $data;
    }

    /**
     * Get decrypted value of a sensitive field.
     */
    protected function getDecryptedField(string $field): ?string
    {
        $value = $this->getAttribute($field);
        if ($value === null) {
            return null;
        }

        try {
            return app(\App\Services\EncryptionService::class)->decrypt($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
