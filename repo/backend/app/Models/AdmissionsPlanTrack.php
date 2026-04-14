<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionsPlanTrack extends Model
{
    protected $fillable = [
        'program_id',
        'track_code',
        'track_name',
        'description',
        'planned_capacity',
        'capacity_notes',
        'admission_criteria',
        'sort_order',
    ];

    protected $casts = [
        'planned_capacity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(AdmissionsPlanProgram::class, 'program_id');
    }
}
