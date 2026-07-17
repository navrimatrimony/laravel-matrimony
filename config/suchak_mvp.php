<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suchak MVP surface controls
    |--------------------------------------------------------------------------
    |
    | Hide future or low-priority modules from navigation while keeping routes,
    | services, and business logic available for direct access and tests.
    |
    */

    'nav' => [
        'network' => false,
        'tools' => false,
    ],

    'nav_subitems' => [
        'collaborations' => true,
        'offline_camps' => false,
        'export_retention' => false,
        'training_academy' => false,
    ],

    'dashboard_tabs' => [
        'profile' => true,
        'work' => true,
        'profiles' => true,
        'requests' => true,
        'money' => true,
        'sharing' => false,
        'records' => true,
    ],

    'dashboard_panels' => [
        'workflow_whatsapp_templates' => false,
        'white_label_kit' => false,
    ],

    'admin_links' => [
        'retention' => false,
        'academy' => false,
        'payouts' => true,
    ],

];
