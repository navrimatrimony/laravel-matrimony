<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakCollaborationRequest;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPipeline;
use App\Models\SuchakProfileNote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SuchakCrmLedgerService
{
    public function __construct(
        private readonly SuchakActivityLogger $activityLogger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createProfileNote(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakProfileNote {
        $account->refresh();
        $profile->refresh();
        $this->assertVerifiedOwner($account, $actor);

        $noteText = $this->requiredLimitedText($attributes['note_text'] ?? null, 4000, 'Note text is required.');
        $this->assertNoPrivateContactText($noteText);
        $noteType = $this->allowedValue(
            $attributes['note_type'] ?? SuchakProfileNote::TYPE_GENERAL,
            SuchakProfileNote::TYPES,
            'Invalid Suchak note type.',
        );
        $visibility = $this->allowedValue(
            $attributes['visibility'] ?? SuchakProfileNote::VISIBILITY_PRIVATE,
            [SuchakProfileNote::VISIBILITY_PRIVATE],
            'Suchak notes are private in MVP.',
        );
        $collaboration = $this->optionalCollaboration($attributes['collaboration_request_id'] ?? null, $account, $profile);

        return DB::transaction(function () use ($account, $actor, $profile, $noteType, $noteText, $visibility, $attributes, $collaboration, $ipAddress, $userAgent): SuchakProfileNote {
            $note = SuchakProfileNote::query()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'collaboration_request_id' => $collaboration?->id,
                'note_type' => $noteType,
                'note_text' => $noteText,
                'visibility' => $visibility,
                'follow_up_at' => $attributes['follow_up_at'] ?? null,
            ]);

            $this->recordActivity(
                SuchakActivityLog::ACTION_CRM_NOTE_ADDED,
                'suchak_profile_note',
                (int) $note->id,
                $account,
                $actor,
                $profile,
                $ipAddress,
                $userAgent,
                [
                    'context' => 'crm_note_added',
                    'note_type' => $note->note_type,
                    'visibility' => $note->visibility,
                    'collaboration_request_id' => $note->collaboration_request_id,
                    'has_follow_up_at' => $note->follow_up_at !== null,
                ],
            );

            return $note->fresh(['suchakAccount', 'matrimonyProfile', 'collaborationRequest']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createLedgerEntry(
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        array $attributes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): SuchakLedgerEntry {
        $account->refresh();
        $profile->refresh();
        $this->assertVerifiedOwner($account, $actor);

        $entryType = $this->allowedValue(
            $attributes['entry_type'] ?? null,
            SuchakLedgerEntry::TYPES,
            'Invalid Suchak ledger entry type.',
        );
        $status = $this->allowedValue(
            $attributes['status'] ?? SuchakLedgerEntry::STATUS_EXPECTED,
            SuchakLedgerEntry::STATUSES,
            'Invalid Suchak ledger status.',
        );
        $note = $this->nullableLimitedText($attributes['note'] ?? null, 2000);
        if ($note !== null) {
            $this->assertNoPrivateContactText($note);
        }
        $pipeline = $this->optionalPipeline($attributes['pipeline_id'] ?? null, $account, $profile);
        $collaboration = $this->optionalCollaboration($attributes['collaboration_request_id'] ?? null, $account, $profile);

        return DB::transaction(function () use ($account, $actor, $profile, $attributes, $entryType, $status, $note, $pipeline, $collaboration, $ipAddress, $userAgent): SuchakLedgerEntry {
            $entry = SuchakLedgerEntry::query()->create([
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
                'pipeline_id' => $pipeline?->id,
                'collaboration_request_id' => $collaboration?->id,
                'entry_type' => $entryType,
                'amount' => $this->nullableAmount($attributes['amount'] ?? null),
                'currency' => $this->currency($attributes['currency'] ?? 'INR'),
                'status' => $status,
                'due_date' => $attributes['due_date'] ?? null,
                'paid_at' => $attributes['paid_at'] ?? null,
                'note' => $note,
            ]);

            $this->recordActivity(
                SuchakActivityLog::ACTION_LEDGER_ENTRY_CREATED,
                'suchak_ledger_entry',
                (int) $entry->id,
                $account,
                $actor,
                $profile,
                $ipAddress,
                $userAgent,
                [
                    'context' => 'ledger_entry_created',
                    'entry_type' => $entry->entry_type,
                    'status' => $entry->status,
                    'currency' => $entry->currency,
                    'pipeline_id' => $entry->pipeline_id,
                    'collaboration_request_id' => $entry->collaboration_request_id,
                    'has_amount' => $entry->amount !== null,
                    'has_due_date' => $entry->due_date !== null,
                    'has_paid_at' => $entry->paid_at !== null,
                ],
            );

            return $entry->fresh(['suchakAccount', 'matrimonyProfile', 'pipeline', 'collaborationRequest']);
        });
    }

    /**
     * @return Collection<int, SuchakProfileNote>
     */
    public function privateNotesForProfile(SuchakAccount $account, User $actor, MatrimonyProfile $profile): Collection
    {
        $this->assertVerifiedOwner($account, $actor);

        return SuchakProfileNote::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, SuchakLedgerEntry>
     */
    public function privateLedgerForProfile(SuchakAccount $account, User $actor, MatrimonyProfile $profile): Collection
    {
        $this->assertVerifiedOwner($account, $actor);

        return SuchakLedgerEntry::query()
            ->where('suchak_account_id', $account->id)
            ->where('matrimony_profile_id', $profile->id)
            ->orderByDesc('id')
            ->get();
    }

    private function assertVerifiedOwner(SuchakAccount $account, User $actor): void
    {
        if ((int) $account->user_id !== (int) $actor->id) {
            throw new InvalidArgumentException('Only the owning Suchak account can manage private CRM records.');
        }

        if (! $account->isVerified()) {
            throw new InvalidArgumentException('Only verified Suchak accounts can manage private CRM records.');
        }
    }

    private function optionalPipeline(mixed $pipelineId, SuchakAccount $account, MatrimonyProfile $profile): ?SuchakPipeline
    {
        if ($pipelineId === null || $pipelineId === '') {
            return null;
        }

        $pipeline = SuchakPipeline::query()->findOrFail((int) $pipelineId);
        if ((int) $pipeline->selected_suchak_account_id !== (int) $account->id) {
            throw new InvalidArgumentException('Ledger pipeline context must belong to the Suchak account.');
        }

        if (! in_array((int) $profile->id, [
            (int) $pipeline->target_matrimony_profile_id,
            (int) $pipeline->requesting_matrimony_profile_id,
        ], true)) {
            throw new InvalidArgumentException('Ledger profile must match the pipeline context.');
        }

        return $pipeline;
    }

    private function optionalCollaboration(mixed $collaborationId, SuchakAccount $account, MatrimonyProfile $profile): ?SuchakCollaborationRequest
    {
        if ($collaborationId === null || $collaborationId === '') {
            return null;
        }

        $collaboration = SuchakCollaborationRequest::query()->findOrFail((int) $collaborationId);
        if (! in_array((int) $account->id, [
            (int) $collaboration->requesting_suchak_account_id,
            (int) $collaboration->target_suchak_account_id,
        ], true)) {
            throw new InvalidArgumentException('CRM collaboration context must include the Suchak account.');
        }

        if (! in_array((int) $profile->id, [
            (int) $collaboration->requesting_matrimony_profile_id,
            (int) $collaboration->target_matrimony_profile_id,
        ], true)) {
            throw new InvalidArgumentException('CRM profile must match the collaboration context.');
        }

        return $collaboration;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function allowedValue(mixed $value, array $allowed, string $message): string
    {
        $normalized = trim((string) ($value ?? ''));
        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function requiredLimitedText(mixed $value, int $limit, string $message): string
    {
        $normalized = $this->nullableLimitedText($value, $limit);
        if ($normalized === null) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
    }

    private function nullableLimitedText(mixed $value, int $limit): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === ''
            ? null
            : Str::limit($normalized, $limit, '');
    }

    private function assertNoPrivateContactText(string $value): void
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1
            || preg_match('/(?<!\d)(?:\+?91[\s-]*)?[6-9]\d(?:[\s-]?\d){8}(?!\d)/', $value) === 1) {
            throw new InvalidArgumentException('Suchak CRM records must not store private contact details.');
        }
    }

    private function nullableAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('Ledger amount must be a non-negative number.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper(trim((string) ($value ?? 'INR')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Ledger currency must be a three-letter code.');
        }

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordActivity(
        string $actionType,
        string $targetType,
        int $targetId,
        SuchakAccount $account,
        User $actor,
        MatrimonyProfile $profile,
        ?string $ipAddress,
        ?string $userAgent,
        array $metadata,
    ): void {
        $this->activityLogger->record([
            'suchak_account_id' => $account->id,
            'actor_user_id' => $actor->id,
            'actor_type' => SuchakActivityLog::ACTOR_SUCHAK,
            'action_type' => $actionType,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'matrimony_profile_id' => $profile->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 512, ''),
            'metadata_json' => array_merge($metadata, [
                'suchak_account_id' => $account->id,
                'matrimony_profile_id' => $profile->id,
            ]),
        ]);
    }
}
