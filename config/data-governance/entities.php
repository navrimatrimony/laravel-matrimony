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
        'customer' => [
            'adapter' => 'generic_table',
            'table' => 'customers',
            'id_column' => 'id',
            'canonical_fields' => ['name', 'email', 'phone', 'city'],
        ],
        'employee' => [
            'adapter' => 'generic_table',
            'table' => 'employees',
            'id_column' => 'id',
            'canonical_fields' => ['name', 'department', 'city'],
        ],
        'vendor' => [
            'adapter' => 'generic_table',
            'table' => 'vendors',
            'id_column' => 'id',
            'canonical_fields' => ['name', 'contact_email', 'city'],
        ],
        'listing' => [
            'adapter' => 'generic_table',
            'table' => 'listings',
            'id_column' => 'id',
            'canonical_fields' => ['title', 'status', 'city'],
        ],
        'order' => [
            'adapter' => 'generic_table',
            'table' => 'orders',
            'id_column' => 'id',
            'canonical_fields' => ['order_number', 'status', 'total_amount', 'customer_id'],
        ],
    ],
];

