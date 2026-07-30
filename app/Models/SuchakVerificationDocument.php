<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file inside a verification.
 *
 * Several of these hang off a single {@see SuchakVerificationRecord} — both
 * sides of an Aadhaar, a PAN alongside it — because the thing an admin
 * approves is the Suchak's identity, not each page of it. The record therefore
 * keeps the status and the remarks; this keeps only the files.
 */
class SuchakVerificationDocument extends Model
{
    protected $table = 'suchak_verification_documents';

    protected $fillable = [
        'suchak_verification_record_id',
        'document_path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(SuchakVerificationRecord::class, 'suchak_verification_record_id');
    }

    /**
     * Whether this is a PDF rather than an image, so a viewer can choose
     * between rendering it and offering a download.
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf'
            || str_ends_with(strtolower((string) $this->document_path), '.pdf');
    }
}
