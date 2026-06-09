<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class SuchakBiodataExport extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_BIODATA_PDF = 'biodata_pdf';

    protected $table = 'suchak_biodata_exports';

    protected $fillable = [
        'suchak_account_id',
        'matrimony_profile_id',
        'representation_id',
        'export_type',
        'file_path',
        'generated_by_user_id',
        'downloaded_at',
        'shared_at',
    ];

    protected $casts = [
        'downloaded_at' => 'datetime',
        'shared_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function suchakAccount(): BelongsTo
    {
        return $this->belongsTo(SuchakAccount::class);
    }

    public function matrimonyProfile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class);
    }

    public function representation(): BelongsTo
    {
        return $this->belongsTo(SuchakProfileRepresentation::class, 'representation_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function qrTokens(): HasMany
    {
        return $this->hasMany(SuchakQrToken::class, 'export_id');
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak biodata export records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak biodata export records cannot be deleted.');
    }
}
