<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultationAttachment extends Model
{
    protected $fillable = [
        'ticket_id', 'original_filename', 'mime_type', 'file_size',
        'storage_path', 'sha256_fingerprint', 'upload_status', 'quarantine_reason',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ConsultationTicket::class, 'ticket_id');
    }
}
