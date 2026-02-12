<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SeriousIntent extends Model
{
    use SoftDeletes;

    protected $table = 'serious_intents';

    protected $fillable = ['name'];

    protected $casts = [];

    protected static function booted(): void
    {
        static::creating(function (SeriousIntent $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });

        static::saving(function (SeriousIntent $model) {
            if ($model->exists && $model->isDirty('slug')) {
                throw new \InvalidArgumentException('SeriousIntent slug is immutable and cannot be changed.');
            }
        });
    }
}
