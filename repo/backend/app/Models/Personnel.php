<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasMaskedFields;

class Personnel extends Model
{
    use SoftDeletes, HasMaskedFields;

    protected $table = 'personnel';

    protected $fillable = [
        'employee_id',
        'full_name',
        'normalized_name',
        'encrypted_date_of_birth',
        'encrypted_government_id',
        'email',
        'phone',
        'organization_id',
        'status',
        'merged_into_id',
    ];

    protected $hidden = [
        'encrypted_date_of_birth',
        'encrypted_government_id',
    ];

    protected array $maskedFields = [
        'date_of_birth' => 'encrypted_date_of_birth',
        'government_id' => 'encrypted_government_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Personnel $p) {
            $p->normalized_name = self::normalizeName($p->full_name);
        });

        static::updating(function (Personnel $p) {
            if ($p->isDirty('full_name')) {
                $p->normalized_name = self::normalizeName($p->full_name);
            }
        });
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Personnel::class, 'merged_into_id');
    }
}
