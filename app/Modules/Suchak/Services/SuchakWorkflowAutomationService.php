<?php

namespace App\Modules\Suchak\Services;

use App\Models\MatrimonyProfile;
use App\Models\SuchakAccount;
use App\Models\SuchakConsent;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use App\Models\SuchakVisitConfirmation;
use App\Models\SuchakWorkflowReminder;
use App\Models\SuchakWorkflowTimelineEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SuchakWorkflowAutomationService
{
    private const PER_SOURCE_LIMIT = 200;

    public function __construct(
        private readonly SuchakCandidateMaskingService $maskingService,
    ) {
    }

    public function generateDueReminders(?SuchakAccount $account = null, ?Carbon $at = null): Collection
    {
        $at ??= now();

        return DB::transaction(function () use ($account, $at): Collection {
            return collect()
                ->merge($this->followUpPayloads($account, $at))
                ->merge($this->ledgerPaymentPayloads($account, $at))
                ->merge($this->paymentRequestPayloads($account, $at))
                ->merge($this->consentPayloads($account, $at))
                ->merge($this->meetingPayloads($account, $at))
                ->map(fn (array $payload): SuchakWorkflowReminder => $this->persistReminder($payload, $at))
                ->values();
        });
    }

    public function recentReminders(SuchakAccount $account, int $limit = 8): Collection
    {
        return SuchakWorkflowReminder::query()
            ->where('suchak_account_id', $account->id)
            ->latest('last_generated_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function recentTimeline(SuchakAccount $account, int $limit = 8): Collection
    {
        return SuchakWorkflowTimelineEvent::query()
            ->where('suchak_account_id', $account->id)
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, array{label: string, channel: string, provider_status: string, body: string}>
     */
    public function whatsappTemplateCatalog(): array
    {
        return [
            'follow_up_due_v1' => [
                'label' => 'Follow-up reminder',
                'channel' => SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY,
                'provider_status' => SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS,
                'body' => 'Reminder: follow up for {candidate_reference}. Due {due_at}. Keep the next step inside platform records.',
            ],
            'payment_due_v1' => [
                'label' => 'Payment reminder',
                'channel' => SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY,
                'provider_status' => SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS,
                'body' => 'Payment follow-up for {candidate_reference}. Due {due_at}. Share only the platform payment request or verified receipt path.',
            ],
            'consent_due_v1' => [
                'label' => 'Consent reminder',
                'channel' => SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY,
                'provider_status' => SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS,
                'body' => 'Consent reminder for {candidate_reference}. Action is due by {due_at}. Use the platform consent link only.',
            ],
            'meeting_due_v1' => [
                'label' => 'Meeting reminder',
                'channel' => SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY,
                'provider_status' => SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS,
                'body' => 'Meeting reminder for {candidate_reference}. Scheduled for {due_at}. Confirm attendance and update the platform timeline.',
            ],
        ];
    }

    private function followUpPayloads(?SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakProfileNote::query()
            ->with('matrimonyProfile')
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $at)
            ->orderBy('follow_up_at')
            ->orderBy('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (SuchakProfileNote $note): array => $this->payload(
                (int) $note->suchak_account_id,
                'suchak_profile_note',
                (int) $note->id,
                SuchakWorkflowReminder::TYPE_FOLLOW_UP,
                'follow_up_due_v1',
                $note->follow_up_at,
                $note->matrimony_profile_id,
                null,
                $note->matrimonyProfile,
                'Follow-up reminder generated',
                'CRM follow-up date is due.',
            ));
    }

    private function ledgerPaymentPayloads(?SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakLedgerEntry::query()
            ->with('matrimonyProfile')
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereIn('status', [
                SuchakLedgerEntry::STATUS_DUE,
                SuchakLedgerEntry::STATUS_EXPECTED,
            ])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $at->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (SuchakLedgerEntry $entry): array => $this->payload(
                (int) $entry->suchak_account_id,
                'suchak_ledger_entry',
                (int) $entry->id,
                SuchakWorkflowReminder::TYPE_PAYMENT,
                'payment_due_v1',
                $entry->due_date?->copy()->startOfDay(),
                $entry->matrimony_profile_id,
                null,
                $entry->matrimonyProfile,
                'Payment reminder generated',
                'Ledger due date is open.',
            ));
    }

    private function paymentRequestPayloads(?SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakPaymentRequest::query()
            ->with('customerContext.candidateProfile')
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereIn('payment_status', [
                SuchakPaymentRequest::STATUS_SENT,
                SuchakPaymentRequest::STATUS_OPENED,
                SuchakPaymentRequest::STATUS_PENDING,
                SuchakPaymentRequest::STATUS_PARTIALLY_PAID,
                SuchakPaymentRequest::STATUS_OVERDUE,
            ])
            ->where(function (Builder $query) use ($at): void {
                $query->where('payment_status', SuchakPaymentRequest::STATUS_OVERDUE)
                    ->orWhere(function (Builder $query) use ($at): void {
                        $query->whereNotNull('expires_at')
                            ->where('expires_at', '<=', $at->copy()->addDays(3));
                    });
            })
            ->orderByRaw('expires_at IS NULL')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (SuchakPaymentRequest $request): array => $this->payload(
                (int) $request->suchak_account_id,
                'suchak_payment_request',
                (int) $request->id,
                SuchakWorkflowReminder::TYPE_PAYMENT,
                'payment_due_v1',
                $request->expires_at ?? $at,
                $request->customerContext?->candidate_matrimony_profile_id,
                $request->customer_context_id,
                $request->customerContext?->candidateProfile,
                'Payment reminder generated',
                'Payment request is open or overdue.',
            ));
    }

    private function consentPayloads(?SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakConsent::query()
            ->with(['matrimonyProfile', 'representation'])
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->where(function (Builder $query) use ($at): void {
                $query->where(function (Builder $query) use ($at): void {
                    $query->whereIn('consent_status', SuchakConsent::PENDING_ACTION_STATUSES)
                        ->whereNotNull('token_expires_at')
                        ->where('token_expires_at', '<=', $at->copy()->addDays(2));
                })->orWhere(function (Builder $query) use ($at): void {
                    $query->where('consent_status', SuchakConsent::STATUS_ACCEPTED)
                        ->whereNull('revoked_at')
                        ->whereNotNull('valid_until')
                        ->whereBetween('valid_until', [$at, $at->copy()->addDays(7)]);
                });
            })
            ->orderByRaw('COALESCE(token_expires_at, valid_until)')
            ->orderBy('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (SuchakConsent $consent): array => $this->payload(
                (int) $consent->suchak_account_id,
                'suchak_consent',
                (int) $consent->id,
                SuchakWorkflowReminder::TYPE_CONSENT,
                'consent_due_v1',
                $consent->token_expires_at ?? $consent->valid_until ?? $at,
                $consent->matrimony_profile_id,
                null,
                $consent->matrimonyProfile,
                'Consent reminder generated',
                'Consent action or renewal is due.',
                $consent->representation,
            ));
    }

    private function meetingPayloads(?SuchakAccount $account, Carbon $at): Collection
    {
        return SuchakVisitConfirmation::query()
            ->with(['targetMatrimonyProfile', 'representation'])
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->where('visit_status', SuchakVisitConfirmation::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $at->copy()->addDay())
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (SuchakVisitConfirmation $visit): array => $this->payload(
                (int) $visit->suchak_account_id,
                'suchak_visit_confirmation',
                (int) $visit->id,
                SuchakWorkflowReminder::TYPE_MEETING,
                'meeting_due_v1',
                $visit->scheduled_for,
                $visit->target_matrimony_profile_id,
                $visit->customer_context_id,
                $visit->targetMatrimonyProfile,
                'Meeting reminder generated',
                'Scheduled visit or meeting is due.',
                $visit->representation,
            ));
    }

    private function payload(
        int $accountId,
        string $sourceType,
        int $sourceId,
        string $reminderType,
        string $templateKey,
        ?Carbon $dueAt,
        ?int $profileId,
        ?int $customerContextId,
        ?MatrimonyProfile $profile,
        string $eventTitle,
        string $eventSummary,
        ?SuchakProfileRepresentation $representation = null,
    ): array {
        $dueAt ??= now();

        return [
            'suchak_account_id' => $accountId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reminder_type' => $reminderType,
            'template_key' => $templateKey,
            'due_at' => $dueAt,
            'matrimony_profile_id' => $profileId,
            'customer_context_id' => $customerContextId,
            'candidate_reference' => $this->candidateReference($profile, $representation),
            'event_title' => $eventTitle,
            'event_summary' => $eventSummary,
        ];
    }

    private function persistReminder(array $payload, Carbon $at): SuchakWorkflowReminder
    {
        $messageCopy = $this->renderTemplate($payload);
        $this->assertTemplateIsSafe($messageCopy);

        $reminderKey = $this->reminderKey($payload, $at);
        $attributes = [
            'suchak_account_id' => $payload['suchak_account_id'],
            'customer_context_id' => $payload['customer_context_id'],
            'matrimony_profile_id' => $payload['matrimony_profile_id'],
            'source_type' => $payload['source_type'],
            'source_id' => $payload['source_id'],
            'reminder_type' => $payload['reminder_type'],
            'reminder_key' => $reminderKey,
            'template_key' => $payload['template_key'],
            'channel' => SuchakWorkflowReminder::CHANNEL_WHATSAPP_COPY,
            'provider_status' => SuchakWorkflowReminder::PROVIDER_PENDING_CREDENTIALS,
            'due_at' => $payload['due_at'],
            'generated_for_date' => $at->toDateString(),
            'last_generated_at' => $at,
            'message_copy' => $messageCopy,
            'metadata_json' => [
                'candidate_reference' => $payload['candidate_reference'],
                'provider_credentials' => 'pending',
                'source_type' => $payload['source_type'],
                'source_id' => $payload['source_id'],
            ],
        ];

        /** @var SuchakWorkflowReminder|null $existing */
        $existing = SuchakWorkflowReminder::query()
            ->where('reminder_key', $reminderKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();

            return $existing->fresh();
        }

        $reminder = SuchakWorkflowReminder::query()->create(array_merge($attributes, [
            'reminder_status' => SuchakWorkflowReminder::STATUS_PENDING,
        ]));

        $this->recordTimeline($reminder, $payload, $at);

        return $reminder->fresh();
    }

    private function recordTimeline(SuchakWorkflowReminder $reminder, array $payload, Carbon $at): void
    {
        SuchakWorkflowTimelineEvent::query()->create([
            'suchak_account_id' => $reminder->suchak_account_id,
            'workflow_reminder_id' => $reminder->id,
            'customer_context_id' => $reminder->customer_context_id,
            'matrimony_profile_id' => $reminder->matrimony_profile_id,
            'event_type' => SuchakWorkflowTimelineEvent::EVENT_REMINDER_GENERATED,
            'source_type' => $reminder->source_type,
            'source_id' => $reminder->source_id,
            'actor_type' => SuchakWorkflowTimelineEvent::ACTOR_SYSTEM,
            'actor_user_id' => null,
            'event_title' => $payload['event_title'],
            'event_summary' => $payload['event_summary'],
            'metadata_json' => [
                'reminder_type' => $reminder->reminder_type,
                'template_key' => $reminder->template_key,
                'channel' => $reminder->channel,
                'provider_status' => $reminder->provider_status,
            ],
            'occurred_at' => $at,
            'created_at' => $at,
        ]);
    }

    private function renderTemplate(array $payload): string
    {
        $template = $this->whatsappTemplateCatalog()[$payload['template_key']] ?? null;

        if ($template === null) {
            throw new InvalidArgumentException('Suchak workflow template key is invalid.');
        }

        return strtr($template['body'], [
            '{candidate_reference}' => $payload['candidate_reference'] ?? 'masked-candidate',
            '{due_at}' => $payload['due_at']->format('Y-m-d H:i'),
        ]);
    }

    private function assertTemplateIsSafe(string $messageCopy): void
    {
        if (preg_match('/\b\d{10,}\b/', $messageCopy) === 1
            || preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $messageCopy) === 1
            || preg_match('/\b[A-Z0-9._%+\-]{2,}@[A-Z]{2,}\b/i', $messageCopy) === 1) {
            throw new InvalidArgumentException('Suchak workflow templates must not include protected contact data.');
        }
    }

    private function reminderKey(array $payload, Carbon $at): string
    {
        return hash('sha256', implode('|', [
            $payload['suchak_account_id'],
            $payload['reminder_type'],
            $payload['source_type'],
            $payload['source_id'],
            $at->toDateString(),
        ]));
    }

    private function candidateReference(?MatrimonyProfile $profile, ?SuchakProfileRepresentation $representation = null): string
    {
        if ($profile === null) {
            return 'masked-candidate';
        }

        if ($representation !== null) {
            $summary = $this->maskingService->maskedSummary($profile, $representation);

            return $summary['candidate_reference'] ?? 'masked-candidate';
        }

        return 'masked-profile-'.substr(hash('sha256', 'profile:'.$profile->id), 0, 12);
    }
}
