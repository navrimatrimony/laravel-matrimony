<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakConsent;
use App\Models\SuchakProfileRepresentation;
use App\Support\ConsentContactRole;
use App\Support\MobileNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which numbers a Suchak may send a consent request to, and when to suggest
 * trying a different one (PO decision 2026-07-22).
 *
 * Approved rules encoded here:
 * - Consent may ONLY target a number already stored on the profile. The Suchak
 *   cannot type an arbitrary number, so this list is the allowed set.
 * - Candidate's own number first; family numbers are fallback only.
 * - After no response for the configured window the system SUGGESTS the next
 *   stored number ("वडिलांच्या नंबरवर प्रयत्न करा") — it never auto-sends.
 *
 * Reuse (one-engine rule): ordering/labels come from the shared
 * App\Support\ConsentContactRole, extracted from
 * BulkIntakeCandidateContactPlanService which now consumes the same vocabulary.
 * The no-response window reuses the existing whatsapp.bulk_consent_no_response_hours
 * config that the bulk-intake auto-advance command already runs on, so both
 * pipelines wait the same 72h by default.
 * What differs — and legitimately so — is only the SOURCE of the numbers:
 * intake reads an OCR snapshot, this reads the saved profile.
 */
final class SuchakConsentContactSuggestionService
{
    /**
     * @return array{
     *     options: array<int, array<string, mixed>>,
     *     suggest_alternate: bool,
     *     suggestion_reason: string|null,
     *     pending_consent_id: int|null,
     *     no_response_hours: int
     * }
     */
    public function forRepresentation(SuchakProfileRepresentation $representation, MatrimonyProfile $profile): array
    {
        $options = $this->storedContacts($profile);
        $pending = $this->pendingConsent($representation);
        $noResponseHours = max(1, (int) config('whatsapp.bulk_consent_no_response_hours', 72));

        $triedMobiles = $this->triedMobiles($representation);
        foreach ($options as $index => $option) {
            $options[$index]['already_tried'] = in_array($option['mobile'], $triedMobiles, true);
        }

        [$suggestAlternate, $reason] = $this->suggestion($pending, $options, $noResponseHours);

        return [
            'options' => array_values($options),
            'suggest_alternate' => $suggestAlternate,
            'suggestion_reason' => $reason,
            'pending_consent_id' => $pending?->id !== null ? (int) $pending->id : null,
            'no_response_hours' => $noResponseHours,
        ];
    }

