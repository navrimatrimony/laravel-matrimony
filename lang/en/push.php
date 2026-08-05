<?php

/**
 * Push notification copy — the user-visible half of App\Services\Push\PushTypeRegistry.
 *
 * `label` / `description` are what the in-app notification-settings screen shows
 * for that switch. `title` / `body` are what actually lands on the lock screen.
 *
 * Adding a type: add the same four strings here AND in lang/mr/push.php, keyed by
 * the push key you added to the registry. A missing string renders as the raw
 * translation key, which is visible in QA rather than silently blank.
 *
 * Digits in every string MUST be Latin 0-9 (frozen workspace rule) — this applies
 * to the Marathi file too.
 *
 * `body` is the generic fallback — used when the server knows nothing about the
 * person behind the event. `body_named` / `body_named_preview` are used instead
 * when the receiver's plan has ALREADY revealed that person, so their name may be
 * spoken. A locked row never reaches these keys: its body is the teaser line the
 * WhoViewedTeaserPresenter produced under the admin's privacy policy. See
 * App\Services\Push\PushTeaserCopyService.
 */
return [

    'groups' => [
        'interest' => 'Interest',
        'chat' => 'Messages',
        'contact' => 'Contact requests',
        'profile' => 'My profile',
        'engagement' => 'Suggestions and reminders',
        'account' => 'Account and plan',
    ],

    'quiet_hours' => [
        'label' => 'Quiet hours',
        // :start and :end are pre-formatted as HH:MM in Latin digits.
        // Wording must match the implemented behaviour: push is SUPPRESSED during
        // the window, not held for later. Nothing is lost — the notification is
        // still in the app's list.
        'description' => 'Between :start and :end no push notifications are sent. You will still see them in the app.',
    ],

    'types' => [

        'new_interest' => [
            'label' => 'New interest received',
            'description' => 'When someone sends you an interest.',
            'title' => 'New interest',
            'body' => 'Someone has shown interest in your profile.',
            'body_named' => ':name sent you an interest.',
        ],
        'interest_accepted' => [
            'label' => 'Interest accepted',
            'description' => 'When someone accepts the interest you sent.',
            'title' => 'Interest accepted',
            'body' => 'Your interest has been accepted.',
            'body_named' => ':name accepted your interest.',
        ],
        'interest_rejected' => [
            'label' => 'Interest declined',
            'description' => 'When someone declines the interest you sent.',
            'title' => 'Interest declined',
            'body' => 'Your interest was not accepted.',
        ],

        'new_chat_message' => [
            'label' => 'New message',
            'description' => 'When you receive a new chat message.',
            'title' => 'New message',
            'body' => 'You have received a new message.',
            'body_named' => ':name sent you a message.',
            'body_named_preview' => ':name: :preview',
        ],
        'chat_message_locked' => [
            'label' => 'Message locked',
            'description' => 'When a message needs an active plan to open.',
            'title' => 'Message waiting',
            'body' => 'A message is waiting for you. An active plan is needed to open it.',
        ],

        'contact_request_received' => [
            'label' => 'Contact request received',
            'description' => 'When someone asks for your contact details.',
            'title' => 'Contact request',
            'body' => 'Someone has requested your contact details.',
        ],
        'contact_request_accepted' => [
            'label' => 'Contact request approved',
            'description' => 'When your contact request is approved.',
            'title' => 'Contact request approved',
            'body' => 'Your contact request has been approved.',
        ],
        'contact_request_rejected' => [
            'label' => 'Contact request declined',
            'description' => 'When your contact request is declined.',
            'title' => 'Contact request declined',
            'body' => 'Your contact request was not approved.',
        ],
        'contact_request_expired' => [
            'label' => 'Contact request expired',
            'description' => 'When a contact request expires without an answer.',
            'title' => 'Contact request expired',
            'body' => 'A contact request has expired.',
        ],
        'contact_grant_revoked' => [
            'label' => 'Contact access withdrawn',
            'description' => 'When someone withdraws contact access they had given.',
            'title' => 'Contact access withdrawn',
            'body' => 'Contact access has been withdrawn.',
        ],

        // Mediation = an assisted contact request between two members. Not the
        // Suchak profile-request pipeline; do not word it as one.
        'mediation_request_received' => [
            'label' => 'Mediation request received',
            'description' => 'When an assisted request reaches you.',
            'title' => 'New request',
            'body' => 'A new request has reached you.',
        ],
        'mediation_request_response' => [
            'label' => 'Mediation request answered',
            'description' => 'When your assisted request is answered.',
            'title' => 'Request answered',
            'body' => 'There is an answer to your request.',
        ],

        'photo_approved' => [
            'label' => 'Photo approved',
            'description' => 'When your uploaded photo is approved.',
            'title' => 'Photo approved',
            'body' => 'Your photo has been approved.',
        ],
        'photo_rejected' => [
            'label' => 'Photo not approved',
            'description' => 'When your uploaded photo is not approved.',
            'title' => 'Photo not approved',
            'body' => 'Your photo was not approved. Please upload another one.',
        ],

        'profile_viewed' => [
            'label' => 'Profile views',
            'description' => 'When someone views your profile.',
            'title' => 'Profile viewed',
            'body' => 'Someone has viewed your profile.',
            'body_named' => ':name viewed your profile.',
        ],
        'new_matches' => [
            'label' => 'New matches',
            'description' => 'A daily summary of new matches for you.',
            'title' => 'New matches',
            'body' => 'New matches are available for you.',
        ],
        'inactive_reminder' => [
            'label' => 'Reminders',
            'description' => 'A reminder when you have not opened the app for a while.',
            'title' => 'New profiles are waiting',
            'body' => 'New profiles have been added since your last visit.',
        ],

        'plan_expiring' => [
            'label' => 'Plan expiring',
            'description' => 'Before your plan expires.',
            'title' => 'Plan expiring soon',
            'body' => 'Your plan is expiring soon.',
        ],
        'profile_suspended' => [
            'label' => 'Profile suspended',
            'description' => 'When your profile is suspended.',
            'title' => 'Profile suspended',
            'body' => 'Your profile has been suspended.',
        ],
        'profile_unsuspended' => [
            'label' => 'Profile restored',
            'description' => 'When your profile is restored.',
            'title' => 'Profile restored',
            'body' => 'Your profile is active again.',
        ],
        'profile_soft_deleted' => [
            'label' => 'Profile removed',
            'description' => 'When your profile is removed.',
            'title' => 'Profile removed',
            'body' => 'Your profile has been removed.',
        ],
        'password_changed' => [
            'label' => 'Password changed',
            'description' => 'When your account password is changed.',
            'title' => 'Password changed',
            'body' => 'Your account password was changed. If this was not you, contact us now.',
        ],
        'referral_activity' => [
            'label' => 'Referral activity',
            'description' => 'When someone you invited joins.',
            'title' => 'Referral update',
            'body' => 'There is an update on someone you invited.',
        ],
        'referral_reward' => [
            'label' => 'Referral reward',
            'description' => 'When you receive a referral reward.',
            'title' => 'Referral reward',
            'body' => 'You have received a referral reward.',
        ],
        'suchak_customer_deletion_requested' => [
            'label' => 'Customer leaving',
            'description' => 'When a customer you represent requests account deletion.',
            'title' => 'Customer leaving',
            'body' => ':customer_full_name requested account deletion on :event_date.',
        ],
        'suchak_customer_deletion_cancelled' => [
            'label' => 'Customer stayed',
            'description' => 'When a customer you represent cancels a pending account deletion.',
            'title' => 'Customer stayed',
            'body' => ':customer_full_name cancelled account deletion on :event_date.',
        ],
        'dispute_party_deletion_requested' => [
            'label' => 'Dispute party leaving',
            'description' => 'When a member in an open dispute requests account deletion.',
            'title' => 'Dispute party leaving',
            'body' => ':customer_full_name requested deletion on :event_date (:open_dispute_count open dispute(s)).',
        ],

    ],

];
