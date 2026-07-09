<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BiodataIntakeOcrAttempt;
use App\Models\BulkIntakeBatchItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BulkIntakeDuplicateHistoryHintService
{
    public function __construct(
        private readonly BulkIntakeCandidateMobileCollector $mobileCollector,
        private readonly IntakeDuplicateFieldMatchEvaluator $fieldMatchEvaluator,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function hintsForItem(BulkIntakeBatchItem $item): array
    {
        $intake = $this->intakeForHints($item);
        if (! $intake instanceof BiodataIntake) {
            return [];
        }

        $hints = [];
        $mobiles = $this->snapshotMobiles($intake);
        $name = $this->normalizeName($this->snapshotName($intake));
        $dob = $this->normalizeDate($this->snapshotDob($intake));

        foreach ($this->hashHints($item, $intake) as $hint) {
            $hints[] = $hint;
        }

        foreach ($mobiles as $mobile) {
            $mobileMatch = $this->matchingIntakeByMobile($intake, $mobile);
            if ($mobileMatch instanceof BiodataIntake) {
                $hints[] = $this->hint(
                    'Same mobile found',
                    'same_mobile',
                    'high',
                    'normalized_mobile',
                    $mobileMatch
                );
            }

            $profileId = $this->matchingProfileIdByMobile($mobile, $intake->matrimony_profile_id ? (int) $intake->matrimony_profile_id : null);
            if ($profileId !== null) {
                $hints[] = $this->hint(
                    'Same mobile found',
                    'same_profile_mobile',
                    'high',
                    'existing_profile_contact_mobile',
                    null,
                    $profileId
                );
            }
        }

        if ($name !== null && $dob !== null) {
            $nameDobMatch = $this->matchingIntakeByNameDob($intake, $name, $dob);
            if ($nameDobMatch instanceof BiodataIntake) {
                $fieldMatch = $this->fieldMatchEvaluator->evaluate($intake, $nameDobMatch);
                $hints[] = $this->hint(
                    'Same name + DOB',
                    'same_name_dob',
                    $this->confidenceFromFieldMatch($fieldMatch),
                    $this->reasonFromFieldMatch($fieldMatch, 'name_dob_match'),
                    $nameDobMatch
                );
            }
        }

        return $this->uniqueHints($hints);
    }

    private function intakeForHints(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $loaded = $item->relationLoaded('biodataIntake') ? $item->biodataIntake : null;
        if ($loaded instanceof BiodataIntake) {
            $attributes = $loaded->getAttributes();
            if (
                array_key_exists('parsed_json', $attributes)
                && array_key_exists('approval_snapshot_json', $attributes)
                && array_key_exists('content_hash', $attributes)
            ) {
                return $loaded;
            }
        }

        $intakeId = $item->biodata_intake_id ?? $loaded?->id;
        if ($intakeId === null) {
            return null;
        }

        return BiodataIntake::query()->find((int) $intakeId, $this->intakeColumns());
    }

    /**
     * @return list<string>
     */
    private function intakeColumns(): array
    {
        return [
            'id',
            'matrimony_profile_id',
            'parse_status',
            'parsed_json',
            'approval_snapshot_json',
            'content_hash',
            'created_at',
            'updated_at',
            'parsed_at',
            'reviewed_at',
            'approved_at',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function hashHints(BulkIntakeBatchItem $item, BiodataIntake $intake): array
    {
        $hints = [];
        $contentHash = $this->stringOrNull($intake->content_hash ?? null);
        if ($contentHash !== null) {
            $match = BiodataIntake::query()
                ->whereKeyNot((int) $intake->id)
                ->where('content_hash', $contentHash)
                ->latest('id')
                ->first($this->intakeColumns());
            if ($match instanceof BiodataIntake) {
                $hints[] = $this->hint('Previous intake found', 'content_hash', 'high', 'exact_content_hash', $match);
            }
        }

        foreach ([
            'file_hash' => 'same_file_hash',
            'raw_text_hash' => 'same_raw_text_hash',
        ] as $column => $type) {
            $hash = $this->stringOrNull($item->{$column} ?? null);
            if ($hash === null) {
                continue;
            }

            $matchItem = BulkIntakeBatchItem::query()
                ->whereKeyNot((int) $item->id)
                ->where($column, $hash)
                ->whereNotNull('biodata_intake_id')
                ->latest('id')
                ->first(['id', 'biodata_intake_id']);
            if (! $matchItem instanceof BulkIntakeBatchItem) {
                continue;
            }

            $match = BiodataIntake::query()->find((int) $matchItem->biodata_intake_id, $this->intakeColumns());
            if ($match instanceof BiodataIntake) {
                $hints[] = $this->hint('Previous intake found', $type, 'high', $column, $match);
            }
        }

        $ocrMatch = $this->matchingOcrAttemptIntake($intake);
        if ($ocrMatch instanceof BiodataIntake) {
            $hints[] = $this->hint('Previous intake found', 'ocr_hash', 'medium', 'ocr_attempt_hash', $ocrMatch);
        }

        return $hints;
    }

    private function matchingOcrAttemptIntake(BiodataIntake $intake): ?BiodataIntake
    {
        $attempts = BiodataIntakeOcrAttempt::query()
            ->where('intake_id', (int) $intake->id)
            ->get(['normalized_text_hash', 'image_hash']);

        $hashes = [];
        foreach ($attempts as $attempt) {
            foreach (['normalized_text_hash', 'image_hash'] as $column) {
                $hash = $this->stringOrNull($attempt->{$column} ?? null);
                if ($hash !== null) {
                    $hashes[$column][] = $hash;
                }
            }
        }

        foreach ($hashes as $column => $values) {
            $match = BiodataIntakeOcrAttempt::query()
                ->where('intake_id', '!=', (int) $intake->id)
                ->whereIn($column, array_values(array_unique($values)))
                ->latest('id')
                ->first(['intake_id']);
            if ($match instanceof BiodataIntakeOcrAttempt) {
                return BiodataIntake::query()->find((int) $match->intake_id, $this->intakeColumns());
            }
        }

        return null;
    }

    private function matchingIntakeByMobile(BiodataIntake $current, string $mobile): ?BiodataIntake
    {
        foreach ($this->candidateReferenceIntakes($current) as $reference) {
            if (in_array($mobile, $this->snapshotMobiles($reference), true)) {
                return $reference;
            }
        }

        return null;
    }

    private function matchingIntakeByNameDob(BiodataIntake $current, string $name, string $dob): ?BiodataIntake
    {
        foreach ($this->candidateReferenceIntakes($current) as $reference) {
            if ($this->normalizeName($this->snapshotName($reference)) === $name && $this->normalizeDate($this->snapshotDob($reference)) === $dob) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * @return iterable<int, BiodataIntake>
     */
    private function candidateReferenceIntakes(BiodataIntake $current): iterable
    {
        return BiodataIntake::query()
            ->whereKeyNot((int) $current->id)
            ->where(function ($query): void {
                $query->whereNotNull('approval_snapshot_json')
                    ->orWhereNotNull('parsed_json');
            })
            ->latest('id')
            ->limit(500)
            ->get($this->intakeColumns());
    }

    private function matchingProfileIdByMobile(string $mobile, ?int $currentProfileId): ?int
    {
        $userId = User::query()
            ->where('mobile', $mobile)
            ->orWhere('mobile_backup', $mobile)
            ->value('id');
        if ($userId !== null && Schema::hasTable('matrimony_profiles')) {
            $profileId = DB::table('matrimony_profiles')
                ->where('user_id', (int) $userId)
                ->value('id');
            if ($profileId !== null) {
                $profileId = (int) $profileId;
                if ($profileId !== $currentProfileId) {
                    return $profileId;
                }
            }
        }

        if (Schema::hasTable('profile_contacts')) {
            $profileId = DB::table('profile_contacts')
                ->where('phone_number', $mobile)
                ->value('profile_id');
            if ($profileId !== null) {
                $profileId = (int) $profileId;
                if ($profileId !== $currentProfileId) {
                    return $profileId;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function hint(
        string $label,
        string $type,
        string $confidence,
        string $reason,
        ?BiodataIntake $matchedIntake = null,
        ?int $matchedProfileId = null,
    ): array {
        return [
            'label' => $label,
            'type' => $type,
            'confidence' => $confidence,
            'reason' => $reason,
            'matched_intake_id' => $matchedIntake?->id,
            'matched_profile_id' => $matchedProfileId ?? $matchedIntake?->matrimony_profile_id,
            'last_seen_at' => $this->lastSeenLabel($matchedIntake),
        ];
    }

    /**
     * @param  array<string, mixed>  $fieldMatch
     */
    private function confidenceFromFieldMatch(array $fieldMatch): string
    {
        $score = is_numeric($fieldMatch['duplicate_field_match_score'] ?? null)
            ? (float) $fieldMatch['duplicate_field_match_score']
            : 0.0;

        return $score >= 0.66 ? 'high' : 'medium';
    }

    /**
     * @param  array<string, mixed>  $fieldMatch
     */
    private function reasonFromFieldMatch(array $fieldMatch, string $fallback): string
    {
        $codes = $fieldMatch['duplicate_field_mismatch_codes'] ?? [];
        if (is_array($codes) && $codes !== []) {
            return implode(',', array_map('strval', array_slice($codes, 0, 3)));
        }

        return $fallback;
    }

    /**
     * @param  list<array<string, mixed>>  $hints
     * @return list<array<string, mixed>>
     */
    private function uniqueHints(array $hints): array
    {
        $seen = [];
        $out = [];
        foreach ($hints as $hint) {
            $key = implode('|', [
                (string) ($hint['type'] ?? ''),
                (string) ($hint['matched_intake_id'] ?? ''),
                (string) ($hint['matched_profile_id'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $hint;
            if (count($out) >= 4) {
                break;
            }
        }

        return $out;
    }

    private function lastSeenLabel(?BiodataIntake $intake): ?string
    {
        if (! $intake instanceof BiodataIntake) {
            return null;
        }

        $date = $intake->reviewed_at ?? $intake->approved_at ?? $intake->parsed_at ?? $intake->updated_at ?? $intake->created_at;

        return $date ? $date->format('d-m-Y H:i') : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotData(BiodataIntake $intake): array
    {
        $approval = is_array($intake->approval_snapshot_json) ? $intake->approval_snapshot_json : [];
        if ($approval !== []) {
            return $approval;
        }

        return is_array($intake->parsed_json) ? $intake->parsed_json : [];
    }

    /**
     * @return list<string>
     */
    private function snapshotMobiles(BiodataIntake $intake): array
    {
        return $this->mobileCollector->collectFromSources($this->snapshotData($intake));
    }

    private function snapshotName(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.full_name')
            ?? data_get($data, 'core.name')
            ?? data_get($data, 'candidate.full_name')
            ?? data_get($data, 'candidate.name');
    }

    private function snapshotDob(BiodataIntake $intake): mixed
    {
        $data = $this->snapshotData($intake);

        return data_get($data, 'core.date_of_birth')
            ?? data_get($data, 'core.dob')
            ?? data_get($data, 'candidate.date_of_birth')
            ?? data_get($data, 'candidate.dob');
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

        return $digits !== '' ? $digits : null;
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
