<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

class MasterNakshatra extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_nakshatras';

    protected $fillable = ['key', 'label', 'is_active', 'nakshatra_number'];

    protected $casts = ['is_active' => 'boolean'];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
