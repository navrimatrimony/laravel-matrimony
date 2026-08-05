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
];
