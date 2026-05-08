<?php

return [
    'yaml_dir' => env('DATA_GOVERNANCE_ENTITIES_YAML_DIR', base_path('python-data-engine/config/entities')),
    'map' => [
        'matrimony_profile' => [
            'adapter' => 'matrimony_profile',
            'table' => 'matrimony_profiles',
            'id_column' => 'id',
            'canonical_fields' => [
                'full_name', 'gender', 'date_of_birth', 'height_cm', 'religion', 'caste', 'education', 'occupation', 'annual_income', 'city', 'state', 'mother_tongue', 'marital_status',
            ],
        ],
    ],
];

