<?php

namespace App\Support\Suchak;

use App\Models\SuchakAccount;
use App\Models\SuchakVerificationRecord;
use Illuminate\Support\Collection;

class SuchakOnboardingPresenter
{
    /**
     * @param  Collection<int, SuchakVerificationRecord>|null  $verificationRecords
     * @return array<string, mixed>
     */
    public function forAccount(SuchakAccount $account, ?Collection $verificationRecords = null): array
    {
        $account->loadMissing('user');
        $verificationRecords ??= $account->verificationRecords()->latest('id')->get();

        $mobileVerified = $account->user?->mobile_verified_at !== null;
        $verificationStatus = (string) $account->verification_status;
        $isVerified = $verificationStatus === SuchakAccount::VERIFICATION_VERIFIED;
        $isBlocked = in_array($verificationStatus, [
            SuchakAccount::VERIFICATION_REJECTED,
            SuchakAccount::VERIFICATION_SUSPENDED,
            SuchakAccount::VERIFICATION_ARCHIVED,
        ], true);

        $documentRows = $this->documentRows($account, $verificationRecords);
        $requiredDocumentRows = $documentRows->filter(fn (array $row): bool => $row['required']);
        $requiredDocumentsUploaded = $requiredDocumentRows->every(fn (array $row): bool => $row['uploaded']);
        $requiredDocumentsApproved = $requiredDocumentRows->every(
            fn (array $row): bool => $row['uploaded'] && $row['status'] === SuchakVerificationRecord::STATUS_APPROVED,
        );
        $hasRejectedRequiredDocument = $requiredDocumentRows->contains(
            fn (array $row): bool => $row['status'] === SuchakVerificationRecord::STATUS_REJECTED,
        );
        $hasManualAdminApproval = $verificationRecords->contains(
            fn (SuchakVerificationRecord $record): bool => $record->verification_type === SuchakVerificationRecord::TYPE_OTHER
                && $record->admin_status === SuchakVerificationRecord::STATUS_APPROVED
                && $record->admin_user_id !== null,
        );
        $isSystemAutoApproved = $isVerified && ! $hasManualAdminApproval;

        $documentState = match (true) {
            $hasRejectedRequiredDocument => 'blocked',
            $requiredDocumentsApproved => 'complete',
            $requiredDocumentsUploaded => 'submitted',
            $mobileVerified => 'current',
            default => 'upcoming',
        };
        $adminReviewState = match (true) {
            ! $mobileVerified => 'upcoming',
            $isBlocked => 'blocked',
            $hasManualAdminApproval => 'complete',
            $isSystemAutoApproved => 'submitted',
            default => 'in_progress',
        };

        $state = [
            'registration' => 'complete',
            'otp' => $mobileVerified ? 'complete' : 'current',
            'admin_review' => $adminReviewState,
            'documents' => $documentState,
        ];

        $currentKey = $this->currentStepKey($state, $mobileVerified, $requiredDocumentsUploaded);
        $steps = collect(array_keys($state))
            ->map(function (string $key, int $index) use ($account, $state, $mobileVerified, $requiredDocumentsUploaded): array {
                return [
                    'key' => $key,
                    'index' => $index + 1,
                    'state' => $state[$key],
                    'label' => __('suchak.status.steps.'.$key.'.label'),
                    'detail' => __('suchak.status.steps.'.$key.'.detail'),
                    'body' => $this->stepBody($key, $state[$key], $requiredDocumentsUploaded),
                    'action_label' => $this->stepActionLabel($key, $mobileVerified, $requiredDocumentsUploaded),
                    'action_url' => $this->stepActionUrl($key, $mobileVerified),
                ];
            })
            ->values();

        return [
            'mobile_verified' => $mobileVerified,
            'is_verified' => $isVerified,
            'is_blocked' => $isBlocked,
            'current_step_key' => $currentKey,
            'current_step' => $steps->firstWhere('key', $currentKey) ?? $steps->first(),
            'steps' => $steps,
            'document_rows' => $documentRows,
            'required_documents_uploaded' => $requiredDocumentsUploaded,
            'required_documents_approved' => $requiredDocumentsApproved,
            'manual_admin_review_complete' => $hasManualAdminApproval,
            'admin_review_pending' => $isSystemAutoApproved || ($requiredDocumentsUploaded && ! $requiredDocumentsApproved),
            'uploaded_document_count' => $documentRows->filter(fn (array $row): bool => $row['uploaded'])->count(),
            'message_key' => $this->messageKey(
                $mobileVerified,
                $isVerified,
                $isBlocked,
                $requiredDocumentsUploaded,
                $requiredDocumentsApproved,
                $hasManualAdminApproval,
            ),
        ];
    }

