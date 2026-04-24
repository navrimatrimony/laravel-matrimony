<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemRule extends Model
{
    protected $fillable = [
        'key',
        'value',
        'meta',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}
