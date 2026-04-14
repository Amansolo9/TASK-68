<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDataVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'version_no',
        'before_snapshot',
        'after_snapshot',
        'before_hash',
        'after_hash',
        'actor_user_id',
        'change_reason',
        'created_at',
    ];

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'created_at' => 'datetime',
    ];
}
