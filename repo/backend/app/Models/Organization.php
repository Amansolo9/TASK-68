<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'type',
        'address',
        'phone',
        'parent_org_id',
        'status',
        'merged_into_id',
    ];

    protected $casts = [
        'parent_org_id' => 'integer',
        'merged_into_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Organization $org) {
            $org->normalized_name = self::normalizeName($org->name);
        });

        static::updating(function (Organization $org) {
            if ($org->isDirty('name')) {
                $org->normalized_name = self::normalizeName($org->name);
            }
        });
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    public static function validateCode(string $code): bool
    {
        return (bool) preg_match('/^ORG-\d{6}$/', $code);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_org_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_org_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'merged_into_id');
    }

    public function personnel(): HasMany
    {
        return $this->hasMany(Personnel::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }
}
