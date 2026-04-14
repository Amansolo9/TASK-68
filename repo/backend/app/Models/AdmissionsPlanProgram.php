<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionsPlanProgram extends Model
{
    protected $fillable = [
        'version_id',
        'program_code',
        'program_name',
        'description',
        'planned_capacity',
        'capacity_notes',
        'sort_order',
    ];

    protected $casts = [
        'planned_capacity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(AdmissionsPlanVersion::class, 'version_id');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(AdmissionsPlanTrack::class, 'program_id')->orderBy('sort_order');
    }
}
