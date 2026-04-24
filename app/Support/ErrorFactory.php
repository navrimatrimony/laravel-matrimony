<?php

namespace App\Support;

use App\DTO\RuleResult;

/**
 * Central user-facing messages (Marathi / Hinglish) + optional action payloads.
 * URLs/labels may be overridden via {@see SystemRule::$meta} (action_type, action_label, action_url, modal_id).
 */
class ErrorFactory
{
    public static function generic(): RuleResult
    {
        return new RuleResult(
            false,
            'GENERIC_ERROR',
            'काहीतरी चुकीचे झाले. पुन्हा प्रयत्न करा.',
            null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $action  Same shape as RuleResult action (type, label, url, modal_id)
     */
    public static function deny(string $code, string $message, ?array $action = null): RuleResult
    {
        return new RuleResult(false, $code, $message, $action);
    }

    public static function photoUploadFailed(): RuleResult
    {
        return new RuleResult(
            false,
            'PHOTO_UPLOAD_FAILED',
            'Photo upload जमला नाही. पुन्हा प्रयत्न करा किंवा छोटा फाइल वापरा.',
            [
                'type' => 'redirect',
                'label' => 'Photo section',
                'url' => route('matrimony.profile.upload-photo'),
            ],
        );
    }

    public static function helpCentreNetwork(): RuleResult
    {
        return new RuleResult(
            false,
            'HELP_CENTRE_NETWORK',
            'Network issue. थोड्या वेळाने पुन्हा प्रयत्न करा.',
            null,
        );
    }

    public static function subCasteCannotMergeIntoSelf(): RuleResult
    {
        return new RuleResult(
            false,
            'SUBCASTE_MERGE_SELF',
            'Swatahla swatah madhe merge karu shakat nahi.',
            null,
        );
    }

    public static function subCasteMergeProfilesFailed(): RuleResult
    {
        return new RuleResult(
            false,
            'SUBCASTE_MERGE_FAILED',
            'Profiles update करताना त्रुटी. Admin कडे तपासा किंवा पुन्हा प्रयत्न करा.',
            null,
        );
    }

    public static function subCasteNotPending(): RuleResult
    {
        return new RuleResult(
            false,
            'SUBCASTE_NOT_PENDING',
            'हे sub-caste आधीच approve झाले किंवा pending नाही.',
            null,
        );
    }

    public static function profileWizardInvalidSnapshot(): RuleResult
    {
        return new RuleResult(
            false,
            'PROFILE_WIZARD_INVALID',
            'हा section सध्या save होऊ शकत नाही. फील्ड तपून पुन्हा प्रयत्न करा.',
            [
                'type' => 'redirect',
                'label' => 'Wizard',
                'url' => route('matrimony.profile.wizard'),
            ],
        );
    }

    public static function adminTagAssignFailed(): RuleResult
    {
        return new RuleResult(
            false,
            'ADMIN_TAG_ASSIGN_FAILED',
            'Tag लावता आला नाही. Validations तपासा.',
            null,
        );
    }

    public static function adminTagRemoveFailed(): RuleResult
    {
        return new RuleResult(
            false,
            'ADMIN_TAG_REMOVE_FAILED',
            'Tag काढता आला नाही. Validations तपासा.',
            null,
        );
    }

    public static function photoModerationSelectPhotos(): RuleResult
    {
        return new RuleResult(
            false,
            'PHOTO_MODERATION_SELECT',
            'किमान एक photo निवडा.',
            null,
        );
    }

    public static function photoModerationReasonMinLength(): RuleResult
    {
        return new RuleResult(
            false,
            'PHOTO_MODERATION_REASON',
            'Reject साठी reason किमान 10 अक्षरे लिहा.',
            null,
        );
    }

    public static function adminSuggestionsChooseActionBeforeApply(): RuleResult
    {
        return new RuleResult(
            false,
            'ADMIN_SUGGESTIONS_NO_ACTION',
            'Apply करण्यापूर्वी Accept / Reject / Flag मधून किमान एक निवडा.',
            null,
        );
    }

    public static function intakeManualCropCornersTooSmall(): RuleResult
    {
        return new RuleResult(
            false,
            'INTAKE_CROP_CORNERS',
            'चारही कोपरे अचूक जवळ आणा, नंतर save करा.',
            null,
        );
    }

    public static function intakeManualCropNoRedirect(): RuleResult
    {
        return new RuleResult(
            false,
            'INTAKE_CROP_NO_REDIRECT',
            'Save झाला पण पुढचा URL मिळाला नाही. Page refresh करा.',
            null,
        );
    }

    public static function intakeManualCropWarpSaveFailed(): RuleResult
    {
        return new RuleResult(
            false,
            'INTAKE_CROP_SAVE_FAILED',
            'Crop save जमला नाही. पुन्हा प्रयत्न करा.',
            null,
        );
    }

    public static function interestSendBlocked(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_SEND_BLOCKED',
            'Interest सध्या पाठवता येत नाही. नियम तपासा किंवा पुन्हा प्रयत्न करा.',
            [
                'type' => 'redirect',
                'label' => 'Profiles',
                'url' => route('matrimony.profiles.index'),
            ],
        );
    }

    public static function interestSendLimitHttp(int $statusCode, string $message): RuleResult
    {
        $action = [
            'type' => 'redirect',
            'label' => 'Plans bagha',
            'url' => route('plans.index'),
        ];

        return new RuleResult(
            false,
            'INTEREST_SEND_LIMIT',
            $message,
            $statusCode === 403 ? $action : null,
        );
    }

    public static function interestApiMatrimonyProfileRequired(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_NEED_PROFILE',
            'आधी matrimony profile तयार करा.',
            [
                'type' => 'redirect',
                'label' => 'Profile सुरू करा',
                'url' => route('matrimony.profile.wizard.section', ['section' => 'basic-info']),
            ],
        );
    }

