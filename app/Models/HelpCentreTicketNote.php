<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpCentreTicketNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'help_centre_ticket_id',
        'admin_user_id',
        'note',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpCentreTicket::class, 'help_centre_ticket_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
