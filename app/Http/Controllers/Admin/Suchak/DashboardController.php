<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakAccount;
use App\Models\SuchakActivityLog;
use App\Models\SuchakBiodataExport;
use App\Models\SuchakConsent;
use App\Models\SuchakConsentEvent;
use App\Models\SuchakDispute;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakQrToken;
use App\Models\SuchakSubscription;
use App\Models\SuchakVerificationRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $now = now();
        $recentActivity = SuchakActivityLog::query()
            ->with('suchakAccount')
            ->latest('occurred_at')
            ->limit(8)
            ->get();

        return view('admin.suchak.dashboard', [
            'stats' => $this->accountStats($now),
            'approvalsSummary' => $this->approvalsSummary($now),
            'consentHealth' => $this->consentHealth($now),
            'subscriptionHealth' => $this->subscriptionHealth($now),
            'customerPaymentHealth' => $this->customerPaymentHealth($now),
            'disputeSummary' => $this->disputeSummary($now),
            'pdfQrActivity' => $this->pdfQrActivity($now),
            'recentAccounts' => SuchakAccount::query()
                ->with('user')
                ->latest()
                ->limit(8)
                ->get(),
            'recentActivity' => $recentActivity,
            'evidenceTimeline' => $this->evidenceTimeline(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function accountStats(Carbon $now): array
    {
        return [
            'pending' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_PENDING)->count(),
            'verified' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)->count(),
            'rejected' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_REJECTED)->count(),
            'suspended' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_SUSPENDED)->count(),
            'archived' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_ARCHIVED)->count(),
            'public_active' => SuchakAccount::query()->where('public_status', SuchakAccount::PUBLIC_ACTIVE)->count(),
            'public_hidden' => SuchakAccount::query()->where('public_status', SuchakAccount::PUBLIC_HIDDEN)->count(),
            'public_inactive' => SuchakAccount::query()->where('public_status', SuchakAccount::PUBLIC_INACTIVE)->count(),
            'registered_last_7_days' => SuchakAccount::query()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function approvalsSummary(Carbon $now): array
    {
        return [
            'accounts_pending' => SuchakAccount::query()->where('verification_status', SuchakAccount::VERIFICATION_PENDING)->count(),
            'accounts_approved_last_7_days' => SuchakAccount::query()->where('verified_at', '>=', $now->copy()->subDays(7))->count(),
            'accounts_rejected_last_7_days' => SuchakAccount::query()->where('rejected_at', '>=', $now->copy()->subDays(7))->count(),
            'records_pending' => SuchakVerificationRecord::query()->where('admin_status', SuchakVerificationRecord::STATUS_PENDING)->count(),
            'photo_records_pending' => SuchakVerificationRecord::query()
                ->whereIn('verification_type', [
                    SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
                    SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
                    SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
                ])
                ->where('admin_status', SuchakVerificationRecord::STATUS_PENDING)
                ->whereNotNull('document_path')
                ->where('document_path', '!=', '')
                ->count(),
            'photo_queue_counts' => PhotoReviewController::queueCounts(),
            'records_approved_last_7_days' => SuchakVerificationRecord::query()->where('verified_at', '>=', $now->copy()->subDays(7))->count(),
            'records_rejected_last_7_days' => SuchakVerificationRecord::query()->where('rejected_at', '>=', $now->copy()->subDays(7))->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function consentHealth(Carbon $now): array
    {
        return [
            'pending_action' => SuchakConsent::query()->whereIn('consent_status', SuchakConsent::PENDING_ACTION_STATUSES)->count(),
            'accepted_valid' => SuchakConsent::query()
                ->where('consent_status', SuchakConsent::STATUS_ACCEPTED)
                ->whereNull('revoked_at')
                ->where(function ($query) use ($now): void {
                    $query->whereNull('valid_until')
                        ->orWhere('valid_until', '>', $now);
                })
                ->count(),
            'expired' => SuchakConsent::query()
                ->where(function ($query) use ($now): void {
                    $query->where('consent_status', SuchakConsent::STATUS_EXPIRED)
                        ->orWhere(function ($dates) use ($now): void {
                            $dates->whereNotNull('valid_until')
                                ->where('valid_until', '<=', $now);
                        });
                })
                ->count(),
            'revoked' => SuchakConsent::query()->where('consent_status', SuchakConsent::STATUS_REVOKED)->count(),
            'expiring_soon' => SuchakConsent::query()
                ->where('consent_status', SuchakConsent::STATUS_ACCEPTED)
                ->whereNull('revoked_at')
                ->whereBetween('valid_until', [$now, $now->copy()->addDays(30)])
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function subscriptionHealth(Carbon $now): array
    {
        return [
            'active' => SuchakSubscription::query()->activeAt($now)->count(),
            'pending_admin_review' => SuchakSubscription::query()->where('status', SuchakSubscription::STATUS_PENDING_ADMIN_REVIEW)->count(),
            'cancelled' => SuchakSubscription::query()->where('status', SuchakSubscription::STATUS_CANCELLED)->count(),
            'expired' => SuchakSubscription::query()->where('status', SuchakSubscription::STATUS_EXPIRED)->count(),
            'ending_soon' => SuchakSubscription::query()
                ->where('status', SuchakSubscription::STATUS_ACTIVE)
                ->whereBetween('ends_at', [$now, $now->copy()->addDays(14)])
                ->count(),
            'verified_without_active_plan' => SuchakAccount::query()
                ->where('verification_status', SuchakAccount::VERIFICATION_VERIFIED)
                ->whereDoesntHave('suchakSubscriptions', fn ($query) => $query->activeAt($now))
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function customerPaymentHealth(Carbon $now): array
    {
        return [
            'expected' => SuchakLedgerEntry::query()->where('status', SuchakLedgerEntry::STATUS_EXPECTED)->count(),
            'due' => SuchakLedgerEntry::query()->where('status', SuchakLedgerEntry::STATUS_DUE)->count(),
            'paid' => SuchakLedgerEntry::query()->where('status', SuchakLedgerEntry::STATUS_PAID)->count(),
            'waived' => SuchakLedgerEntry::query()->where('status', SuchakLedgerEntry::STATUS_WAIVED)->count(),
            'cancelled' => SuchakLedgerEntry::query()->where('status', SuchakLedgerEntry::STATUS_CANCELLED)->count(),
            'overdue' => SuchakLedgerEntry::query()
                ->whereIn('status', [SuchakLedgerEntry::STATUS_EXPECTED, SuchakLedgerEntry::STATUS_DUE])
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $now->toDateString())
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function disputeSummary(Carbon $now): array
    {
        return [
            'open' => SuchakDispute::query()->where('status', SuchakDispute::STATUS_OPEN)->count(),
            'under_review' => SuchakDispute::query()->where('status', SuchakDispute::STATUS_UNDER_REVIEW)->count(),
            'resolved_last_7_days' => SuchakDispute::query()->where('resolved_at', '>=', $now->copy()->subDays(7))->count(),
            'abuse_reports' => SuchakDispute::query()->where('dispute_type', SuchakDispute::TYPE_ABUSE_REPORT)->count(),
            'high_priority_open' => SuchakDispute::query()
                ->whereIn('status', [SuchakDispute::STATUS_OPEN, SuchakDispute::STATUS_UNDER_REVIEW])
                ->whereIn('priority', [SuchakDispute::PRIORITY_HIGH, SuchakDispute::PRIORITY_URGENT])
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pdfQrActivity(Carbon $now): array
    {
        return [
            'pdf_generated_last_7_days' => SuchakBiodataExport::query()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
            'pdf_downloaded_last_7_days' => SuchakBiodataExport::query()->where('downloaded_at', '>=', $now->copy()->subDays(7))->count(),
            'pdf_shared_last_7_days' => SuchakBiodataExport::query()->where('shared_at', '>=', $now->copy()->subDays(7))->count(),
            'qr_active' => SuchakQrToken::query()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->count(),
            'qr_expiring_soon' => SuchakQrToken::query()
                ->whereNull('revoked_at')
                ->whereBetween('expires_at', [$now, $now->copy()->addDays(7)])
                ->count(),
            'qr_revoked' => SuchakQrToken::query()->whereNotNull('revoked_at')->count(),
            'qr_scans_total' => (int) SuchakQrToken::query()->sum('scan_count'),
            'qr_scanned_last_7_days' => SuchakQrToken::query()->where('last_scanned_at', '>=', $now->copy()->subDays(7))->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function evidenceTimeline(): Collection
    {
        $items = collect();

        SuchakActivityLog::query()
            ->with('suchakAccount')
            ->latest('occurred_at')
            ->limit(10)
            ->get()
            ->each(fn (SuchakActivityLog $activity) => $items->push($this->timelineItem(
                'Activity',
                $this->displayLabel($activity->action_type),
                $activity->suchakAccount,
                $activity->occurred_at,
                $activity->actor_type,
                $this->sourceLabel($activity->target_type, $activity->target_id, 'activity', $activity->id),
            )));

        SuchakVerificationRecord::query()
            ->with('suchakAccount')
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->each(fn (SuchakVerificationRecord $record) => $items->push($this->timelineItem(
                'Verification',
                $this->displayLabel($record->verification_type).' record',
                $record->suchakAccount,
                $record->verified_at ?? $record->rejected_at ?? $record->created_at,
                $record->admin_status,
                'Verification Record #'.$record->id,
            )));

        SuchakConsentEvent::query()
            ->with('consent.suchakAccount')
            ->latest('created_at')
            ->limit(8)
            ->get()
            ->each(fn (SuchakConsentEvent $event) => $items->push($this->timelineItem(
                'Consent',
                $this->displayLabel($event->event_type),
                $event->consent?->suchakAccount,
                $event->created_at,
                $event->actor_type,
                'Consent #'.$event->consent_id,
            )));

        SuchakDispute::query()
            ->with('suchakAccount')
            ->latest('opened_at')
            ->limit(6)
            ->get()
            ->each(fn (SuchakDispute $dispute) => $items->push($this->timelineItem(
                'Risk',
                $this->displayLabel($dispute->dispute_type),
                $dispute->suchakAccount,
                $dispute->opened_at,
                $this->displayLabel($dispute->priority).' / '.$this->displayLabel($dispute->status),
                'Dispute #'.$dispute->id,
            )));

        SuchakBiodataExport::query()
            ->with('suchakAccount')
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->each(fn (SuchakBiodataExport $export) => $items->push($this->timelineItem(
                'PDF',
                $this->displayLabel($export->export_type).' generated',
                $export->suchakAccount,
                $export->created_at,
                $export->shared_at ? 'shared' : ($export->downloaded_at ? 'downloaded' : 'generated'),
                'Export #'.$export->id,
            )));

        SuchakQrToken::query()
            ->with('suchakAccount')
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->each(fn (SuchakQrToken $token) => $items->push($this->timelineItem(
                'QR',
                $token->revoked_at ? 'QR token revoked' : 'QR token active',
                $token->suchakAccount,
                $token->revoked_at ?? $token->last_scanned_at ?? $token->created_at,
                'scans '.$token->scan_count,
                'QR Token #'.$token->id,
            )));

        return $items
            ->sortByDesc(fn (array $item) => $item['occurred_at']?->getTimestamp() ?? 0)
            ->take(24)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineItem(
        string $type,
        string $label,
        ?SuchakAccount $account,
        ?Carbon $occurredAt,
        ?string $status,
        string $sourceLabel
    ): array {
        return [
            'type' => $type,
            'label' => $label,
            'account_name' => $account?->suchak_name ?: 'Unknown Suchak',
            'account_url' => $account ? route('admin.suchak.accounts.show', $account) : null,
            'occurred_at' => $occurredAt,
            'status' => $status ? $this->displayLabel($status) : '-',
            'source_label' => $sourceLabel,
        ];
    }

    private function sourceLabel(?string $targetType, ?int $targetId, string $fallbackType, int $fallbackId): string
    {
        if ($targetType && $targetId) {
            return $this->displayLabel($targetType).' #'.$targetId;
        }

        return $fallbackType.' #'.$fallbackId;
    }

    private function displayLabel(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
