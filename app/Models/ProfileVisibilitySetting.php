<?php

namespace App\Models;

use App\Support\ContactVisibilityStrictness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileVisibilitySetting extends Model
{
    protected $table = 'profile_visibility_settings';

    protected $fillable = [
        'profile_id',
        'visibility_scope',
        'show_photo_to',
        'show_contact_to',
        'hide_from_blocked_users',
        'contact_visibility_json',
    ];

    protected $casts = [
        'hide_from_blocked_users' => 'boolean',
        'contact_visibility_json' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MatrimonyProfile::class, 'profile_id');
    }

    /**
     * Defaults when no profile_visibility_settings row exists yet.
     *
     * @return array{
     *   rule: string,
     *   strictness: string,
     *   filters: array{id_verified_only: bool, photo_only: bool},
     *   approval_required: bool,
     *   require_contact_request: bool
     * }
     */
    public static function defaultResolvedContactVisibility(): array
    {
        $tmp = new self([
            'show_contact_to' => 'everyone',
            'contact_visibility_json' => null,
        ]);

        return $tmp->resolvedContactVisibility();
    }

    /**
     * Normalized contact visibility from {@see $contact_visibility_json}.
     * Legacy {@see $show_contact_to} is used only when JSON omits keys (not shown in UI).
     *
     * @return array{
     *   rule: string,
     *   strictness: string,
     *   filters: array{id_verified_only: bool, photo_only: bool},
     *   approval_required: bool,
     *   require_contact_request: bool
     * }
     */
    public function resolvedContactVisibility(): array
    {
        $json = is_array($this->contact_visibility_json) ? $this->contact_visibility_json : [];
        $rule = strtolower(trim((string) ($json['rule'] ?? '')));
        $strictness = strtolower(trim((string) ($json['strictness'] ?? '')));
        $filters = is_array($json['filters'] ?? null) ? $json['filters'] : [];
        $approval = array_key_exists('approval_required', $json)
            ? (bool) $json['approval_required']
            : false;
        $requireContactRequest = array_key_exists('require_contact_request', $json)
            ? (bool) $json['require_contact_request']
            : null;

        if ($rule === '') {
            $show = strtolower(trim((string) ($this->show_contact_to ?? 'everyone')));
            $rule = match ($show) {
                'no_one' => 'none',
                'accepted_interest' => 'interest',
                default => 'anyone',
            };
        }

        if (! in_array($rule, ['anyone', 'interest', 'matching', 'none'], true)) {
            $rule = 'anyone';
        }

        if (! in_array($strictness, ['relaxed', 'balanced', 'strict'], true)) {
            $strictness = ContactVisibilityStrictness::BALANCED;
        }

        $idVerified = (bool) ($filters['id_verified_only'] ?? $filters['verified_only'] ?? false);
        $photoOnly = (bool) ($filters['photo_only'] ?? false);

        if ($requireContactRequest === null) {
            $show = strtolower(trim((string) ($this->show_contact_to ?? '')));
            $requireContactRequest = ($show === 'unlock_only');
        }

        return [
            'rule' => $rule,
            'strictness' => $strictness,
            'filters' => [
                'id_verified_only' => $idVerified,
                'photo_only' => $photoOnly,
            ],
            'approval_required' => $approval,
            'require_contact_request' => (bool) $requireContactRequest,
        ];
    }
}
