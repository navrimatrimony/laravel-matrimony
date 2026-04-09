<?php

namespace App\Casts;

use App\Support\Utf8MojibakeRepair;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<string|null, string|null>
 */
class MojibakeSafeUtf8String implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $repaired = Utf8MojibakeRepair::repair((string) $value);

        return is_string($repaired) ? $repaired : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! is_string($value)) {
            return [$key => $value];
        }

        $repaired = Utf8MojibakeRepair::repair($value);

        return [$key => is_string($repaired) ? $repaired : $value];
    }
}
