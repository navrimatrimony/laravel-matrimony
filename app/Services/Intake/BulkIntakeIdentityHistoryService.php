<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Models\BulkIntakeIdentityHistory;
use App\Models\User;
use App\Support\MobileNumber;

class BulkIntakeIdentityHistoryService
{
    public function __construct(
        private readonly BulkIntakeCandidateMobileCollector $mobileCollector,
    ) {}

    /**
     * @return list<string>
     */
    public function blockingReasonCodes(): array
    {
        return BulkIntakeIdentityHistory::BLOCKING_REASON_CODES;
    }

    public function isBlockingReasonCode(string $reasonCode): bool
    {
        return in_array($reasonCode, $this->blockingReasonCodes(), true);
    }

    public function reasonCodeLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            BulkIntakeIdentityHistory::REASON_ALREADY_MARRIED => 'Already married',
            BulkIntakeIdentityHistory::REASON_NOT_INTERESTED => 'Not interested',
            BulkIntakeIdentityHistory::REASON_WRONG_NUMBER => 'Wrong number',
            BulkIntakeIdentityHistory::REASON_DO_NOT_SUGGEST => 'Do not suggest',
            BulkIntakeIdentityHistory::REASON_NO_RESPONSE => 'No response',
            default => str_replace('_', ' ', $reasonCode),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blockingHistoriesForItem(BulkIntakeBatchItem $item): array
    {
        $identity = $this->identityKeysFromItem($item);
        if ($identity['mobiles'] === [] && ($identity['name'] === null || $identity['dob'] === null)) {
            return [];
        }

        $query = BulkIntakeIdentityHistory::query()
            ->whereIn('reason_code', $this->blockingReasonCodes())
            ->where(function ($builder) use ($identity): void {
                $hasMobileClause = false;

                if ($identity['mobiles'] !== []) {
                    $builder->whereIn('normalized_mobile', $identity['mobiles']);
                    $hasMobileClause = true;
                }

                if ($identity['name'] !== null && $identity['dob'] !== null) {
                    $clause = function ($nameDobQuery) use ($identity): void {
                        $nameDobQuery
                            ->where('normalized_name', $identity['name'])
                            ->where('normalized_dob', $identity['dob']);
                    };

                    if ($hasMobileClause) {
                        $builder->orWhere($clause);
                    } else {
                        $builder->where($clause);
                    }
                }
            })
            ->latest('id')
            ->limit(10);

        if ($item->id !== null) {
            $query->where(function ($excludeCurrent) use ($item): void {
                $excludeCurrent
                    ->whereNull('source_bulk_intake_batch_item_id')
                    ->orWhere('source_bulk_intake_batch_item_id', '!=', (int) $item->id);
            });
        }

        return $query
            ->get()
            ->map(fn (BulkIntakeIdentityHistory $history): array => $this->historyPayload($history))
            ->values()
            ->all();
    }

    public function recordFromScreeningReview(
        BulkIntakeBatchItem $item,
        User $actor,
        string $reasonKey,
        ?string $note = null
    ): ?BulkIntakeIdentityHistory {
        if (! $this->isBlockingReasonCode($reasonKey)) {
            return null;
        }

        return $this->recordForItem(
            $item,
            $reasonKey,
            BulkIntakeIdentityHistory::SOURCE_ADMIN_SCREENING,
            $actor,
            $note
        );
    }

    public function recordFromManualDuplicate(
        BulkIntakeBatchItem $item,
        User $actor,
        ?string $note = null
    ): BulkIntakeIdentityHistory {
        return $this->recordForItem(
            $item,
            BulkIntakeIdentityHistory::REASON_DO_NOT_SUGGEST,
            BulkIntakeIdentityHistory::SOURCE_ADMIN_DUPLICATE,
            $actor,
            $note ?? 'Manual duplicate mark'
        );
    }

