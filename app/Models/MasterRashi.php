<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

class MasterRashi extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_rashis';

    protected $fillable = ['key', 'label', 'is_active', 'varna_id', 'vashya_id', 'rashi_lord_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
