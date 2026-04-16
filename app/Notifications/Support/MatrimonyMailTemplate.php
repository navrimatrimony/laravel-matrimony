<?php

namespace App\Notifications\Support;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Builds Markdown emails for matrimony activity notifications (single layout + i18n keys).
 */
final class MatrimonyMailTemplate
{
    /**
     * @param  array<string, mixed>  $payload  Output of {@see Notification::toArray()}.
     */
    public static function fromToArray(array $payload): MailMessage
    {
        $type = (string) ($payload['type'] ?? 'default');
        $baseKey = 'mail.types.'.$type;
        $subject = __($baseKey.'.subject');
        if ($subject === $baseKey.'.subject') {
            $type = 'default';
            $baseKey = 'mail.types.default';
            $subject = __($baseKey.'.subject');
        }

        $title = __($baseKey.'.title');
        $intro = (string) ($payload['message'] ?? '');
        $detail = self::detailLine($type, $payload);
        $secondary = self::secondaryAction($type, $payload);

        $actionUrl = trim((string) ($payload['mail_action_url'] ?? ''));
        if ($actionUrl === '') {
            $actionUrl = url(route('notifications.index', [], false));
        } elseif (! str_starts_with($actionUrl, 'http')) {
            $actionUrl = url($actionUrl);
        }

        $actionText = trim((string) ($payload['mail_action_text'] ?? ''));
        if ($actionText === '') {
            $actionText = __('mail.common.open_notifications');
        }

        return (new MailMessage)
            ->subject($subject)
            ->markdown('emails.notifications.generic-alert', [
                'title' => $title,
                'intro' => $intro,
                'detail' => $detail,
                'actionUrl' => $actionUrl,
                'actionText' => $actionText,
                'secondaryUrl' => $secondary['url'] ?? null,
                'secondaryText' => $secondary['text'] ?? null,
            ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function detailLine(string $type, array $payload): ?string
    {
        return match ($type) {
            'chat_message' => filled($payload['message_preview'] ?? null)
                ? trim((string) $payload['message_preview'])
                : null,
            'plan_expiring_soon' => null,
            'image_rejected', 'image_approved', 'profile_suspended', 'profile_soft_deleted', 'profile_unsuspended' => self::reasonDetail($payload),
            'mediation_request_response' => filled($payload['feedback'] ?? null)
                ? (string) $payload['feedback']
                : null,
            'referral_reward' => null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function reasonDetail(array $payload): ?string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            return null;
        }

        return __('mail.detail.admin_reason', ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{url?: string, text?: string}
     */
    private static function secondaryAction(string $type, array $payload): array
    {
        if (! in_array($type, ['chat_message', 'chat_message_locked'], true)) {
            return [];
        }

        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            return [];
        }

        return [
            'url' => url(route('chat.show', ['conversation' => $conversationId], false)),
            'text' => __('mail.common.open_chat'),
        ];
    }
}
