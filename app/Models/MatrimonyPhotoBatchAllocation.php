<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatrimonyPhotoBatchAllocation extends Model
{
    protected $table = 'matrimony_photo_batch_allocations';

    protected $fillable = [
        'yy',
        'mm',
        'batch_index',
        'profiles_count',
    ];

    protected function casts(): array
    {
        return [
            'yy' => 'integer',
            'mm' => 'integer',
            'batch_index' => 'integer',
            'profiles_count' => 'integer',
        ];
    }
}
