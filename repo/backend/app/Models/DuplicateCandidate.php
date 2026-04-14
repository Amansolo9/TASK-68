<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DuplicateCandidate extends Model
{
    protected $fillable = [
        'entity_type', 'left_entity_id', 'right_entity_id',
        'detection_basis', 'confidence', 'status',
    ];

    protected $casts = ['confidence' => 'decimal:2'];
}
