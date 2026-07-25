<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPlan;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CRUD + resolution for per-Suchak REUSABLE customer plan presets.
 *
 * The two code presets (Basic / Premium) stay owned by {@see SuchakDefaultPlans}.
 * A DB row with a preset_key only OVERRIDES a code preset (price / visibility /
 * order); a DB row with preset_key NULL is a fully custom reusable plan. Presets
 * are never seeded as DB rows.
 *
 * This service does not touch the send-time model. On send a chosen plan still
 * materializes into suchak_service_packages via SuchakPackageCatalogService with
 * no FK back to this table.
 */
class SuchakCustomerPlanService
{
    /**
     * Create a fully custom reusable plan (preset_key always NULL).
     *
     * @param  array<string, mixed>  $input
     */
    public function create(SuchakAccount $account, array $input): SuchakCustomerPlan
    {
        $services = $this->normalizeServices(
            $input['services'] ?? [],
            (bool) ($input['include_basic'] ?? false),
        );

        if ($services === []) {
            throw new InvalidArgumentException('Add at least one service, or include the Basic services.');
        }

        return SuchakCustomerPlan::query()->create([
            'suchak_account_id' => $account->id,
            'preset_key' => null,
            'name' => $this->requiredText($input['name'] ?? null, 'Plan name is required.', 160),
            'name_mr' => $this->limitedText($input['name_mr'] ?? null, 160),
            'price_amount' => $this->normalizeAmount($input['price_amount'] ?? null, 'Plan price'),
            'currency' => $this->normalizeCurrency($input['currency'] ?? null),
            'duration' => $this->requiredDuration($input['duration'] ?? null),
            'services_json' => $services,
            'per_meeting_fee_amount' => $this->normalizeAmount($input['per_meeting_fee_amount'] ?? null, 'Per-meeting fee', true),
            'post_marriage_fee_mode' => $this->normalizeMode($input['post_marriage_fee_mode'] ?? null),
            'post_marriage_fee_amount' => $this->normalizeAmount($input['post_marriage_fee_amount'] ?? null, 'Post-marriage fee', true),
            'original_price_amount' => $this->normalizeAmount($input['original_price_amount'] ?? null, 'Original price', true),
            'private_note' => $this->text($input['private_note'] ?? null),
            'is_visible' => array_key_exists('is_visible', $input)
                ? filter_var($input['is_visible'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'sort_order' => $this->nextSortOrder($account),
        ]);
    }

    /**
     * Update a plan row. Custom rows accept the full field set; preset-override
     * rows accept only price / original price / name / visibility / order.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(SuchakCustomerPlan $plan, array $input): SuchakCustomerPlan
    {
        $this->guardVisibilityChange($plan, $input);

        if ($plan->isCustom()) {
            $this->applyCustomFields($plan, $input);
        } else {
            $this->applyOverrideFields($plan, $input);
        }

        if (array_key_exists('is_visible', $input)) {
            $plan->is_visible = filter_var($input['is_visible'], FILTER_VALIDATE_BOOLEAN);
        }

        $plan->save();

        return $plan->refresh();
    }

    /**
     * Delete a plan. Only custom rows (preset_key NULL) are deletable — presets
     * cannot be deleted, only hidden via an override row.
     */
    public function delete(SuchakCustomerPlan $plan): void
    {
        if ($plan->isPresetOverride()) {
            throw new InvalidArgumentException('Preset plans cannot be deleted. Hide them instead.');
        }

        if ($plan->is_visible) {
            $this->assertNotLastVisible(
                $this->accountFor($plan),
                fn (array $entry): bool => $this->entryMatchesPlan($entry, $plan),
            );
        }

        $plan->delete();
    }

    /**
     * Persist a new sort_order for the given ordered list of row ids, scoped to
     * the Suchak. Ids not owned by the Suchak are ignored.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(SuchakAccount $account, array $orderedIds): void
    {
        DB::transaction(function () use ($account, $orderedIds): void {
            $sort = 10;
            foreach ($orderedIds as $id) {
                SuchakCustomerPlan::query()
                    ->where('suchak_account_id', $account->id)
                    ->whereKey((int) $id)
                    ->update(['sort_order' => $sort]);
                $sort += 10;
            }
        });
    }

    /**
     * Show or hide a plan. Hiding runs the last-visible guard.
     */
    public function toggleVisibility(SuchakCustomerPlan $plan, ?bool $visible = null): SuchakCustomerPlan
    {
        $target = $visible ?? ! $plan->is_visible;

        if (! $target && $plan->is_visible) {
            $this->assertNotLastVisible(
                $this->accountFor($plan),
                fn (array $entry): bool => $this->entryMatchesPlan($entry, $plan),
            );
        }

        $plan->is_visible = $target;
        $plan->save();

        return $plan->refresh();
    }

    /**
     * Upsert the OVERRIDE row for a code preset (by suchak + preset_key). Only
     * price / original price / name / visibility / order are stored; the preset's
     * services stay code-defined in {@see SuchakDefaultPlans}.
     *
     * @param  array<string, mixed>  $input
     */
    public function upsertPresetOverride(SuchakAccount $account, string $presetKey, array $input): SuchakCustomerPlan
    {
        if (SuchakDefaultPlans::find($presetKey) === null) {
            throw new InvalidArgumentException('Unknown preset plan.');
        }

        $existing = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->where('preset_key', $presetKey)
            ->first();

        // Block hiding the last visible plan.
        if (array_key_exists('is_visible', $input) && ! filter_var($input['is_visible'], FILTER_VALIDATE_BOOLEAN)) {
            $currentlyVisible = $existing === null ? true : (bool) $existing->is_visible;
            if ($currentlyVisible) {
                $this->assertNotLastVisible(
                    $account,
                    fn (array $entry): bool => ($entry['is_preset'] ?? false) && ($entry['preset_key'] ?? null) === $presetKey,
                );
            }
        }

        $attributes = [];
        if (array_key_exists('price_amount', $input)) {
            $attributes['price_amount'] = $this->normalizeAmount($input['price_amount'], 'Plan price', true);
        }
        if (array_key_exists('original_price_amount', $input)) {
            $attributes['original_price_amount'] = $this->normalizeAmount($input['original_price_amount'], 'Original price', true);
        }
        if (array_key_exists('name', $input)) {
            $attributes['name'] = $this->limitedText($input['name'], 160);
        }
        if (array_key_exists('name_mr', $input)) {
            $attributes['name_mr'] = $this->limitedText($input['name_mr'], 160);
        }
        if (array_key_exists('is_visible', $input)) {
            $attributes['is_visible'] = filter_var($input['is_visible'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('sort_order', $input)) {
            $attributes['sort_order'] = $this->sortOrder($input['sort_order']);
        }

        if ($existing === null) {
            $attributes['suchak_account_id'] = $account->id;
            $attributes['preset_key'] = $presetKey;
            // Keep natural code order until an explicit reorder moves it.
            if (! array_key_exists('sort_order', $attributes)) {
                $attributes['sort_order'] = $this->presetIndex($presetKey);
            }
            if (! array_key_exists('is_visible', $attributes)) {
                $attributes['is_visible'] = true;
            }

            return SuchakCustomerPlan::query()->create($attributes);
        }

        $existing->fill($attributes)->save();

        return $existing->refresh();
    }

    /**
     * The EFFECTIVE ordered VISIBLE plan list for the customer-facing payment
     * carousel: the two code presets with per-Suchak overrides applied (hidden
     * ones removed, price/name overridden), plus the Suchak's visible custom
     * plans — all sorted by sort_order. Never includes private_note.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveCarousel(SuchakAccount $account): array
    {
        return $this->buildEntries($account, includeHidden: false, forCustomer: true);
    }

    /**
     * ALL plan entries for the management screen — includes hidden plans and the
     * two presets as overridable items, plus private_note (Suchak-only).
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveForManagement(SuchakAccount $account): array
    {
        return $this->buildEntries($account, includeHidden: true, forCustomer: false);
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildEntries(SuchakAccount $account, bool $includeHidden, bool $forCustomer): array
    {
        $rows = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->get();

        /** @var array<string, SuchakCustomerPlan> $overrides */
        $overrides = [];
        /** @var array<int, SuchakCustomerPlan> $customs */
        $customs = [];
        foreach ($rows as $row) {
            if ($row->preset_key !== null) {
                $overrides[$row->preset_key] = $row;
            } else {
                $customs[] = $row;
            }
        }

        $entries = [];

        foreach (array_values(SuchakDefaultPlans::all()) as $index => $preset) {
            $override = $overrides[$preset['key']] ?? null;
            $visible = $override === null ? true : (bool) $override->is_visible;
            if (! $includeHidden && ! $visible) {
                continue;
            }
            $entries[] = $this->presetEntry($preset, $index, $override, $forCustomer);
        }

        foreach ($customs as $custom) {
            if (! $includeHidden && ! $custom->is_visible) {
                continue;
            }
            $entries[] = $this->customEntry($custom, $forCustomer);
        }

        return $this->sortEntries($entries);
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    private function presetEntry(array $preset, int $index, ?SuchakCustomerPlan $override, bool $forCustomer): array
    {
        $services = array_map(static fn (array $deliverable): array => [
            'name' => $deliverable['name'],
            'name_mr' => $deliverable['name_mr'] ?? null,
        ], $preset['deliverables'] ?? []);

        $entry = [
            'id' => $override?->id,
            'preset_key' => $preset['key'],
            'is_preset' => true,
            'name' => $override?->name ?? $preset['name'],
            'name_mr' => $override?->name_mr ?? ($preset['name_mr'] ?? null),
            'price_amount' => $override?->price_amount ?? $this->decimalString($preset['price_amount']),
            'currency' => $override?->currency ?? strtoupper((string) $preset['currency']),
            'original_price_amount' => $override?->original_price_amount,
            'duration' => $override?->duration,
            'services' => $services,
            'per_meeting_fee_amount' => $override?->per_meeting_fee_amount,
            'post_marriage_fee_mode' => $override?->post_marriage_fee_mode,
            'post_marriage_fee_amount' => $override?->post_marriage_fee_amount,
            'is_visible' => $override === null ? true : (bool) $override->is_visible,
            'sort_order' => $override !== null ? (int) $override->sort_order : $index,
            '_kind' => 0,
            '_ref' => $index,
        ];

        if (! $forCustomer) {
            $entry['private_note'] = $override?->private_note;
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function customEntry(SuchakCustomerPlan $custom, bool $forCustomer): array
    {
        $entry = [
            'id' => $custom->id,
            'preset_key' => null,
            'is_preset' => false,
            'name' => $custom->name,
            'name_mr' => $custom->name_mr,
            'price_amount' => $custom->price_amount,
            'currency' => $custom->currency,
            'original_price_amount' => $custom->original_price_amount,
            'duration' => $custom->duration,
            'services' => $custom->services_json ?? [],
            'per_meeting_fee_amount' => $custom->per_meeting_fee_amount,
            'post_marriage_fee_mode' => $custom->post_marriage_fee_mode,
            'post_marriage_fee_amount' => $custom->post_marriage_fee_amount,
            'is_visible' => (bool) $custom->is_visible,
            'sort_order' => (int) $custom->sort_order,
            '_kind' => 1,
            '_ref' => (int) $custom->id,
        ];

        if (! $forCustomer) {
            $entry['private_note'] = $custom->private_note;
        }

        return $entry;
    }

    /**
     * Sort by sort_order, then presets before customs, then a stable reference.
     * Strips the internal sort keys from the returned entries.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            return [$a['sort_order'], $a['_kind'], $a['_ref']] <=> [$b['sort_order'], $b['_kind'], $b['_ref']];
        });

        return array_map(static function (array $entry): array {
            unset($entry['_kind'], $entry['_ref']);

            return $entry;
        }, $entries);
    }

    /**
     * Refuse the change if it would leave no OTHER visible plan than the target.
     *
     * @param  callable(array<string, mixed>): bool  $matchesTarget
     */
    private function assertNotLastVisible(SuchakAccount $account, callable $matchesTarget): void
    {
        $visible = $this->resolveCarousel($account);
        $others = array_filter($visible, static fn (array $entry): bool => ! $matchesTarget($entry));

        if ($others === []) {
            throw new InvalidArgumentException(
                'At least one plan must stay visible for the payment carousel. Add or show another plan before hiding this one.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entryMatchesPlan(array $entry, SuchakCustomerPlan $plan): bool
    {
        if ($plan->isPresetOverride()) {
            return ($entry['is_preset'] ?? false) && ($entry['preset_key'] ?? null) === $plan->preset_key;
        }

        return ! ($entry['is_preset'] ?? false) && ($entry['id'] ?? null) === $plan->id;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function guardVisibilityChange(SuchakCustomerPlan $plan, array $input): void
    {
        if (! array_key_exists('is_visible', $input)) {
            return;
        }
        if (filter_var($input['is_visible'], FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        if (! $plan->is_visible) {
            return;
        }

        $this->assertNotLastVisible(
            $this->accountFor($plan),
            fn (array $entry): bool => $this->entryMatchesPlan($entry, $plan),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyCustomFields(SuchakCustomerPlan $plan, array $input): void
    {
        if (array_key_exists('name', $input)) {
            $plan->name = $this->requiredText($input['name'], 'Plan name is required.', 160);
        }
        if (array_key_exists('name_mr', $input)) {
            $plan->name_mr = $this->limitedText($input['name_mr'], 160);
        }
        if (array_key_exists('price_amount', $input)) {
            $plan->price_amount = $this->normalizeAmount($input['price_amount'], 'Plan price');
        }
        if (array_key_exists('currency', $input)) {
            $plan->currency = $this->normalizeCurrency($input['currency']);
        }
        if (array_key_exists('duration', $input)) {
            $plan->duration = $this->requiredDuration($input['duration']);
        }
        if (array_key_exists('services', $input) || array_key_exists('include_basic', $input)) {
            $services = $this->normalizeServices(
                $input['services'] ?? [],
                (bool) ($input['include_basic'] ?? false),
            );
            if ($services === []) {
                throw new InvalidArgumentException('Add at least one service, or include the Basic services.');
            }
            $plan->services_json = $services;
        }
        if (array_key_exists('per_meeting_fee_amount', $input)) {
            $plan->per_meeting_fee_amount = $this->normalizeAmount($input['per_meeting_fee_amount'], 'Per-meeting fee', true);
        }
        if (array_key_exists('post_marriage_fee_mode', $input)) {
            $plan->post_marriage_fee_mode = $this->normalizeMode($input['post_marriage_fee_mode']);
        }
        if (array_key_exists('post_marriage_fee_amount', $input)) {
            $plan->post_marriage_fee_amount = $this->normalizeAmount($input['post_marriage_fee_amount'], 'Post-marriage fee', true);
        }
        if (array_key_exists('original_price_amount', $input)) {
            $plan->original_price_amount = $this->normalizeAmount($input['original_price_amount'], 'Original price', true);
        }
        if (array_key_exists('private_note', $input)) {
            $plan->private_note = $this->text($input['private_note']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function applyOverrideFields(SuchakCustomerPlan $plan, array $input): void
    {
        if (array_key_exists('price_amount', $input)) {
            $plan->price_amount = $this->normalizeAmount($input['price_amount'], 'Plan price', true);
        }
        if (array_key_exists('original_price_amount', $input)) {
            $plan->original_price_amount = $this->normalizeAmount($input['original_price_amount'], 'Original price', true);
        }
        if (array_key_exists('name', $input)) {
            $plan->name = $this->limitedText($input['name'], 160);
        }
        if (array_key_exists('name_mr', $input)) {
            $plan->name_mr = $this->limitedText($input['name_mr'], 160);
        }
        if (array_key_exists('sort_order', $input)) {
            $plan->sort_order = $this->sortOrder($input['sort_order']);
        }
    }

    /**
     * Normalize a service list to [{name, name_mr}] objects, optionally folding
     * the code Basic preset's services in first. Accepts either bare strings or
     * {name, name_mr} maps as input.
     *
     * @param  array<int, mixed>  $services
     * @return array<int, array{name: string, name_mr: string|null}>
     */
    private function normalizeServices(array $services, bool $includeBasic): array
    {
        $out = [];

        if ($includeBasic) {
            $basic = SuchakDefaultPlans::find(SuchakDefaultPlans::KEY_BASIC);
            foreach ($basic['deliverables'] ?? [] as $deliverable) {
                $out[] = [
                    'name' => (string) $deliverable['name'],
                    'name_mr' => $deliverable['name_mr'] ?? null,
                ];
            }
        }

        foreach ($services as $service) {
            if (is_array($service)) {
                $name = trim((string) ($service['name'] ?? ''));
                $nameMr = trim((string) ($service['name_mr'] ?? ''));
            } else {
                $name = trim((string) $service);
                $nameMr = '';
            }

            if ($name === '') {
                continue;
            }

            $out[] = [
                'name' => Str::limit($name, 160, ''),
                'name_mr' => $nameMr === '' ? null : Str::limit($nameMr, 160, ''),
            ];
        }

        return $out;
    }

    private function nextSortOrder(SuchakAccount $account): int
    {
        $max = (int) SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->max('sort_order');

        return min(65535, $max + 10);
    }

    private function presetIndex(string $key): int
    {
        foreach (array_values(SuchakDefaultPlans::all()) as $index => $preset) {
            if ($preset['key'] === $key) {
                return $index;
            }
        }

        return 0;
    }

    private function accountFor(SuchakCustomerPlan $plan): SuchakAccount
    {
        return $plan->suchakAccount ?? SuchakAccount::query()->findOrFail($plan->suchak_account_id);
    }

    private function requiredText(mixed $value, string $message, int $limit): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            throw new InvalidArgumentException($message);
        }

        return Str::limit($normalized, $limit, '');
    }

    private function limitedText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : Str::limit($normalized, $limit, '');
    }

    private function text(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeAmount(mixed $value, string $label, bool $nullable = false): ?string
    {
        if ($value === null || $value === '') {
            if ($nullable) {
                return null;
            }
            throw new InvalidArgumentException($label.' is required.');
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException($label.' must be zero or greater.');
        }

        return $this->decimalString($value);
    }

    private function normalizeCurrency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? '')));
        if ($currency === '') {
            $currency = 'INR';
        }
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter code.');
        }

        return $currency;
    }

    private function requiredDuration(mixed $value): string
    {
        $duration = trim((string) ($value ?? ''));
        if ($duration === '') {
            throw new InvalidArgumentException('Plan duration is required.');
        }
        if (! in_array($duration, SuchakCustomerPlan::DURATIONS, true)) {
            throw new InvalidArgumentException('Plan duration is invalid.');
        }

        return $duration;
    }

    private function normalizeMode(mixed $value): ?string
    {
        $mode = trim((string) ($value ?? ''));
        if ($mode === '') {
            return null;
        }
        if (! in_array($mode, SuchakCustomerPlan::POST_MARRIAGE_FEE_MODES, true)) {
            throw new InvalidArgumentException('Post-marriage fee mode is invalid.');
        }

        return $mode;
    }

    private function sortOrder(mixed $value): int
    {
        return max(0, min(65535, (int) $value));
    }

    private function decimalString(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
