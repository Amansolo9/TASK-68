<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketRoutingHistory extends Model
{
    public $timestamps = false;

    protected $table = 'ticket_routing_history';

    protected $fillable = [
        'ticket_id', 'from_department', 'to_department',
        'from_advisor', 'to_advisor', 'reason', 'actor_user_id', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
