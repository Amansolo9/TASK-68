<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionsPlan extends Model
{
    protected $fillable = [
        'academic_year',
        'intake_batch',
        'current_version_id',
        'status',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(AdmissionsPlanVersion::class, 'plan_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AdmissionsPlanVersion::class, 'current_version_id');
    }

    public function publishedVersion()
    {
        return $this->versions()->where('state', 'published')->first();
    }

    public function latestVersion()
    {
        return $this->versions()->orderBy('version_no', 'desc')->first();
    }

    public function getNextVersionNumber(): int
    {
        $max = $this->versions()->max('version_no');
        return ($max ?? 0) + 1;
    }
}
