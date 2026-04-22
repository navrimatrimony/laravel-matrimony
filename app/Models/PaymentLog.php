<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'txnid',
        'source',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
