<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HelpCentreTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ticket_code',
        'query_text',
        'normalized_query',
        'intent',
        'escalated',
        'status',
        'bot_reply',
        'meta',
    ];

    protected $casts = [
        'escalated' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workflow(): HasOne
    {
        return $this->hasOne(HelpCentreTicketWorkflow::class, 'help_centre_ticket_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(HelpCentreTicketNote::class, 'help_centre_ticket_id');
    }
}
