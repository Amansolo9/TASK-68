<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataQualityMetric extends Model
{
    protected $fillable = [
        'run_id', 'entity_type', 'metric_name', 'metric_value',
        'numerator', 'denominator',
    ];

    protected $casts = [
        'metric_value' => 'decimal:4',
        'numerator' => 'integer',
        'denominator' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataQualityRun::class, 'run_id');
    }
}
