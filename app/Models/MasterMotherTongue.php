<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

class MasterMotherTongue extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_mother_tongues';

    protected $fillable = ['key', 'label', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
