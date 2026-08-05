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

    // Suchak push/database copy for U2 — placeholders :name and :date (Latin digits).
    'suchak_customer_deletion_requested' => ':name यांनी :date रोजी खाते हटवण्याची विनंती केली.',
    'suchak_customer_deletion_cancelled' => ':name यांनी :date रोजी खाते हटवण्याची विनंती रद्द केली.',

    // Admin alert for U3 — :name, :date, :count (Latin digits).
    'dispute_party_deletion_requested' => ':name यांनी :date रोजी खाते हटवण्याची विनंती केली; त्यांचे :count खुले dispute आहेत.',
];