    /**
     * @param  Collection<int, SuchakVerificationRecord>  $verificationRecords
     * @return Collection<int, array<string, mixed>>
     */
    private function documentRows(SuchakAccount $account, Collection $verificationRecords): Collection
    {
        $recordsByType = $verificationRecords->keyBy('verification_type');
        $types = [
            SuchakVerificationRecord::TYPE_IDENTITY => true,
            SuchakVerificationRecord::TYPE_OFFICE => in_array($account->business_type, [
                SuchakAccount::BUSINESS_TYPE_BUREAU,
                SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
            ], true),
            SuchakVerificationRecord::TYPE_BUSINESS => $account->business_type === SuchakAccount::BUSINESS_TYPE_ORGANIZATION,
        ];

        return collect($types)->map(function (bool $required, string $type) use ($recordsByType): array {
            $record = $recordsByType->get($type);
            $status = $record?->admin_status ?: SuchakVerificationRecord::STATUS_PENDING;
            $statusLabelKey = $record?->document_path && $status === SuchakVerificationRecord::STATUS_PENDING
                ? 'pending_review'
                : $status;

            return [
                'type' => $type,
                'required' => $required,
                'label' => __('suchak.status.document_types.'.$type),
                'help' => __('suchak.status.document_help.'.$type),
                'uploaded' => (bool) ($record?->document_path),
                'status' => $status,
                'status_label' => __('suchak.labels.common.'.$statusLabelKey),
                'remarks' => $record?->remarks_mr ?: $record?->remarks,
            ];
        })->values();
    }

    /**
     * @param  array<string, string>  $state
     */
    private function currentStepKey(array $state, bool $mobileVerified, bool $requiredDocumentsUploaded): string
    {
        if (! $mobileVerified) {
            return 'otp';
        }

        if (! $requiredDocumentsUploaded || $state['documents'] === 'blocked') {
            return 'documents';
        }

        foreach ($state as $key => $value) {
            if (in_array($value, ['blocked', 'current', 'in_progress', 'submitted'], true)) {
                return $key;
            }
        }

        return 'documents';
    }

    private function messageKey(
        bool $mobileVerified,
        bool $isVerified,
        bool $isBlocked,
        bool $requiredDocumentsUploaded,
        bool $requiredDocumentsApproved,
        bool $hasManualAdminApproval,
    ): string {
        return match (true) {
            ! $mobileVerified => 'otp_pending',
            $isBlocked => 'blocked',
            ! $requiredDocumentsUploaded => 'kyc_pending',
            $isVerified && (! $requiredDocumentsApproved || ! $hasManualAdminApproval) => 'work_allowed_review_pending',
            ! $isVerified => 'review_pending',
            default => 'ready',
        };
    }

    private function stepBody(string $key, string $state, bool $requiredDocumentsUploaded): string
    {
        if ($key === 'documents' && $requiredDocumentsUploaded) {
            return __('suchak.status.steps.documents.submitted_body');
        }

        if ($key === 'admin_review' && $state === 'submitted') {
            return __('suchak.status.steps.admin_review.submitted_body');
        }

        return __('suchak.status.steps.'.$key.'.body');
    }

    private function stepActionLabel(string $key, bool $mobileVerified, bool $requiredDocumentsUploaded): ?string
    {
        return match ($key) {
            'otp' => $mobileVerified ? null : __('suchak.status.step_actions.verify_otp'),
            'documents' => $mobileVerified
                ? ($requiredDocumentsUploaded ? __('suchak.status.step_actions.view_documents') : __('suchak.status.step_actions.upload_documents'))
                : null,
            'admin_review' => $mobileVerified ? __('suchak.status.step_actions.view_status') : null,
            default => null,
        };
    }

    private function stepActionUrl(string $key, bool $mobileVerified): ?string
    {
        return match ($key) {
            'otp' => $mobileVerified ? null : route('suchak.register.verify'),
            'documents' => $mobileVerified ? route('suchak.register.status', absolute: false).'#kyc-documents' : null,
            'admin_review' => $mobileVerified ? route('suchak.register.status', absolute: false).'#kyc-documents' : null,
            default => null,
        };
    }
}
