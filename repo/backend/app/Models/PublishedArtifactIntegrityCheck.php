<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublishedArtifactIntegrityCheck extends Model
{
    protected $fillable = [
        'artifact_type',
        'artifact_id',
        'expected_hash',
        'last_verified_hash',
        'verified_at',
        'status',
        'error_message',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];
}
