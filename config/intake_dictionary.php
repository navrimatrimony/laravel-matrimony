<?php

/**
 * Intake dictionary: synonym → canonical token → display label.
 * Expand via admin UI later; keep additive only.
 */
return [

    'education' => [
        'tokens' => [
            'be' => 'Bachelor of Engineering',
            'btech' => 'B.Tech',
            'b.tech' => 'B.Tech',
            'mtech' => 'M.Tech',
            'm.tech' => 'M.Tech',
            'me' => 'M.E.',
            'msc' => 'M.Sc.',
            'mba' => 'MBA',
            'bcom' => 'B.Com',
            'b.com' => 'B.Com',
            'mcom' => 'M.Com',
            'ba' => 'B.A.',
            'ma' => 'M.A.',
            'phd' => 'Ph.D.',
            'diploma' => 'Diploma',
            'hsc' => 'HSC (12th)',
            'ssc' => 'SSC (10th)',
        ],
        'synonyms' => [
            'bachelor of engineering' => 'be',
            'b.e' => 'be',
            'b.e.' => 'be',
            'b e' => 'be',
            'बीई' => 'be',
            'बी.ई' => 'be',
            'bachelor of technology' => 'btech',
            'master of engineering' => 'me',
            'master of technology' => 'mtech',
        ],
    ],

    'occupation' => [
        'tokens' => [
            'engineer' => 'Engineer',
            'software_engineer' => 'Software Engineer',
            'teacher' => 'Teacher',
            'doctor' => 'Doctor',
            'business' => 'Business',
            'govt' => 'Government service',
            'private' => 'Private sector',
        ],
        'synonyms' => [
            'sw eng' => 'software_engineer',
            's/w engineer' => 'software_engineer',
            'शिक्षक' => 'teacher',
            'डॉक्टर' => 'doctor',
            'व्यवसाय' => 'business',
        ],
    ],

    'caste' => [
        'tokens' => [],
        'synonyms' => [],
    ],

    'subcaste' => [
        'tokens' => [],
        'synonyms' => [],
    ],

    'location_text' => [
        'synonyms' => [
            'mumbai' => 'Mumbai',
            'bombay' => 'Mumbai',
            'पुणे' => 'Pune',
            'poona' => 'Pune',
            'nashik' => 'Nashik',
            'नाशिक' => 'Nashik',
        ],
    ],

    'core_field_map' => [
        'highest_education' => 'education',
        'occupation' => 'occupation',
        'occupation_text' => 'occupation',
    ],

    'extended_field_map' => [
        'highest_education' => 'education',
        'occupation' => 'occupation',
    ],
];
