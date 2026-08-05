<?php

/*
| Member self-service account deletion (Marathi).
|
| The confirmation word stays the English "delete" on purpose — decided with the
| product owner, so a Marathi keyboard never blocks the member from finishing.
*/

return [
    'deletion_confirmation_mismatch' => 'पुष्टीसाठी delete हा शब्द लिहा.',

    'deletion_reasons' => [
        'no_suitable_matches' => 'मला योग्य स्थळ मिळाले नाही',
        'found_match_elsewhere' => 'मला दुसरीकडे स्थळ मिळाले',
        'too_many_messages' => 'खूप जास्त संदेश किंवा सूचना येतात',
        'privacy_concern' => 'माझ्या गोपनीयतेबद्दल काळजी वाटते',
        'hard_to_use' => 'ॲप वापरणे अवघड वाटले',
        'other' => 'दुसरे कारण',
    ],
];