    public function recordForItem(
        BulkIntakeBatchItem $item,
        string $reasonCode,
        string $sourceType,
        ?User $actor = null,
        ?string $note = null,
        ?string $normalizedMobile = null,
    ): BulkIntakeIdentityHistory {
        $identity = $this->identityKeysFromItem($item);
        $intakeId = $item->biodata_intake_id ? (int) $item->biodata_intake_id : null;
        $mobile = MobileNumber::normalize($normalizedMobile) ?? ($identity['mobiles'][0] ?? null);

        return BulkIntakeIdentityHistory::query()->create([
            'reason_code' => $reasonCode,
            'normalized_mobile' => $mobile,
            'normalized_name' => $identity['name'],
            'normalized_dob' => $identity['dob'],
            'normalized_gender' => $identity['gender'],
            'source_type' => $sourceType,
            'source_bulk_intake_batch_item_id' => (int) $item->id,
            'source_biodata_intake_id' => $intakeId,
            'recorded_by_user_id' => $actor?->id,
            'note' => $this->stringOrNull($note),
        ]);
    }

    /**
     * @return array{
     *     mobiles: list<string>,
     *     name: string|null,
     *     dob: string|null,
     *     gender: string|null
     * }
     */
    public function identityKeysFromItem(BulkIntakeBatchItem $item): array
    {
        $intake = $this->intakeForIdentity($item);
        $snapshot = $this->snapshotData($intake);

        $gender = strtolower((string) (
            data_get($snapshot, 'core.gender')
            ?? data_get($snapshot, 'gender')
            ?? data_get($snapshot, 'candidate.gender')
            ?? ''
        ));

        return [
            'mobiles' => $this->mobileCollector->collectFromSources($snapshot),
            'name' => $this->normalizeName(
                data_get($snapshot, 'core.full_name')
                ?? data_get($snapshot, 'core.name')
                ?? data_get($snapshot, 'candidate.full_name')
                ?? data_get($snapshot, 'candidate.name')
            ),
            'dob' => $this->normalizeDate(
                data_get($snapshot, 'core.date_of_birth')
                ?? data_get($snapshot, 'core.dob')
                ?? data_get($snapshot, 'candidate.date_of_birth')
                ?? data_get($snapshot, 'candidate.dob')
            ),
            'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
        ];
    }

  /**
     * @return array<string, mixed>
     */
    private function historyPayload(BulkIntakeIdentityHistory $history): array
    {
        return [
            'id' => (int) $history->id,
            'reason_code' => (string) $history->reason_code,
            'label' => $this->reasonCodeLabel((string) $history->reason_code),
            'normalized_mobile' => $history->normalized_mobile,
            'normalized_name' => $history->normalized_name,
            'normalized_dob' => $history->normalized_dob,
            'source_type' => (string) $history->source_type,
            'source_bulk_intake_batch_item_id' => $history->source_bulk_intake_batch_item_id,
            'source_biodata_intake_id' => $history->source_biodata_intake_id,
            'recorded_at' => $history->created_at?->toISOString(),
        ];
    }

    private function intakeForIdentity(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $loaded = $item->relationLoaded('biodataIntake') ? $item->biodataIntake : null;
        if ($loaded instanceof BiodataIntake) {
            return $loaded;
        }

        $intakeId = $item->biodata_intake_id ?? $loaded?->id;
        if ($intakeId === null) {
            return null;
        }

        return BiodataIntake::query()->find((int) $intakeId, [
            'id',
            'parsed_json',
            'approval_snapshot_json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(?BiodataIntake $intake): array
    {
        $approval = is_array($intake?->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        if ($approval !== []) {
            return $approval;
        }

        return is_array($intake?->parsed_json) ? $intake->parsed_json : [];
    }

    private function normalizeName(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = $this->stringOrNull($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $matches)) {
            return sprintf('%04d%02d%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $value, $matches)) {
            return sprintf('%04d%02d%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) === 8 ? $digits : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
