<?php

/*
| Member self-service account deletion.
|
| Reason labels are keyed by MemberAccountDeletionService::REASONS. Adding a
| reason there without a label here shows the raw key, so keep the two in step.
*/

return [
    'deletion_confirmation_mismatch' => 'Type the word delete to confirm.',

    'deletion_reasons' => [
        'no_suitable_matches' => 'I did not find suitable matches',
        'found_match_elsewhere' => 'I found a match elsewhere',
        'too_many_messages' => 'Too many messages or notifications',
        'privacy_concern' => 'I am concerned about my privacy',
        'hard_to_use' => 'The app was hard to use',
        'other' => 'Another reason',
    ],

    // Suchak push/database copy for U2 — placeholders :name and :date (Latin digits).
    'suchak_customer_deletion_requested' => ':name requested account deletion on :date.',
    'suchak_customer_deletion_cancelled' => ':name cancelled account deletion on :date.',

    // Admin alert for U3 — :name, :date, :count (Latin digits).
    'dispute_party_deletion_requested' => ':name requested account deletion on :date and is a party to :count open dispute(s).',
];