    /**
     * The consent allow-list: every number already recorded against this
     * profile, normalised for comparison.
     *
     * This is the SAME set the app offers as options — exposed so the write
     * path can enforce what the read path merely suggests. Without it a Suchak
     * could aim a consent request at any number they typed, including their
     * own, and end up holding a "valid" consent for a person who never agreed.
     *
     * @return array<int, string>
     */
    public function allowedMobiles(MatrimonyProfile $profile): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['mobile'],
            $this->storedContacts($profile),
        )));
    }

    /**
     * Every number stored against this profile, ordered by consent priority.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storedContacts(MatrimonyProfile $profile): array
    {
        $rows = [];
        $add = static function (?string $raw, string $role, ?string $ownerName) use (&$rows): void {
            $mobile = $raw !== null && trim($raw) !== '' ? MobileNumber::normalize($raw) : null;
            if ($mobile === null) {
                return;
            }
            foreach ($rows as $existing) {
                if ($existing['mobile'] === $mobile) {
                    return; // first (highest-priority) owner wins
                }
            }
            $rows[] = [
                'mobile' => $mobile,
                'mobile_masked' => ConsentContactRole::maskMobile($mobile),
                'role' => $role,
                'role_label' => ConsentContactRole::label($role),
                'role_label_mr' => ConsentContactRole::labelMarathi($role),
                'owner_name' => $ownerName !== null && trim($ownerName) !== '' ? trim($ownerName) : null,
            ];
        };

        // 1. Candidate's own account mobile.
        $ownMobile = DB::table('users')->where('id', $profile->user_id)->value('mobile');
        $add($ownMobile !== null ? (string) $ownMobile : null, ConsentContactRole::SELF, $profile->full_name);

        // 2. profile_contacts (canonical contact store, relation-aware).
        //    The relation is a FK to master_contact_relations (`contact_relation_id`),
        //    NOT a `relation_type` string — that column lives on profile_siblings and
        //    profile_relatives. Selecting the wrong one threw a SQL error that took
        //    this whole list down, which is why the guard below is explicit.
        if (Schema::hasTable('profile_contacts')) {
            $hasRelationFk = Schema::hasColumn('profile_contacts', 'contact_relation_id');
            $columns = ['phone_number', 'contact_name'];
            if ($hasRelationFk) {
                $columns[] = 'contact_relation_id';
            }

            $contacts = DB::table('profile_contacts')
                ->where('profile_id', $profile->id)
                ->orderByDesc('is_primary')
                ->get($columns);

            $relationKeys = [];
            if ($hasRelationFk && Schema::hasTable('master_contact_relations')) {
                $ids = $contacts->pluck('contact_relation_id')->filter()->unique()->all();
                if ($ids !== []) {
                    $relationKeys = DB::table('master_contact_relations')
                        ->whereIn('id', $ids)
                        ->pluck('key', 'id')
                        ->all();
                }
            }

            foreach ($contacts as $contact) {
                $relationId = $hasRelationFk ? ($contact->contact_relation_id ?? null) : null;
                $add(
                    $contact->phone_number !== null ? (string) $contact->phone_number : null,
                    $this->roleFromRelation((string) ($relationKeys[$relationId] ?? '')),
                    $contact->contact_name !== null ? (string) $contact->contact_name : null,
                );
            }
        }

        // 3. Dedicated parent slots on the profile.
        foreach ([
            'father_contact_1' => ConsentContactRole::FATHER,
            'father_contact_2' => ConsentContactRole::FATHER,
            'mother_contact_1' => ConsentContactRole::MOTHER,
            'mother_contact_2' => ConsentContactRole::MOTHER,
        ] as $column => $role) {
            if (! Schema::hasColumn('matrimony_profiles', $column)) {
                continue;
            }
            $ownerName = $role === ConsentContactRole::FATHER ? $profile->father_name : $profile->mother_name;
            $add(
                $profile->getAttribute($column) !== null ? (string) $profile->getAttribute($column) : null,
                $role,
                $ownerName !== null ? (string) $ownerName : null,
            );
        }

        // 4. Sibling rows (columns confirmed present — see PRODUCT_MAP §5).
        if (Schema::hasTable('profile_siblings')) {
            $query = DB::table('profile_siblings')->where('profile_id', $profile->id);
            if (Schema::hasColumn('profile_siblings', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
            foreach ($query->get(['name', 'contact_number', 'contact_number_2', 'contact_number_3']) as $sibling) {
                foreach (['contact_number', 'contact_number_2', 'contact_number_3'] as $column) {
                    $add(
                        $sibling->{$column} !== null ? (string) $sibling->{$column} : null,
                        ConsentContactRole::SIBLING,
                        $sibling->name !== null ? (string) $sibling->name : null,
                    );
                }
            }
        }

        // 5. Relative rows (single contact_number column).
        if (Schema::hasTable('profile_relatives')) {
            foreach (DB::table('profile_relatives')
                ->where('profile_id', $profile->id)
                ->get(['relation_type', 'contact_number']) as $relative) {
                $add(
                    $relative->contact_number !== null ? (string) $relative->contact_number : null,
                    ConsentContactRole::OTHER_FAMILY,
                    $relative->relation_type !== null ? str_replace('_', ' ', (string) $relative->relation_type) : null,
                );
            }
        }

        usort($rows, static fn (array $a, array $b): int => ConsentContactRole::priority($a['role']) <=> ConsentContactRole::priority($b['role']));

        return $rows;
    }

    private function roleFromRelation(string $relation): string
    {
        $relation = strtolower(trim($relation));

        // An unrecorded relation must NOT fall through to SELF: `self` is the
        // top consent priority, so an unlabelled row would be offered to the
        // Suchak as "the candidate's own number" on no evidence at all. Unknown
        // means unknown — it sorts last.
        return match (true) {
            str_contains($relation, 'self') || str_contains($relation, 'candidate') => ConsentContactRole::SELF,
            str_contains($relation, 'father') => ConsentContactRole::FATHER,
            str_contains($relation, 'mother') => ConsentContactRole::MOTHER,
            str_contains($relation, 'brother') || str_contains($relation, 'sister') || str_contains($relation, 'sibling') => ConsentContactRole::SIBLING,
            default => ConsentContactRole::OTHER_FAMILY,
        };
    }

    private function pendingConsent(SuchakProfileRepresentation $representation): ?SuchakConsent
    {
        // Column is consent_status (not status) — see suchak_consents schema.
        return SuchakConsent::query()
            ->where('matrimony_profile_id', $representation->matrimony_profile_id)
            ->where('suchak_account_id', $representation->suchak_account_id)
            ->whereIn('consent_status', [
                SuchakConsent::STATUS_REQUESTED,
                SuchakConsent::STATUS_LINK_OPENED,
                SuchakConsent::STATUS_OTP_SENT,
            ])
            ->latest('id')
            ->first();
    }

    /** @return array<int, string> */
    private function triedMobiles(SuchakProfileRepresentation $representation): array
    {
        return SuchakConsent::query()
            ->where('matrimony_profile_id', $representation->matrimony_profile_id)
            ->where('suchak_account_id', $representation->suchak_account_id)
            ->pluck('intended_mobile')
            ->filter()
            ->map(static fn ($mobile): ?string => MobileNumber::normalize((string) $mobile))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     * @return array{0: bool, 1: string|null}
     */
    private function suggestion(?SuchakConsent $pending, array $options, int $noResponseHours): array
    {
        if ($pending === null) {
            return [false, null];
        }

        $untriedExists = false;
        foreach ($options as $option) {
            if ($option['already_tried'] === false) {
                $untriedExists = true;
                break;
            }
        }
        if (! $untriedExists) {
            return [false, 'no_untried_contacts'];
        }

        $sentAt = $pending->created_at instanceof Carbon ? $pending->created_at : null;
        if ($sentAt === null || $sentAt->diffInHours(now()) < $noResponseHours) {
            return [false, null];
        }

        if ($pending->consent_status === SuchakConsent::STATUS_REQUESTED) {
            return [true, 'no_response'];
        }

        // Link opened / OTP sent but never verified — engaged yet stuck.
        return [true, 'started_not_completed'];
    }
}
