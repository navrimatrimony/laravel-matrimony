<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

class MasterAddressType extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_address_types';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
