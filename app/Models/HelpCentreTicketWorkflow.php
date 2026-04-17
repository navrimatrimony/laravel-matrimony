<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpCentreTicketWorkflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'help_centre_ticket_id',
        'assigned_admin_id',
        'priority',
        'first_response_due_at',
        'first_response_at',
        'resolved_at',
    ];

    protected $casts = [
        'first_response_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpCentreTicket::class, 'help_centre_ticket_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }
}
