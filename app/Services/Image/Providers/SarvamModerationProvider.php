<?php

namespace App\Services\Image\Providers;

class SarvamModerationProvider
{
    /**
     * Provider hook (kept non-destructive / non-breaking).
     *
     * @return array{approved:bool,reason:?string,raw:array}
     */
    public function moderate(string $imagePath): array
    {
        // Endpoint + contract for Sarvam image moderation is not standardized in this codebase yet.
        // We return "not approved" so the caller can route to manual review safely.
        return [
            'approved' => false,
            'reason' => 'Sarvam AI moderation is not configured for images.',
            'raw' => ['provider' => 'sarvam'],
        ];
    }
}
