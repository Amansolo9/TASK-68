<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'username',
        'attempted_at',
        'ip_address',
        'outcome',
        'captcha_required',
        'user_agent',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'captcha_required' => 'boolean',
    ];
}
