<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Mediator {@see ContactRequest} rows (type=mediator). Stored in contact_requests.
 */
class MediationRequest extends ContactRequest
{
    public const TYPE_MEDIATOR = ContactRequest::TYPE_MEDIATOR;

    public const CHANNEL_MANUAL_SIMULATION = 'manual_simulation';

    public const CHANNEL_IN_APP_ONLY = 'in_app_only';

    public const CHANNEL_WHATSAPP_API = 'whatsapp_api';

    public const CHANNEL_WHATSAPP_API_WITH_IN_APP_FALLBACK = 'whatsapp_api_with_in_app_fallback';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_QUEUED = 'queued';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_REMINDER_DUE = 'reminder_due';

    public const DELIVERY_REMINDER_SENT = 'reminder_sent';

    public const DELIVERY_RESPONDED = 'responded';

    public const DELIVERY_EXPIRED = 'expired';

    public const DELIVERY_FAILED = 'failed';

    public const DELIVERY_CANCELLED = 'cancelled';

    public const STATUS_PENDING = ContactRequest::STATUS_PENDING;

    public const STATUS_INTERESTED = ContactRequest::STATUS_INTERESTED;

    public const STATUS_NOT_INTERESTED = ContactRequest::STATUS_NOT_INTERESTED;

    public const STATUS_NEED_MORE_INFO = ContactRequest::STATUS_NEED_MORE_INFO;

    protected static function booted(): void
    {
        parent::booted();

        static::addGlobalScope('mediator_type', function (Builder $builder) {
            $builder->where(
                $builder->getModel()->getTable().'.type',
                ContactRequest::TYPE_MEDIATOR
            );
        });

        static::creating(function (MediationRequest $model) {
            $model->type = ContactRequest::TYPE_MEDIATOR;
            if ($model->reason === null || $model->reason === '') {
                $model->reason = ContactRequest::REASON_MEDIATOR;
            }
            if ($model->requested_scopes === null || $model->requested_scopes === []) {
                $model->requested_scopes = ['mediator'];
            }
        });

        static::saving(function (MediationRequest $model) {
            self::ensureMediatorProfileIds($model);
        });
    }

    /**
     * Mediator inbox, duplicate checks, and contact-reveal gating rely on non-null profile ids.
     */
    private static function ensureMediatorProfileIds(self $model): void
    {
        if ($model->receiver_profile_id === null && $model->subject_profile_id !== null) {
            $model->receiver_profile_id = $model->subject_profile_id;
        }

        if ($model->sender_profile_id === null && $model->sender_id !== null) {
            $pid = DB::table('matrimony_profiles')->where('user_id', $model->sender_id)->value('id');
            if ($pid) {
                $model->sender_profile_id = (int) $pid;
            }
        }

        if ($model->sender_profile_id === null || $model->receiver_profile_id === null) {
            throw new LogicException(__('mediation.missing_profile_ids_for_mediator'));
        }
    }

    public function hasResponded(): bool
    {
        return $this->responded_at !== null || in_array($this->status, [
            self::STATUS_INTERESTED,
            self::STATUS_NOT_INTERESTED,
            self::STATUS_NEED_MORE_INFO,
        ], true);
    }

    public function isDeliveryExpired(): bool
    {
        if ($this->hasResponded()) {
            return false;
        }

        return $this->expired_at !== null
            || $this->delivery_status === self::DELIVERY_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isReminderDue(): bool
    {
        if ($this->hasResponded() || $this->isDeliveryExpired()) {
            return false;
        }

        return $this->first_reminder_sent_at === null
            && $this->first_reminder_due_at !== null
            && $this->first_reminder_due_at->isPast();
    }

    public function effectiveDeliveryStatus(): string
    {
        if ($this->hasResponded()) {
            return self::DELIVERY_RESPONDED;
        }

        if ($this->isDeliveryExpired()) {
            return self::DELIVERY_EXPIRED;
        }

        if ($this->isReminderDue()) {
            return self::DELIVERY_REMINDER_DUE;
        }

        return $this->delivery_status ?: self::DELIVERY_PENDING;
    }
}
