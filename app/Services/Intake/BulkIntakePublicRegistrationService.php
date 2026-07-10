<?php

namespace App\Services\Intake;

use App\Models\BiodataIntake;
use App\Models\BulkIntakeBatchItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BulkIntakePublicRegistrationService
{
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly BulkIntakeCandidateDisplayService $candidateDisplayService,
        private readonly BulkIntakeWhatsAppConsentService $whatsappConsentService,
        private readonly IntakeHumanReviewSnapshotService $reviewSnapshotService,
        private readonly BulkIntakeRegistrationFormBridgeService $formBridge,
    ) {}

    public function publicUrl(BulkIntakeBatchItem $item): string
    {
        return route('bulk-intake.register.show', [
            'token' => $this->ensureToken($item),
        ]);
    }

    public function ensureToken(BulkIntakeBatchItem $item): string
    {
        $meta = is_array($item->item_meta_json) ? $item->item_meta_json : [];
        $registration = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
        $token = trim((string) ($registration['public_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $token = Str::random(self::TOKEN_BYTES);
        $registration['public_token'] = $token;
        $registration['public_token_created_at'] = now()->toISOString();
        $meta['registration'] = $registration;
        $item->forceFill(['item_meta_json' => $meta])->save();

        return $token;
    }

    public function itemForToken(string $token): ?BulkIntakeBatchItem
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return BulkIntakeBatchItem::query()
            ->where('item_meta_json->registration->public_token', $token)
            ->first();
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    public function accessGate(BulkIntakeBatchItem $item): array
    {
        if ($this->whatsappConsentService->consentStatus($item) !== BulkIntakeWhatsAppConsentService::STATUS_CONSENT_RECEIVED) {
            return ['allowed' => false, 'reason' => 'consent_not_received'];
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            return ['allowed' => false, 'reason' => 'missing_intake'];
        }

        if ($this->snapshotLocked($intake)) {
            return ['allowed' => false, 'reason' => 'snapshot_locked'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function formPayload(BulkIntakeBatchItem $item): array
    {
        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $snapshot = $this->sourceSnapshot($intake);
        $snapshot = $this->formBridge->mergeCandidatePreviewIntoSnapshot($snapshot, $intake);
        $snapshot = $this->formBridge->prepareDisplaySnapshot($snapshot, $intake);
        $core = is_array($snapshot['core'] ?? null) ? $snapshot['core'] : [];
        $candidate = $this->candidateDisplayService->candidateForItem($item);
        $motherTongueId = $this->formBridge->resolveMotherTongueId($intake, $core);
        if ($motherTongueId !== null) {
            $core['mother_tongue_id'] = $motherTongueId;
            $snapshot['core'] = $core;
        }

        $mobile = $candidate['mobile'] ?? $core['primary_contact_number'] ?? null;

        return array_merge(
            $this->formBridge->viewContext(
                $item,
                $snapshot,
                is_string($candidate['full_name'] ?? null) ? $candidate['full_name'] : null,
                is_string($mobile) ? $mobile : null,
            ),
            [
                'item' => $item,
                'intake' => $intake,
                'mother_tongue_id' => $motherTongueId,
            ],
        );
    }

    public function save(BulkIntakeBatchItem $item, Request $request): BiodataIntake
    {
        $gate = $this->accessGate($item);
        if (! $gate['allowed']) {
            throw ValidationException::withMessages([
                'registration' => $this->accessDeniedMessage((string) $gate['reason']),
            ]);
        }

        $intake = $this->intakeForItem($item);
        if (! $intake instanceof BiodataIntake) {
            throw ValidationException::withMessages([
                'registration' => 'Linked biodata intake is missing.',
            ]);
        }

        $snapshot = $this->formBridge->buildSnapshotFromRequest($request, $item, $intake);

        $intake = $this->reviewSnapshotService->saveReviewedSnapshot($intake, $snapshot, [
            'reviewed_by_user_id' => null,
            'review_actor_type' => IntakeHumanReviewSnapshotService::ACTOR_PROFILE_USER,
            'review_surface' => IntakeHumanReviewSnapshotService::SURFACE_WEBSITE,
            'approval_policy' => IntakeHumanReviewSnapshotService::POLICY_PHASE2C_PROFILE_USER_REVIEW_V1,
            'approval_status' => IntakeHumanReviewSnapshotService::STATUS_REVIEWED,
        ]);

        $this->markRegistrationComplete($item);

        return $intake->refresh();
    }

    private function markRegistrationComplete(BulkIntakeBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $locked = BulkIntakeBatchItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $meta = is_array($locked->item_meta_json) ? $locked->item_meta_json : [];
            $existing = is_array($meta['registration'] ?? null) ? $meta['registration'] : [];
            $meta['registration'] = array_merge($existing, [
                'status' => BulkIntakeRegistrationService::STATUS_REGISTRATION_COMPLETE,
                'completed_at' => now()->toISOString(),
                'completed_via' => 'public_web_form',
            ]);
            $locked->forceFill(['item_meta_json' => $meta])->save();
        });
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

    private function intakeForItem(BulkIntakeBatchItem $item): ?BiodataIntake
    {
        $item->loadMissing('biodataIntake');

        return $item->biodataIntake;
    }

    private function snapshotLocked(BiodataIntake $intake): bool
    {
        return (bool) $intake->approved_by_user || (bool) $intake->intake_locked;
    }

    public function accessDeniedMessage(string $reason): string
    {
        return match ($reason) {
            'consent_not_received' => 'Registration link is not active until WhatsApp consent is received.',
            'missing_intake' => 'Registration data is missing.',
            'snapshot_locked' => 'This registration can no longer be edited.',
            default => 'Registration link is not available.',
        };
    }
}
