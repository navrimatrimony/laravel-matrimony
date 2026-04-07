<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Services\FeatureUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Admin API: bulk upsert/delete {@see PlanFeature} rows by canonical keys from {@see config('plan_features')}.
 * Product gates remain in {@see FeatureUsageService} / {@see SubscriptionService}.
 */
class PlanFeatureController extends Controller
{
    private const REQUIRED_KEYS = [
        'chat_can_read',
        'chat_send_limit',
    ];

    public function update(Request $request, Plan $plan, FeatureUsageService $featureUsage): JsonResponse
    {
        $payload = $request->validate([
            'features' => ['required', 'array'],
        ]);

        $definitions = config('plan_features', []);
        if ($definitions === [] || ! is_array($definitions)) {
            throw ValidationException::withMessages([
                'features' => ['plan_features config is missing or invalid.'],
            ]);
        }

        $featuresInput = $payload['features'];
        $normalizedPayload = [];

        foreach ($featuresInput as $rawKey => $value) {
            try {
                $normalized = $featureUsage->normalizeFeatureKey((string) $rawKey);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    "features.{$rawKey}" => [$e->getMessage()],
                ]);
            }

            if (! array_key_exists($normalized, $definitions)) {
                throw ValidationException::withMessages([
                    "features.{$rawKey}" => ["Unknown feature key: {$normalized}"],
                ]);
            }

            $type = (string) ($definitions[$normalized]['type'] ?? '');
            if ($value === null) {
                $normalizedPayload[$normalized] = null;

                continue;
            }

            $normalizedPayload[$normalized] = $this->assertValueMatchesType($normalized, $type, $value);
        }

        DB::transaction(function () use ($normalizedPayload, $plan, $definitions): void {
            foreach ($normalizedPayload as $key => $storedValue) {
                if ($storedValue === null) {
                    if (in_array($key, self::REQUIRED_KEYS, true)) {
                        $type = (string) ($definitions[$key]['type'] ?? '');
                        $safe = match ($type) {
                            'boolean' => '0',
                            'limit', 'days' => '0',
                            default => '0',
                        };

                        PlanFeature::query()->updateOrCreate(
                            [
                                'plan_id' => $plan->id,
                                'key' => $key,
                            ],
                            [
                                'value' => $safe,
                            ]
                        );

                        continue;
                    }

                    PlanFeature::query()
                        ->where('plan_id', $plan->id)
                        ->where('key', $key)
                        ->delete();

                    continue;
                }

                PlanFeature::query()->updateOrCreate(
                    [
                        'plan_id' => $plan->id,
                        'key' => $key,
                    ],
                    [
                        'value' => $storedValue,
                    ]
                );
            }
        });

        $plan->forgetCachedPlanFeatures();
        Plan::forgetCachedPlanFeaturesByPlanId((int) $plan->id);

        return response()->json([
            'ok' => true,
            'plan_id' => $plan->id,
            'updated_keys' => array_keys(array_filter($normalizedPayload, fn ($v) => $v !== null)),
            'deleted_keys' => array_keys(array_filter($normalizedPayload, fn ($v) => $v === null)),
        ]);
    }

    /**
     * @return string Stored string for plan_features.value (limits/days as digits; booleans as 0/1).
     */
    private function assertValueMatchesType(string $key, string $type, mixed $value): string
    {
        return match ($type) {
            'limit' => $this->validateLimit($key, $value),
            'boolean' => $this->validateBoolean($key, $value),
            'days' => $this->validateDays($key, $value),
            default => throw ValidationException::withMessages([
                "features.{$key}" => ["Unsupported feature type: {$type}"],
            ]),
        };
    }

    private function validateLimit(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            throw ValidationException::withMessages([
                "features.{$key}" => ['Limit features expect an integer (>= -1).'],
            ]);
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                "features.{$key}" => ['Limit features expect an integer (>= -1).'],
            ]);
        }

        $int = (int) $value;
        if ($int < -1) {
            throw ValidationException::withMessages([
                "features.{$key}" => ['Limit must be >= -1.'],
            ]);
        }

        return (string) $int;
    }

    private function validateBoolean(string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return ((int) $value) === 1 ? '1' : '0';
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['true', 'on', 'yes'], true)) {
                return '1';
            }
            if (in_array($lower, ['false', 'off', 'no', ''], true)) {
                return '0';
            }
        }

        throw ValidationException::withMessages([
            "features.{$key}" => ['Boolean features expect true/false, 1/0, or equivalent.'],
        ]);
    }

    private function validateDays(string $key, mixed $value): string
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                "features.{$key}" => ['Days features expect a non-negative integer.'],
            ]);
        }

        $int = (int) $value;
        if ($int < 0) {
            throw ValidationException::withMessages([
                "features.{$key}" => ['Days must be >= 0.'],
            ]);
        }

        return (string) $int;
    }
}
