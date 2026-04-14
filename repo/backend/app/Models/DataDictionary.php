<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataDictionary extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'dictionary_type',
        'code',
        'label',
        'description',
        'validation_rule_ref',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeByType($query, string $type)
    {
        return $query->where('dictionary_type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
