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
        ],
        'interest_accepted' => [
            'label' => 'Interest accepted',
            'description' => 'When someone accepts the interest you sent.',
            'title' => 'Interest accepted',
            'body' => 'Your interest has been accepted.',
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

        'mediation_request_received' => [
            'label' => 'Suchak request received',
            'description' => 'When a request reaches you through a Suchak.',
            'title' => 'Suchak request',
            'body' => 'A new request has reached you through a Suchak.',
        ],
        'mediation_request_response' => [
            'label' => 'Suchak request answered',
            'description' => 'When your Suchak request is answered.',
            'title' => 'Suchak request answered',
            'body' => 'There is an answer to your Suchak request.',
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

    ],

];
