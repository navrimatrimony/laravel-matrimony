<?php

return [
    'section_title' => 'Showcase photo pool (strict matching)',
    'section_help' => 'Photos are chosen only from uploads/matrimony_photos/eng/{gender}/{religion}/{marital_status}/{age_bucket}. No any/any/any or gender-only fallbacks.',
    'missing_folder_label' => 'When exact folder is missing or category is incomplete',
    'pool_exhausted_label' => 'When folder exists but all unused images are taken',
    'action_create_without_photo' => 'Create profile without photo (warning on bulk page)',
    'action_skip_profile' => 'Skip profile (do not create)',
    'allow_reuse_label' => 'Allow reusing an image from the same bucket when all unused files are exhausted',
    'allow_reuse_help' => 'Only applies within the exact eng/…/age_bucket folder. Does not search other folders.',
    'reason_missing_folder' => 'No photo folder for this category',
    'reason_pool_exhausted' => 'All photos in this bucket are already used',
    'reason_invalid_category' => 'Could not build photo category (religion, marital status, or age)',
];
