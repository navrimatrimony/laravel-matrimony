<?php

namespace App\Models;

use App\Models\Concerns\ResolvesLocalizedText;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase-5 SSOT: Master lookup for physical build.
 */
class MasterPhysicalBuild extends Model
{
    use ResolvesLocalizedText;

    protected $table = 'master_physical_builds';

    protected $fillable = ['key', 'label', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function localizedLabel(): string
    {
        return $this->localizedText('label');
    }
}
