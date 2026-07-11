<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use App\Services\BiodataParserService;
use App\Services\Ocr\OcrNormalize;
use App\Support\MobileNumber;
use Illuminate\Support\Facades\DB;

/**
 * Consent WhatsApp contact queue (candidate/family only) and separate biodata suchak directory.
 */
class BulkIntakeCandidateContactPlanService
{
    public const ROLE_SELF = 'self';

    public const ROLE_FATHER = 'father';

    public const ROLE_MOTHER = 'mother';

    public const ROLE_OTHER_FAMILY = 'other_family';

    public const ROLE_OCR_OTHER = 'ocr_other';

    /**
     * @return array{
     *     synced_at: string,
     *     active_index: int,
     *     queue: list<array{mobile: string, role: string, source: string}>,
     *     tried: list<array{mobile: string, reason: string, at: string}>,
     *     suchak_directory: list<array{name: string|null, village: string|null, mobile: string, source: string}>
     * }
     */
    public function syncForItem(BulkIntakeBatchItem $item): array
    {
        $item->loadMissing('biodataIntake');
        $plan = $this->composePlan($item);
        $this->persistPlan($item, $plan);

        return $plan;
    }

    /**
     * @return array{
     *     synced_at: string,
     *     active_index: int,
     *     queue: list<array{mobile: string, role: string, source: string}>,
     *     tried: list<array{mobile: string, reason: string, at: string}>,
     *     suchak_directory: list<array{name: string|null, village: string|null, mobile: string, source: string}>
     * }
     */
    private function composePlan(BulkIntakeBatchItem $item): array
    {
        $item->loadMissing('biodataIntake');
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return $this->emptyPlan();
        }

        $snapshot = $this->sourceSnapshot($intake);
        $ocrText = $this->ocrText($intake);
        $suchakDirectory = $this->extractSuchakDirectory($ocrText);
        $suchakPhones = $this->suchakPhoneSet($suchakDirectory, $ocrText);
        $queue = $this->buildConsentQueue($snapshot, $ocrText, $suchakPhones);

        $existing = $this->readPlanMeta($item);
        $activeIndex = is_array($existing) ? min(
            max(0, (int) ($existing['active_index'] ?? 0)),
            max(0, count($queue) - 1),
        ) : 0;

        if ($queue === []) {
            $activeIndex = 0;
        }

