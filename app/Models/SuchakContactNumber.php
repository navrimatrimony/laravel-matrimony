<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuchakContactNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'suchak_account_id',
        'phone_number',
        'label',
        'label_mr',
        'is_whatsapp',
        'is_active',
    ];

    protected $casts = [
        'is_whatsapp' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }
}
