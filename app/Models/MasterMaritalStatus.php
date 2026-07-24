<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for marital status. Keys: never_married, divorced, widowed, separated.
 */
class MasterMaritalStatus extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_marital_statuses';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
