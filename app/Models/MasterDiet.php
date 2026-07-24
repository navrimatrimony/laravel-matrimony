<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

class MasterDiet extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_diets';

    protected $fillable = ['key', 'label', 'is_active', 'sort_order'];

    protected $casts = ['is_active' => 'boolean'];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