        return [
            'synced_at' => now()->toISOString(),
            'active_index' => $activeIndex,
            'queue' => $queue,
            'tried' => is_array($existing['tried'] ?? null) ? array_values($existing['tried']) : [],
            'suchak_directory' => $suchakDirectory,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function planForItem(BulkIntakeBatchItem $item, bool $refresh = false): ?array
    {
        if ($refresh) {
            return $this->syncForItem($item->fresh());
        }

        $persisted = $this->readPlanMeta($item);
        if (is_array($persisted)) {
            $queue = is_array($persisted['queue'] ?? null) ? $persisted['queue'] : [];
            if ($queue !== []) {
                return $persisted;
            }
        }

        return $this->composePlan($item);
    }

    public function activeMobile(BulkIntakeBatchItem $item, bool $refresh = false): ?string
    {
        $plan = $this->planForItem($item, $refresh);

        return is_array($plan) ? $this->activeMobileFromPlan($plan) : null;
    }

    public function hasUsableMobile(BulkIntakeBatchItem $item): bool
    {
        if ($this->activeMobile($item) !== null) {
            return true;
        }

        $item->loadMissing('biodataIntake');
        $intake = $item->biodataIntake;
        if (! $intake instanceof BiodataIntake) {
            return false;
        }

        $snapshot = $this->sourceSnapshot($intake);
        $ocrText = $this->ocrText($intake);
        $mobile = app(BulkIntakeCandidateMobileCollector::class)->displayFromSources(
            $snapshot,
            $ocrText !== '' ? $ocrText : null,
        );

        return MobileNumber::normalize(is_string($mobile) ? $mobile : null) !== null;
    }

    /**
     * @return list<string>
     */
    public function consentMobileDisplayList(BulkIntakeBatchItem $item): array
    {
        $plan = $this->planForItem($item);
        if (! is_array($plan)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): string => (string) ($row['mobile'] ?? ''),
            is_array($plan['queue'] ?? null) ? $plan['queue'] : [],
        ));
    }

    public function hasMoreContacts(BulkIntakeBatchItem $item): bool
    {
        $plan = $this->planForItem($item);
        if (! is_array($plan)) {
            return false;
        }

        $queue = is_array($plan['queue'] ?? null) ? $plan['queue'] : [];
        $activeIndex = (int) ($plan['active_index'] ?? 0);

        return $activeIndex + 1 < count($queue);
    }

    /**
     * @return array{advanced: bool, next_mobile: string|null, exhausted: bool, plan: array<string, mixed>|null}
     */
    public function advanceAfterAttemptFailure(BulkIntakeBatchItem $item, string $reason): array
    {
        return DB::transaction(function () use ($item, $reason): array {
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $plan = $this->syncForItem($locked);
            $activeMobile = $this->activeMobileFromPlan($plan);
            if ($activeMobile !== null) {
                $tried = is_array($plan['tried'] ?? null) ? $plan['tried'] : [];
                $tried[] = [
                    'mobile' => $activeMobile,
                    'reason' => $reason,
                    'at' => now()->toISOString(),
                ];
                $plan['tried'] = $tried;
            }

            $queue = is_array($plan['queue'] ?? null) ? $plan['queue'] : [];
            $nextIndex = (int) ($plan['active_index'] ?? 0) + 1;
            if ($nextIndex < count($queue)) {
                $plan['active_index'] = $nextIndex;
                $this->persistPlan($locked, $plan);
                $intake = $locked->biodataIntake;
                if ($intake instanceof BiodataIntake) {
                    $this->mirrorPrimaryContactOnIntake($intake, $this->activeMobileFromPlan($plan));
                }

                return [
                    'advanced' => true,
                    'next_mobile' => $this->activeMobileFromPlan($plan),
                    'exhausted' => false,
                    'plan' => $plan,
                ];
            }

            $this->persistPlan($locked, $plan);

            return [
                'advanced' => false,
                'next_mobile' => null,
                'exhausted' => true,
                'plan' => $plan,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, bool>  $suchakPhones
     * @return list<array{mobile: string, role: string, source: string}>
     */
    private function buildConsentQueue(array $snapshot, string $ocrText, array $suchakPhones): array
    {
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $contacts = is_array($snapshot['contacts'] ?? null) ? $snapshot['contacts'] : [];
        $rows = [];

        foreach ($this->selfSources($core, $contacts) as $source => $raw) {
            $this->pushQueueRow($rows, $raw, self::ROLE_SELF, $source, $ocrText, $suchakPhones);
        }
        foreach ($this->fatherSources($core) as $source => $raw) {
            $this->pushQueueRow($rows, $raw, self::ROLE_FATHER, $source, $ocrText, $suchakPhones);
        }
        foreach ($this->motherSources($core) as $source => $raw) {
            $this->pushQueueRow($rows, $raw, self::ROLE_MOTHER, $source, $ocrText, $suchakPhones);
        }
        foreach ($this->otherFamilySources($contacts) as $source => $raw) {
            $this->pushQueueRow($rows, $raw, self::ROLE_OTHER_FAMILY, $source, $ocrText, $suchakPhones);
        }

        $collector = app(BulkIntakeCandidateMobileCollector::class);
        foreach ($collector->collectFromSources($snapshot, $ocrText !== '' ? $ocrText : null) as $mobile) {
            $this->pushQueueRow($rows, $mobile, self::ROLE_OCR_OTHER, 'ocr_scan', $ocrText, $suchakPhones);
        }

        return array_values($rows);
    }

    /**
     * @param  list<array{mobile: string, role: string, source: string}>  $rows
     * @param  array<string, bool>  $suchakPhones
     */
    private function pushQueueRow(
        array &$rows,
        mixed $raw,
        string $role,
        string $source,
        string $ocrText,
        array $suchakPhones,
    ): void {
        $mobile = MobileNumber::normalize(is_scalar($raw) ? (string) $raw : null);
        if ($mobile === null) {
            return;
        }
        if (isset($suchakPhones[$mobile])) {
            return;
        }
        if (BiodataParserService::isPhoneExcludedSuchakHeaderStatic($ocrText, $mobile)) {
            return;
        }
        foreach ($rows as $row) {
            if (($row['mobile'] ?? null) === $mobile) {
                return;
            }
        }

        $rows[] = [
            'mobile' => $mobile,
            'role' => $role,
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $core
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, string>
     */
    private function selfSources(array $core, array $contacts): array
    {
        $sources = [];
        foreach ([
            'core.primary_contact_number' => $core['primary_contact_number'] ?? null,
            'core.mobile' => $core['mobile'] ?? null,
            'core.user_contact_1' => $core['user_contact_1'] ?? null,
            'core.contact_number' => $core['contact_number'] ?? null,
        ] as $source => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $sources[$source] = (string) $value;
            }
        }

        foreach ($contacts as $index => $contact) {
            if (! is_array($contact) || $this->contactLooksLikeSuchak($contact)) {
                continue;
            }
            if ($this->contactRelationMatches($contact, ['self', 'candidate', 'चिरंजीव', 'उमेदवार', 'वधू', 'वर'])) {
                foreach (['phone_number', 'number', 'mobile', 'contact_number'] as $key) {
                    $value = $contact[$key] ?? null;
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $sources['contacts.'.$index.'.'.$key] = (string) $value;
                    }
                }
            }
        }

        if (is_array($core['all_contact_numbers'] ?? null)) {
            foreach ($core['all_contact_numbers'] as $index => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $sources['core.all_contact_numbers.'.$index] = (string) $value;
                }
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $core
     * @return array<string, string>
     */
    private function fatherSources(array $core): array
    {
        $sources = [];
        foreach ([
            'core.father_contact_1',
            'core.father_contact_2',
            'core.father_contact_3',
            'core.father_contact_number',
        ] as $path) {
            $value = str_starts_with($path, 'core.')
                ? ($core[substr($path, 5)] ?? null)
                : null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $sources[$path] = (string) $value;
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $core
     * @return array<string, string>
     */
    private function motherSources(array $core): array
    {
        $sources = [];
        foreach ([
            'core.mother_contact_1',
            'core.mother_contact_2',
            'core.mother_contact_3',
            'core.mother_contact_number',
        ] as $path) {
            $key = substr($path, 5);
            $value = $core[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $sources[$path] = (string) $value;
            }
        }

        return $sources;
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, string>
     */
    private function otherFamilySources(array $contacts): array
    {
        $sources = [];
        foreach ($contacts as $index => $contact) {
            if (! is_array($contact) || $this->contactLooksLikeSuchak($contact)) {
                continue;
            }
            if ($this->contactRelationMatches($contact, [
                'father', 'mother', 'brother', 'sister', 'uncle', 'aunt',
                'वडील', 'आई', 'भाऊ', 'बहीण', 'काका', 'मामा', 'मावशी', 'आत्या', 'चुलत',
            ])) {
                foreach (['phone_number', 'number', 'mobile', 'contact_number'] as $key) {
                    $value = $contact[$key] ?? null;
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $sources['contacts.'.$index.'.'.$key] = (string) $value;
                    }
                }
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function contactLooksLikeSuchak(array $contact): bool
    {
        $blob = mb_strtolower(implode(' ', array_filter([
            is_scalar($contact['relation'] ?? null) ? (string) $contact['relation'] : '',
            is_scalar($contact['relationship'] ?? null) ? (string) $contact['relationship'] : '',
            is_scalar($contact['label'] ?? null) ? (string) $contact['label'] : '',
            is_scalar($contact['name'] ?? null) ? (string) $contact['name'] : '',
            is_scalar($contact['organization'] ?? null) ? (string) $contact['organization'] : '',
        ])));

        return preg_match('/suchak|सूचक|ब्युरो|bureau|वध[ूु]वर|विवाह\s*सूचक/u', $blob) === 1;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @param  list<string>  $needles
     */
    private function contactRelationMatches(array $contact, array $needles): bool
    {
        $blob = mb_strtolower(implode(' ', array_filter([
            is_scalar($contact['relation'] ?? null) ? (string) $contact['relation'] : '',
            is_scalar($contact['relationship'] ?? null) ? (string) $contact['relationship'] : '',
            is_scalar($contact['label'] ?? null) ? (string) $contact['label'] : '',
        ])));

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($blob, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{name: string|null, village: string|null, mobile: string, source: string}>
     */
    private function extractSuchakDirectory(string $ocrText): array
    {
        $ocrText = trim($ocrText);
        if ($ocrText === '') {
            return [];
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $ocrText)), static fn (string $line): bool => $line !== ''));
        $bodyIdx = $this->findFirstBiodataAnchorLineIndex($lines);
        if ($bodyIdx === null || $bodyIdx < 1) {
            return [];
        }

        $headerLines = array_slice($lines, 0, $bodyIdx);
        if (! $this->linesContainSuchakBureauMarker($headerLines)) {
            return [];
        }

        $directory = [];
        $pendingName = null;
        $pendingVillage = null;

        foreach ($headerLines as $line) {
            $phones = $this->phonesInLine($line);
            if ($phones !== []) {
                foreach ($phones as $mobile) {
                    $directory[] = [
                        'name' => $pendingName,
                        'village' => $pendingVillage,
                        'mobile' => $mobile,
                        'source' => 'biodata_header',
                    ];
                }
                $pendingName = null;
                $pendingVillage = null;

                continue;
            }

            if ($this->lineLooksLikeVillage($line)) {
                $pendingVillage = $line;

                continue;
            }

            if ($this->lineLooksLikeSuchakName($line)) {
                $pendingName = $line;
            }
        }

        return $this->uniqueSuchakDirectory($directory);
    }

    /**
     * @param  list<array{name: string|null, village: string|null, mobile: string, source: string}>  $directory
     * @return array<string, bool>
     */
    private function suchakPhoneSet(array $directory, string $ocrText): array
    {
        $set = [];
        foreach ($directory as $entry) {
            $mobile = MobileNumber::normalize((string) ($entry['mobile'] ?? ''));
            if ($mobile !== null) {
                $set[$mobile] = true;
            }
        }

        $digitLine = OcrNormalize::normalizeDigits($ocrText);
        if (preg_match_all('/\b([6-9]\d{9})\b/', $digitLine, $matches)) {
            foreach ($matches[1] as $candidate) {
                $mobile = MobileNumber::normalize($candidate);
                if ($mobile !== null && BiodataParserService::isPhoneExcludedSuchakHeaderStatic($ocrText, $mobile)) {
                    $set[$mobile] = true;
                }
            }
        }

        return $set;
    }

    /**
     * @param  list<array{name: string|null, village: string|null, mobile: string, source: string}>  $directory
     * @return list<array{name: string|null, village: string|null, mobile: string, source: string}>
     */
    private function uniqueSuchakDirectory(array $directory): array
    {
        $seen = [];
        $unique = [];
        foreach ($directory as $entry) {
            $mobile = (string) ($entry['mobile'] ?? '');
            if ($mobile === '' || isset($seen[$mobile])) {
                continue;
            }
            $seen[$mobile] = true;
            $unique[] = $entry;
        }

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function phonesInLine(string $line): array
    {
        $digitLine = OcrNormalize::normalizeDigits($line);
        if (! preg_match_all('/\b([6-9]\d{9})\b/', $digitLine, $matches)) {
            return [];
        }

        $phones = [];
        foreach ($matches[1] as $candidate) {
            $mobile = MobileNumber::normalize($candidate);
            if ($mobile !== null) {
                $phones[] = $mobile;
            }
        }

        return $phones;
    }

    private function lineLooksLikeVillage(string $line): bool
    {
        return preg_match('/\b(?:गाव|गांव|तालुका|ता\.|जिल्हा|जि\.|ता\-|जि\-|पोस्ट|पिन)/u', $line) === 1;
    }

    private function lineLooksLikeSuchakName(string $line): bool
    {
        if ($this->phonesInLine($line) !== []) {
            return false;
        }

        return preg_match('/(?:वध[ूु]वर|विवाह\s*सूचक|सूचक\s*केंद्र|लग्न\s*सूचक|ब्युरो|bureau|suchak)/ui', $line) === 1;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function findFirstBiodataAnchorLineIndex(array $lines): ?int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^(?:मुलीचे|मुलाचे|वधूचे)\s*(?:नाव|नांव)\s*[:\-]/u', $line)) {
                return $index;
            }
            if (preg_match('/^जन्म\s*(?:तारीख|तारिख|दिनांक|वेळ|वार)\b/u', $line)) {
                return $index;
            }
            if (preg_match('/^(?:वडिलांचे|वडिलाचे|वडीलांचे)\s*(?:नाव|नांव)/u', $line)) {
                return $index;
            }
            if (preg_match('/^उंची\b/u', $line)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function linesContainSuchakBureauMarker(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:वध[ूु]वर|विवाह\s*सूचक|सूचक\s*केंद्र|लग्न\s*सूचक|बायोडाटा|ब्युरो|bureau|suchak)/ui', $line)) {
                return true;
            }
        }

        return false;
    }

    private function ocrText(BiodataIntake $intake): string
    {
        $parseInput = trim((string) ($intake->last_parse_input_text ?? ''));
        if ($parseInput !== '') {
            return $parseInput;
        }

        return trim((string) ($intake->raw_ocr_text ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceSnapshot(BiodataIntake $intake): array
    {
        if (is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []) {
            return $intake->approval_snapshot_json;
        }

        if (is_array($intake->parsed_json) && $intake->parsed_json !== []) {
            return $intake->parsed_json;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function activeMobileFromPlan(array $plan): ?string
    {
        $queue = is_array($plan['queue'] ?? null) ? $plan['queue'] : [];
        $index = (int) ($plan['active_index'] ?? 0);
        $row = $queue[$index] ?? null;

        return is_array($row) ? MobileNumber::normalize((string) ($row['mobile'] ?? '')) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPlan(): array
    {
        return [
            'synced_at' => now()->toISOString(),
            'active_index' => 0,
            'queue' => [],
            'tried' => [],
            'suchak_directory' => [],
        ];
    }

    /**
     * @return array{
     *     active_mobile: string|null,
     *     active_role: string|null,
     *     active_role_label: string|null,
     *     queue_total: int,
     *     active_position: int,
     *     tried_count: int,
     *     suchak_directory: list<array{name: string|null, village: string|null, mobile: string, source: string}>,
     *     suchak_count: int
     * }
     */
    public function adminSummary(BulkIntakeBatchItem $item): array
    {
        $plan = $this->planForItem($item);
        if (! is_array($plan)) {
            return [
                'active_mobile' => null,
                'active_role' => null,
                'active_role_label' => null,
                'queue_total' => 0,
                'active_position' => 0,
                'tried_count' => 0,
                'suchak_directory' => [],
                'suchak_count' => 0,
            ];
        }

        $queue = is_array($plan['queue'] ?? null) ? $plan['queue'] : [];
        $activeIndex = (int) ($plan['active_index'] ?? 0);
        $activeRow = is_array($queue[$activeIndex] ?? null) ? $queue[$activeIndex] : null;
        $activeRole = is_array($activeRow) ? (string) ($activeRow['role'] ?? '') : '';
        $suchakDirectory = is_array($plan['suchak_directory'] ?? null) ? array_values($plan['suchak_directory']) : [];

        return [
            'active_mobile' => $this->activeMobileFromPlan($plan),
            'active_role' => $activeRole !== '' ? $activeRole : null,
            'active_role_label' => $this->roleLabel($activeRole),
            'queue_total' => count($queue),
            'active_position' => $queue === [] ? 0 : $activeIndex + 1,
            'tried_count' => count(is_array($plan['tried'] ?? null) ? $plan['tried'] : []),
            'suchak_directory' => $suchakDirectory,
            'suchak_count' => count($suchakDirectory),
        ];
    }

    private function roleLabel(string $role): ?string
    {
        return match ($role) {
            self::ROLE_SELF => 'Candidate',
            self::ROLE_FATHER => 'Father',
            self::ROLE_MOTHER => 'Mother',
            self::ROLE_OTHER_FAMILY => 'Family',
            self::ROLE_OCR_OTHER => 'Other',
            default => $role !== '' ? str_replace('_', ' ', $role) : null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readPlanMeta(BulkIntakeBatchItem $item): ?array
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $plan = $meta['consent_contact_plan'] ?? null;

        return is_array($plan) ? $plan : null;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function persistPlan(BulkIntakeBatchItem $item, array $plan): void
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $meta['consent_contact_plan'] = $plan;
        $meta['biodata_suchak_directory'] = is_array($plan['suchak_directory'] ?? null) ? $plan['suchak_directory'] : [];
        $item->forceFill(['item_meta_json' => $meta])->save();
    }

    private function mirrorPrimaryContactOnIntake(BiodataIntake $intake, ?string $mobile): void
    {
        if ($mobile === null) {
            return;
        }

        $snapshot = $this->sourceSnapshot($intake);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        if (($core['primary_contact_number'] ?? null) === $mobile) {
            return;
        }

        $core['primary_contact_number'] = $mobile;
        $snapshot['core'] = $core;

        if (is_array($intake->approval_snapshot_json) && $intake->approval_snapshot_json !== []) {
            $intake->forceFill(['approval_snapshot_json' => $snapshot])->save();

            return;
        }

        if (is_array($intake->parsed_json) && $intake->parsed_json !== []) {
            $parsed = $intake->parsed_json;
            $parsedCore = is_array($parsed['core'] ?? null) ? $parsed['core'] : [];
            $parsedCore['primary_contact_number'] = $mobile;
            $parsed['core'] = $parsedCore;
            $intake->forceFill(['parsed_json' => $parsed])->save();
        }
    }
}
