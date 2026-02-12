<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class VerificationTag extends Model
{
    use SoftDeletes;

    protected $table = 'verification_tags';

    protected $fillable = ['name'];

    protected $casts = [];

    protected static function booted(): void
    {
        static::creating(function (VerificationTag $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name);
            }
        });

        static::saving(function (VerificationTag $model) {
            if ($model->exists && $model->isDirty('slug')) {
                throw new \InvalidArgumentException('VerificationTag slug is immutable and cannot be changed.');
            }
        });
    }
}
