<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataQualityRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_date', 'started_at', 'completed_at', 'status', 'error_message',
    ];

    protected $casts = [
        'run_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function metrics(): HasMany
    {
        return $this->hasMany(DataQualityMetric::class, 'run_id');
    }
}
