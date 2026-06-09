<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SuchakQrToken extends Model
{
    use HasFactory;

    protected $table = 'suchak_qr_tokens';

    protected $fillable = [
        'token_hash',
        'suchak_account_id',
        'matrimony_profile_id',
        'representation_id',
        'export_id',
        'expires_at',
        'scan_count',
        'last_scanned_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'scan_count' => 'integer',
        'last_scanned_at' => 'datetime',
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

    public function export(): BelongsTo
    {
        return $this->belongsTo(SuchakBiodataExport::class, 'export_id');
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte($at ?? now());
    }

    public function incrementScan(?CarbonInterface $scannedAt = null): self
    {
        static::query()
            ->whereKey($this->id)
            ->update([
                'scan_count' => DB::raw('scan_count + 1'),
                'last_scanned_at' => $scannedAt ?? now(),
            ]);

        return $this->refresh();
    }

    public function delete(): ?bool
    {
        throw new RuntimeException('Suchak QR token records cannot be deleted.');
    }

    public function forceDelete(): ?bool
    {
        throw new RuntimeException('Suchak QR token records cannot be deleted.');
    }
}
