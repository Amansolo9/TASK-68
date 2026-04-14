<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaSecret extends Model
{
    protected $fillable = [
        'user_id',
        'encrypted_totp_secret',
        'encrypted_recovery_codes',
        'recovery_codes_remaining',
        'verified_at',
    ];

    protected $hidden = [
        'encrypted_totp_secret',
        'encrypted_recovery_codes',
    ];

    protected $casts = [
        'encrypted_recovery_codes' => 'array',
        'recovery_codes_remaining' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }
}
