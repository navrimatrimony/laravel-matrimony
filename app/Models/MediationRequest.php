<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Mediator {@see ContactRequest} rows (type=mediator). Stored in contact_requests.
 */
class MediationRequest extends ContactRequest
{
    public const TYPE_MEDIATOR = ContactRequest::TYPE_MEDIATOR;

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
    }
}
