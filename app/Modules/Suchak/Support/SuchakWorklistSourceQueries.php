<?php

namespace App\Modules\Suchak\Support;

use App\Models\SuchakAccount;
use App\Models\SuchakLedgerEntry;
use App\Models\SuchakPaymentRequest;
use App\Models\SuchakProfileNote;
use App\Models\SuchakProfileRepresentation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared read queries for dashboard worklist and workflow reminder generation.
 */
class SuchakWorklistSourceQueries
{
    public static function dueFollowUpNotes(?SuchakAccount $account, Carbon $at): Builder
    {
        return SuchakProfileNote::query()
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', $at)
            ->orderBy('follow_up_at')
            ->orderBy('id');
    }

    public static function dueLedgerEntries(?SuchakAccount $account, Carbon $at): Builder
    {
        return SuchakLedgerEntry::query()
            ->when($account, fn (Builder $query) => $query->where('suchak_account_id', $account->id))
            ->whereIn('status', [
                SuchakLedgerEntry::STATUS_DUE,
                SuchakLedgerEntry::STATUS_EXPECTED,
            ])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $at->toDateString())
            ->orderBy('due_date')
            ->orderBy('id');
    }

    public static function paymentRequestsNeedingFollowUp(?SuchakAccount $account, Carbon $at): Builder
    {
        return SuchakPaymentRequest::query()
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
            ->orderBy('id');
    }

    public static function expiringConsentedRepresentations(SuchakAccount $account, Carbon $at, int $withinDays = 7): Builder
    {
        return SuchakProfileRepresentation::query()
            ->where('suchak_account_id', $account->id)
            ->withValidConsent()
            ->whereNotNull('consent_valid_until')
            ->whereBetween('consent_valid_until', [$at, $at->copy()->addDays($withinDays)])
            ->orderBy('consent_valid_until')
            ->orderBy('id');
    }
}
