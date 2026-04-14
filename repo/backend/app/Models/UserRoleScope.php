<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRoleScope extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'department_scope',
        'entity_scope',
        'section_permissions',
        'content_permissions',
        'is_active',
    ];

    protected $casts = [
        'section_permissions' => 'array',
        'content_permissions' => 'array',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
