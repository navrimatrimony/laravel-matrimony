<?php

namespace App\Support\MasterData;

use Illuminate\Support\Str;
use InvalidArgumentException;

class ReligionCasteSubcasteSlugger
{
    public function normalizeLabel(string $label): string
    {
        $trimmed = trim($label);

        return preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
    }

    public function makeKey(string $label): string
    {
        $normalized = $this->normalizeLabel($label);
        $slug = Str::slug($normalized);
        if ($slug === '') {
            throw new InvalidArgumentException('Label cannot produce an empty key.');
        }

        return $slug;
    }
}
