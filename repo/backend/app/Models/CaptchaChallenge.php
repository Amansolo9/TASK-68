<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptchaChallenge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'challenge_key',
        'answer_hash',
        'expires_at',
        'used',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function isValid(): bool
    {
        return !$this->used && $this->expires_at->isFuture();
    }
}
