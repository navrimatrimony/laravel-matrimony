<?php

namespace App\Support;

final class NotificationMarathiPayload
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withMessage(array $payload): array
    {
        if (isset($payload['message_key']) && is_string($payload['message_key'])) {
            return NotificationLocalization::enrichPayload($payload);
        }

        if (! isset($payload['message_mr']) || trim((string) ($payload['message_mr'] ?? '')) === '') {
            $message = self::message($payload);
            if ($message !== null && $message !== '') {
                $payload['message_mr'] = $message;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function message(array $payload): ?string
    {
        $type = (string) ($payload['type'] ?? '');
        $message = trim((string) ($payload['message'] ?? ''));

        return match ($type) {
            'profile_viewed' => self::profileViewed($payload, $message),
            'interest_sent' => self::interestSent($payload, $message),
            'interest_accepted' => self::nameAction($message, ' accepted your interest.', 'यांनी तुमची इच्छा स्वीकारली.'),
            'interest_rejected' => self::nameAction($message, ' declined your interest.', 'यांनी तुमची इच्छा नाकारली.'),
            'chat_message' => self::nameAction($message, ' sent you a message.', 'यांनी तुम्हाला संदेश पाठवला.'),
            'chat_message_locked' => self::chatMessageLocked($message),
            'contact_request_received' => self::nameAction($message, ' requested your contact details.', 'यांनी तुमचा संपर्क मागितला.')
                ?? self::nameAction($message, ' requested your contact.', 'यांनी तुमचा संपर्क मागितला.'),
            'contact_request_accepted' => self::contactRequestAccepted($message),
            'contact_request_rejected' => self::contactRequestRejected($message),
            'contact_request_expired' => 'तुमच्या संपर्क विनंतीला उत्तर मिळाले नाही आणि ती कालबाह्य झाली आहे. इच्छा असल्यास तुम्ही नवीन विनंती पाठवू शकता.',
            'contact_grant_revoked' => 'त्यांच्या संपर्काचा तुमचा प्रवेश रद्द केला आहे.',
            'image_approved' => 'तुमची प्रोफाइल प्रतिमा admin ने मंजूर केली आहे.',
            'image_rejected' => self::imageRejected($message, $payload),
            'profile_suspended' => 'तुमचे विवाह प्रोफाइल निलंबित केले आहे.',
            'profile_unsuspended' => 'तुमचे विवाह प्रोफाइल पुन्हा सक्रिय केले आहे.',
            'profile_soft_deleted' => 'तुमचे विवाह प्रोफाइल हटवले आहे.',
            'inactive_reminder' => self::inactiveReminder($message),
            'new_matches_digest' => self::newMatches($payload),
            'plan_expiring_soon' => self::planExpiring($payload),
            'referral_reward' => self::referralReward($payload),
            'referral_invite_registered' => self::referralInviteRegistered($payload),
            'referral_invite_upgraded' => self::referralInviteUpgraded($payload),
            'referral_reward_pending' => self::referralRewardPending($payload),
            'referral_cap_skipped' => self::referralCapSkipped($payload),
            'mediation_request_received' => self::mediationReceived($message),
            'mediation_request_response' => self::mediationResponse($message),
            default => null,
        };
    }

    private static function profileViewed(array $payload, string $message): string
    {
        if (($payload['revealed'] ?? true) === false) {
            return 'कोणीतरी तुमचे प्रोफाइल पाहिले.';
        }

        return self::nameAction($message, ' viewed your profile.', 'यांनी तुमचे प्रोफाइल पाहिले.')
            ?? 'कोणीतरी तुमचे प्रोफाइल पाहिले.';
    }

    private static function interestSent(array $payload, string $message): string
    {
        if (($payload['revealed'] ?? true) === false || (int) ($payload['sender_profile_id'] ?? 0) <= 0) {
            return 'कोणीतरी तुम्हाला इच्छा पाठवली. प्लॅननुसार व्यवस्थापनासाठी मिळालेल्या इच्छा उघडा.';
        }

        return self::nameAction($message, ' sent you an interest.', 'यांनी तुम्हाला इच्छा पाठवली.')
            ?? 'कोणीतरी तुम्हाला इच्छा पाठवली.';
    }

    private static function chatMessageLocked(string $message): string
    {
        $name = self::nameBefore($message, ' messaged you. Upgrade to read messages');
        if ($name !== null) {
            return $name.' यांनी तुम्हाला संदेश पाठवला. वाचण्यासाठी अपग्रेड करा - पैसे देण्यापूर्वी किंमत स्पष्ट दाखवली जाते.';
        }

        return 'तुम्हाला एक नवीन संदेश आला आहे. वाचण्यासाठी अपग्रेड करा - पैसे देण्यापूर्वी किंमत स्पष्ट दाखवली जाते.';
    }

    private static function contactRequestAccepted(string $message): string
    {
        $name = self::nameBefore($message, ' approved your contact request.');
        if ($name === null) {
            return 'तुमची संपर्क विनंती मंजूर झाली आहे.';
        }

        $validUntil = self::after($message, 'Valid until ');

        return $name.' यांनी तुमची संपर्क विनंती मंजूर केली. शेअर केले: प्राथमिक फोन.'
            .($validUntil !== null ? ' वैध पर्यंत '.$validUntil : '');
    }

    private static function contactRequestRejected(string $message): string
    {
        $endsAt = self::between($message, 'ends ', ').');

        return 'तुमची संपर्क विनंती नाकारली आहे.'
            .($endsAt !== null ? ' कूलिंग कालावधी संपल्यानंतर पुन्हा विनंती करू शकता ('.$endsAt.').' : '');
    }

    private static function imageRejected(string $message, array $payload): string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            $reason = self::after($message, 'Reason: ') ?? '';
        }

        return 'तुमचा प्रोफाइल फोटो admin ने काढला आहे.'
            .($reason !== '' ? ' कारण: '.$reason : '');
    }

    private static function inactiveReminder(string $message): string
    {
        $name = self::between($message, 'Hi ', ', it has been a while') ?? 'सदस्या';

        return 'नमस्कार '.$name.', तुम्ही काही काळ लॉग इन केले नाही. अपडेट्स, संदेश आणि जुळण्या पाहण्यासाठी लॉग इन करा.';
    }

    private static function newMatches(array $payload): ?string
    {
        $count = (int) ($payload['match_count'] ?? $payload['count'] ?? 0);
        $score = (int) ($payload['top_score'] ?? $payload['best_score'] ?? $payload['score'] ?? 0);
        if ($count <= 0) {
            return null;
        }

        return 'तुमच्यासाठी '.$count.' शिफारस केलेल्या जुळण्या आहेत'
            .($score > 0 ? ' (सर्वोत्तम गुण '.$score.'/100)' : '')
            .'. पाहण्यासाठी Matches उघडा.';
    }

    private static function planExpiring(array $payload): ?string
    {
        $plan = trim((string) ($payload['plan_name'] ?? $payload['plan'] ?? ''));
        $days = (int) ($payload['days_left'] ?? $payload['days_remaining'] ?? $payload['days'] ?? 0);
        if ($plan === '' || $days <= 0) {
            return null;
        }

        return 'तुमचा '.$plan.' प्लॅन '.$days.' दिवसांत संपेल. पूर्ण प्रवेश सुरू ठेवण्यासाठी renewal करा - hidden auto-charges नाहीत.';
    }

    private static function referralReward(array $payload): ?string
    {
        $benefits = trim((string) ($payload['benefits_summary'] ?? ''));
        $plan = trim((string) ($payload['purchased_plan_name'] ?? $payload['plan'] ?? ''));
        $days = (int) ($payload['bonus_days'] ?? $payload['days'] ?? 0);

        if ($benefits !== '' && $plan !== '') {
            return NotificationLocalization::translate('notifications.referral_reward_message', [
                'plan' => $plan,
                'benefits' => $benefits,
            ], NotificationLocalization::LOCALE_MR);
        }

        if ($plan !== '' && $days > 0) {
            return NotificationLocalization::translate('notifications.referral_reward_message_days_only', [
                'plan' => $plan,
                'days' => $days,
            ], NotificationLocalization::LOCALE_MR);
        }

        return null;
    }

    private static function referralInviteRegistered(array $payload): ?string
    {
        $name = trim((string) ($payload['invitee_name'] ?? ''));

        return NotificationLocalization::translate('notifications.referral_invite_registered_message', [
            'name' => $name !== '' ? $name : __('referrals.member_placeholder'),
        ], NotificationLocalization::LOCALE_MR);
    }

    private static function referralInviteUpgraded(array $payload): ?string
    {
        $name = trim((string) ($payload['invitee_name'] ?? ''));
        $plan = trim((string) ($payload['plan_name'] ?? ''));

        return NotificationLocalization::translate('notifications.referral_invite_upgraded_message', [
            'name' => $name !== '' ? $name : __('referrals.member_placeholder'),
            'plan' => $plan !== '' ? $plan : __('referrals.member_placeholder'),
        ], NotificationLocalization::LOCALE_MR);
    }

    private static function referralRewardPending(array $payload): ?string
    {
        return NotificationLocalization::translate('notifications.referral_reward_pending_message', [
            'name' => trim((string) ($payload['invitee_name'] ?? '')) ?: __('referrals.member_placeholder'),
            'plan' => trim((string) ($payload['plan_name'] ?? '')) ?: __('referrals.member_placeholder'),
            'days' => (int) ($payload['bonus_days'] ?? 0),
        ], NotificationLocalization::LOCALE_MR);
    }

    private static function referralCapSkipped(array $payload): ?string
    {
        return NotificationLocalization::translate('notifications.referral_cap_skipped_message', [
            'name' => trim((string) ($payload['invitee_name'] ?? '')) ?: __('referrals.member_placeholder'),
            'plan' => trim((string) ($payload['plan_name'] ?? '')) ?: __('referrals.member_placeholder'),
        ], NotificationLocalization::LOCALE_MR);
    }

    private static function mediationReceived(string $message): string
    {
        $name = self::nameBefore($message, ' would like us to ask for your response on WhatsApp.');
        $hint = self::after($message, 'WhatsApp. ');
        if ($name === null) {
            $name = self::nameBefore($message, ' would like an introduction through assisted matchmaking.');
            $hint = self::after($message, 'assisted matchmaking. ');
        }

        return ($name ?? 'कोणीतरी').' यांनी WhatsApp वरून तुमचा प्रतिसाद विचारण्याची विनंती केली आहे.'
            .($hint !== null && $hint !== '' ? ' '.$hint : '');
    }

    private static function mediationResponse(string $message): ?string
    {
        $name = self::nameBefore($message, ' is interested');
        if ($name !== null) {
            return $name.' यांना रस आहे - तुमचा प्लॅन परवानगी देत असल्यास संपर्क credit वापरून त्यांचा संपर्क उघड करू शकता.';
        }

        $name = self::nameBefore($message, ' is not interested');
        if ($name !== null) {
            return $name.' यांना सध्या पुढे नेण्यात रस नाही.';
        }

        $name = self::nameBefore($message, ' would like more information');
        if ($name !== null) {
            return $name.' निर्णय घेण्यापूर्वी अधिक माहिती मागत आहेत.';
        }

        $name = self::nameBefore($message, ' will decide later.');
        if ($name !== null) {
            return $name.' नंतर कळवणार आहेत.';
        }

        $name = self::nameBefore($message, ' already has talks in progress.');
        if ($name !== null) {
            return $name.' यांची सध्या बोलणी सुरू आहेत.';
        }

        return null;
    }

    private static function nameAction(string $message, string $englishSuffix, string $marathiSuffix): ?string
    {
        $name = self::nameBefore($message, $englishSuffix);

        return $name === null ? null : $name.' '.$marathiSuffix;
    }

    private static function nameBefore(string $message, string $suffix): ?string
    {
        $pos = strpos($message, $suffix);
        if ($pos === false || $pos <= 0) {
            return null;
        }

        $name = trim(substr($message, 0, $pos));

        return $name !== '' ? $name : null;
    }

    private static function after(string $message, string $needle): ?string
    {
        $pos = strpos($message, $needle);
        if ($pos === false) {
            return null;
        }

        $value = trim(substr($message, $pos + strlen($needle)));

        return $value !== '' ? $value : null;
    }

    private static function between(string $message, string $start, string $end): ?string
    {
        $from = strpos($message, $start);
        if ($from === false) {
            return null;
        }
        $from += strlen($start);
        $to = strpos($message, $end, $from);
        if ($to === false || $to <= $from) {
            return null;
        }

        $value = trim(substr($message, $from, $to - $from));

        return $value !== '' ? $value : null;
    }
}
