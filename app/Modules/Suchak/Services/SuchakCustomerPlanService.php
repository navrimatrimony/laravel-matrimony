<?php

namespace App\Modules\Suchak\Services;

use App\Models\SuchakAccount;
use App\Models\SuchakCustomerPlan;
use App\Modules\Suchak\Support\SuchakDefaultPlans;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * CRUD + resolution for per-Suchak REUSABLE customer plans.
 *
 * ONE KIND OF ROW (since 2026-08-02). Every plan a Suchak owns is a
 * suchak_customer_plans row they can edit in full. The two ready-made plans are
 * no longer a parallel, code-resident species of plan: {@see SuchakDefaultPlans}
 * was demoted to SEED CONTENT and is read once, by {@see ensurePresetRows()}, to
 * create the Suchak's own row.
 *
 * `preset_key` survives on that row and still means something — it is the row's
 * identity ('basic' / 'premium'), it keeps the row UNDELETABLE (hide it instead),
 * and it is what the send-time flow scopes a customer's package by. What it no
 * longer means is "this plan is not yours to edit".
 *
 * This service does not touch the send-time model. On send a chosen plan still
 * materializes into suchak_service_packages via SuchakPackageCatalogService with
 * no FK back to this table — the plan is the DEFAULT, the send is the DECISION,
 * the package is the FROZEN RECORD, and that three-life rule is untouched here.
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
            // Priced independently of the offline fee — an online session is its own
            // work, so nothing here is derived from or validated against the other.
            'per_meeting_online_fee_amount' => $this->normalizeAmount($input['per_meeting_online_fee_amount'] ?? null, 'Per-meeting online fee', true),
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
     * Update a plan row — the SAME field set for a ready-made plan and a custom
     * one. A seeded preset row is a plan the Suchak owns; the only two things it
     * still refuses are an empty name and an empty duration, and it refuses those
     * by falling back to its seed content rather than by rejecting the write.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(SuchakCustomerPlan $plan, array $input): SuchakCustomerPlan
    {
        $this->guardVisibilityChange($plan, $input);

        $this->applyPlanFields($plan, $input);

        if (array_key_exists('is_visible', $input)) {
            $plan->is_visible = filter_var($input['is_visible'], FILTER_VALIDATE_BOOLEAN);
        }

        $plan->save();

        return $plan->refresh();
    }

    /**
     * Delete a plan. Only custom rows (preset_key NULL) are deletable. A
     * ready-made plan is editable in full but never deletable — hide it instead.
     * Its preset_key is the identity the send-time flow scopes a customer's
     * package by, so a Suchak deleting the row would break that scoping for
     * every customer who was ever sent it.
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
     * Update a ready-made plan addressed by its preset key ('basic'/'premium')
     * instead of by row id — the route the app has always used, kept because a
     * shipped build knows the key and not the id.
     *
     * It no longer "upserts an override": the row is seeded first if it is
     * missing, and then edited through the ONE update path every plan uses.
     *
     * @param  array<string, mixed>  $input
     */
    public function updatePreset(SuchakAccount $account, string $presetKey, array $input): SuchakCustomerPlan
    {
        if (SuchakDefaultPlans::find($presetKey) === null) {
            throw new InvalidArgumentException('Unknown preset plan.');
        }

        $this->ensurePresetRows($account);

        $row = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->where('preset_key', $presetKey)
            ->first();

        if ($row === null) {
            throw new InvalidArgumentException('Unknown preset plan.');
        }

        return $this->update($row, $input);
    }

    /**
     * Give this Suchak the ready-made plans as rows they own. Idempotent.
     *
     * WHEN: lazily, the first time anything reads this Suchak's plans (both
     * resolvers funnel through {@see buildEntries()}), and before a preset is
     * addressed by key. Not on account creation — that hook could only ever serve
     * accounts created after it shipped, and every existing Suchak would still be
     * left without rows, so there would have to be a second, lazy path anyway.
     * One path, running at the exact moment the rows are needed.
     *
     * Never duplicates and never overwrites: only the preset keys this Suchak is
     * missing are inserted, and insertOrIgnore leans on the
     * (suchak_account_id, preset_key) unique index so two concurrent readers
     * cannot both create the same row.
     */
    public function ensurePresetRows(SuchakAccount $account): void
    {
        $existing = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->whereNotNull('preset_key')
            ->pluck('preset_key')
            ->all();

        $now = now();
        $missing = [];

        foreach (SuchakDefaultPlans::seedRows() as $seed) {
            if (in_array($seed['preset_key'], $existing, true)) {
                continue;
            }

            $missing[] = [
                'suchak_account_id' => $account->id,
                'preset_key' => $seed['preset_key'],
                'name' => $seed['name'],
                'name_mr' => $seed['name_mr'],
                'price_amount' => $seed['price_amount'],
                'currency' => $seed['currency'],
                // No duration and no fees: a ready-made plan fixes none until the
                // Suchak fixes them. NULL is the truthful "ठरलेले नाही".
                'duration' => null,
                'services_json' => json_encode($seed['services_json']),
                'is_visible' => true,
                'sort_order' => $seed['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($missing === []) {
            return;
        }

        SuchakCustomerPlan::query()->insertOrIgnore($missing);
    }

    /**
     * The EFFECTIVE ordered VISIBLE plan list for the customer-facing payment
     * carousel: every visible plan this Suchak owns — the two ready-made ones and
     * their own custom ones alike — sorted by sort_order. Never includes
     * private_note.
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveCarousel(SuchakAccount $account): array
    {
        return $this->buildEntries($account, includeHidden: false, forCustomer: true);
    }

    /**
     * ALL plan entries for the management screen — hidden plans included, plus
     * private_note (Suchak-only).
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
        // The ready-made plans exist as this Suchak's own rows from here on.
        $this->ensurePresetRows($account);

        $rows = SuchakCustomerPlan::query()
            ->where('suchak_account_id', $account->id)
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            if (! $includeHidden && ! $row->is_visible) {
                continue;
            }
            $entries[] = $this->planEntry($row, $forCustomer);
        }

        return $this->sortEntries($entries);
    }

    /**
     * ONE entry builder for ONE kind of row. A ready-made plan and a custom plan
     * differ by `preset_key` and by nothing else on the way out — which is what
     * makes the four fee columns readable here at all. They used to be read off a
     * row nothing could ever write them to.
     *
     * The seed content is consulted only as a FALLBACK, for a legacy row created
     * by the old override path (name / price / services could all be NULL there)
     * that the backfill migration has not reached. A blank card is never the
     * right answer, and a Suchak who clears a name gets the ready-made one back
     * rather than an empty row.
     *
     * @return array<string, mixed>
     */
    private function planEntry(SuchakCustomerPlan $row, bool $forCustomer): array
    {
        $isPreset = $row->isPresetOverride();
        $seed = $isPreset ? SuchakDefaultPlans::find($row->preset_key) : null;

        $services = $row->services_json ?? [];
        if ($services === [] && $seed !== null) {
            $services = array_map(static fn (array $deliverable): array => [
                'name' => $deliverable['name'],
                'name_mr' => $deliverable['name_mr'] ?? null,
            ], $seed['deliverables'] ?? []);
        }

        $entry = [
            'id' => $row->id,
            'preset_key' => $row->preset_key,
            'is_preset' => $isPreset,
            'name' => $row->name ?? ($seed['name'] ?? null),
            'name_mr' => $row->name_mr ?? ($seed['name_mr'] ?? null),
            'price_amount' => $row->price_amount
                ?? ($seed !== null ? $this->decimalString($seed['price_amount']) : null),
            'currency' => $row->currency ?? strtoupper((string) ($seed['currency'] ?? 'INR')),
            'original_price_amount' => $row->original_price_amount,
            'duration' => $row->duration,
            'services' => $services,
            'per_meeting_fee_amount' => $row->per_meeting_fee_amount,
            'per_meeting_online_fee_amount' => $row->per_meeting_online_fee_amount,
            'post_marriage_fee_mode' => $row->post_marriage_fee_mode,
            'post_marriage_fee_amount' => $row->post_marriage_fee_amount,
            'is_visible' => (bool) $row->is_visible,
            'sort_order' => (int) $row->sort_order,
            // Ready-made plans win a sort_order tie, then the row id breaks it.
            '_kind' => $isPreset ? 0 : 1,
            '_ref' => (int) $row->id,
        ];

        if (! $forCustomer) {
            $entry['private_note'] = $row->private_note;
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
     * Every entry now carries the row id it was built from — ready-made plans
     * included — so one comparison covers both kinds.
     *
     * @param  array<string, mixed>  $entry
     */
    private function entryMatchesPlan(array $entry, SuchakCustomerPlan $plan): bool
    {
        return ($entry['id'] ?? null) === $plan->id;
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
     * The ONE writer for a plan row, ready-made or custom.
     *
     * Two fields bend for a ready-made row instead of throwing, because it has
     * seed content to fall back on and a custom row does not: a cleared NAME
     * reads back as the ready-made name, and a cleared DURATION reads back as
     * "not fixed" — the same NULL a freshly seeded row carries. Everything else,
     * the four fee columns included, is written identically for both.
     *
     * @param  array<string, mixed>  $input
     */
    private function applyPlanFields(SuchakCustomerPlan $plan, array $input): void
    {
        $isPreset = $plan->isPresetOverride();

        if (array_key_exists('name', $input)) {
            $plan->name = $isPreset
                ? $this->limitedText($input['name'], 160)
                : $this->requiredText($input['name'], 'Plan name is required.', 160);
        }
        if (array_key_exists('name_mr', $input)) {
            $plan->name_mr = $this->limitedText($input['name_mr'], 160);
        }
        if (array_key_exists('price_amount', $input)) {
            $plan->price_amount = $this->normalizeAmount($input['price_amount'], 'Plan price', $isPreset);
        }
        if (array_key_exists('currency', $input)) {
            $plan->currency = $this->normalizeCurrency($input['currency']);
        }
        if (array_key_exists('duration', $input)) {
            $plan->duration = $isPreset
                ? $this->optionalDuration($input['duration'])
                : $this->requiredDuration($input['duration']);
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
        if (array_key_exists('per_meeting_online_fee_amount', $input)) {
            $plan->per_meeting_online_fee_amount = $this->normalizeAmount($input['per_meeting_online_fee_amount'], 'Per-meeting online fee', true);
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

    /**
     * A ready-made plan may go back to fixing no duration at all — the state it
     * is seeded in, and the one that leaves the send screen's own default in
     * charge.
     */
    private function optionalDuration(mixed $value): ?string
    {
        $duration = trim((string) ($value ?? ''));
        if ($duration === '') {
            return null;
        }

        return $this->requiredDuration($duration);
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
