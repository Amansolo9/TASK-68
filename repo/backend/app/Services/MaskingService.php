<?php

namespace App\Services;

use App\Models\User;

class MaskingService
{
    /**
     * Mask a field value based on the requesting user's permissions.
     */
    public function mask(string $fieldName, ?string $value, ?User $requestingUser = null): string
    {
        if ($value === null) {
            return '';
        }

        // Check if user has permission to see the unmasked value
        if ($requestingUser && $this->canViewUnmasked($fieldName, $requestingUser)) {
            return $value;
        }

        return $this->applyMask($fieldName, $value);
    }

    /**
     * Mask an array of fields.
     */
    public function maskFields(array $data, array $fieldNames, ?User $requestingUser = null): array
    {
        foreach ($fieldNames as $fieldName) {
            if (isset($data[$fieldName])) {
                $data[$fieldName] = $this->mask($fieldName, $data[$fieldName], $requestingUser);
            }
        }
        return $data;
    }

    /**
     * Check if user can view unmasked value.
     */
    private function canViewUnmasked(string $fieldName, User $user): bool
    {
        $rules = config('security.masking_rules', []);

        if (!isset($rules[$fieldName])) {
            return false;
        }

        $requiredPermissions = $rules[$fieldName]['full_access_permissions'] ?? [];

        foreach ($requiredPermissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply masking pattern to a value.
     */
    private function applyMask(string $fieldName, string $value): string
    {
        $rules = config('security.masking_rules', []);
        $pattern = $rules[$fieldName]['default'] ?? '********';

        // Handle last4 substitution
        if (str_contains($pattern, '{{last4}}')) {
            $last4 = substr($value, -4);
            return str_replace('{{last4}}', $last4, $pattern);
        }

        return $pattern;
    }
}