    public static function interestApiCannotSendToSelf(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_SELF',
            'Swatahla interest pathvu shakat nahi.',
            null,
        );
    }

    public static function interestApiProfilesMissing(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_PROFILE_MISSING',
            'Profile सापडला नाही. Refresh करून पुन्हा प्रयत्न करा.',
            null,
        );
    }

    public static function interestApiSenderLifecycleBlocked(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_SENDER_STATE',
            'Tumcha profile ya state madhun interest pathvu shakat nahi.',
            [
                'type' => 'redirect',
                'label' => 'Profile bagha',
                'url' => route('matrimony.profile.edit'),
            ],
        );
    }

    public static function interestApiReceiverLifecycleBlocked(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_RECEIVER_STATE',
            'Ya profile la interest pathvu shakat nahi.',
            null,
        );
    }

    public static function interestApiDuplicateInterest(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_DUPLICATE',
            'Interest आधीच पाठवला आहे.',
            null,
        );
    }

    public static function interestApiNotFound(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_NOT_FOUND',
            'Interest सापडला नाही.',
            null,
        );
    }

    public static function interestApiOnlyReceiver(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_NOT_RECEIVER',
            'फक्त receiver ही क्रिया करू शकतो.',
            null,
        );
    }

    public static function interestApiOnlySenderWithdraw(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_API_NOT_SENDER',
            'फक्त pathvnara (sender) cancel करू शकतो.',
            null,
        );
    }

    public static function interestApiAlreadyProcessed(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_ALREADY_PROCESSED',
            'हा interest आधीच process झाला आहे.',
            null,
        );
    }

    public static function interestApiOnlyPendingWithdraw(): RuleResult
    {
        return new RuleResult(
            false,
            'INTEREST_WITHDRAW_NOT_PENDING',
            'फक्त pending interest cancel करता येईल.',
            null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta  From system_rules.meta
     */
    public static function profileIncompleteSender(int $required, ?array $meta = null): RuleResult
    {
        $base = [
            'type' => 'redirect',
            'label' => 'Profile complete kara',
            'url' => route('matrimony.profile.edit'),
        ];

        return new RuleResult(
            false,
            'PROFILE_INCOMPLETE',
            "Interest pathvnyasathi tumcha profile complete kara (minimum {$required}%).",
            self::mergeActionMeta($meta, $base),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function profileIncompleteTarget(?array $meta = null): RuleResult
    {
        $base = [
            'type' => 'redirect',
            'label' => 'Dusri profile bagha',
            'url' => route('matrimony.profiles.index'),
        ];

        return new RuleResult(
            false,
            'PROFILE_INCOMPLETE_TARGET',
            'Ya profile la interest pathvu shakat nahi — samorche profile ajun puran nahi.',
            self::mergeActionMeta($meta, $base),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function profileIncompleteAccept(int $required, ?array $meta = null): RuleResult
    {
        $base = [
            'type' => 'redirect',
            'label' => 'Profile complete kara',
            'url' => route('matrimony.profile.edit'),
        ];

        return new RuleResult(
            false,
            'PROFILE_INCOMPLETE_ACCEPT',
            "Interest accept karanyasathi tumcha profile kiman {$required}% complete asa lagel.",
            self::mergeActionMeta($meta, $base),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function planExpired(?array $meta = null): RuleResult
    {
        $base = [
            'type' => 'redirect',
            'label' => 'Plan ghe',
            'url' => route('plans.index'),
        ];

        return new RuleResult(
            false,
            'PLAN_EXPIRED',
            'Tumcha plan sampla ahe. Pudhe chalnyasathi plan ghya.',
            self::mergeActionMeta($meta, $base),
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array{type: string, label: string, url: string, modal_id?: string}  $defaults
     * @return array<string, mixed>
     */
    private static function mergeActionMeta(?array $meta, array $defaults): array
    {
        if ($meta === null || $meta === []) {
            return $defaults;
        }

        $type = isset($meta['action_type']) && is_string($meta['action_type'])
            ? $meta['action_type']
            : $defaults['type'];

        $label = isset($meta['action_label']) && is_string($meta['action_label']) && $meta['action_label'] !== ''
            ? $meta['action_label']
            : $defaults['label'];

        $url = $defaults['url'];
        if (isset($meta['action_url']) && is_string($meta['action_url']) && $meta['action_url'] !== '') {
            $raw = $meta['action_url'];
            $url = str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')
                ? $raw
                : url($raw);
        } elseif (isset($meta['url']) && is_string($meta['url']) && $meta['url'] !== '') {
            $raw = $meta['url'];
            $url = str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')
                ? $raw
                : url($raw);
        }

        $out = array_merge($defaults, [
            'type' => $type,
            'label' => $label,
            'url' => $url,
        ]);

        if (isset($meta['modal_id']) && is_string($meta['modal_id'])) {
            $out['modal_id'] = $meta['modal_id'];
        }

        return $out;
    }
}
