<?php

/**
 * SSOT Day-27: Baseline OCR patterns for immediate intelligence.
 * These patterns are seeded into ocr_correction_patterns with source='frequency_rule'.
 * They work from day-1 before frequency-based learning kicks in.
 */

return [
    // Blood Group Normalization
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'O Positive',
        'corrected_value' => 'O+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'O+ve',
        'corrected_value' => 'O+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'O +',
        'corrected_value' => 'O+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'O+ve',
        'corrected_value' => 'O+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'A+ve',
        'corrected_value' => 'A+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'A +ve',
        'corrected_value' => 'A+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'A+',
        'corrected_value' => 'A+',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'B+ve',
        'corrected_value' => 'B+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'AB+Vc',
        'corrected_value' => 'AB+',
        'pattern_confidence' => 0.65,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'AB +ve',
        'corrected_value' => 'AB+',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'blood_group',
        'wrong_pattern' => 'AB+',
        'corrected_value' => 'AB+',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],

    // Height Normalization (common OCR errors)
    [
        'field_key' => 'height',
        'wrong_pattern' => '5.7 inch',
        'corrected_value' => "5'7\"",
        'pattern_confidence' => 0.65,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'height',
        'wrong_pattern' => '5 ft 7 in',
        'corrected_value' => "5'7\"",
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],

    // Date Normalization (common formats)
    [
        'field_key' => 'date_of_birth',
        'wrong_pattern' => '08-Aug 1983',
        'corrected_value' => '1983-08-08',
        'pattern_confidence' => 0.65,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'date_of_birth',
        'wrong_pattern' => '29-06-1992',
        'corrected_value' => '1992-06-29',
        'pattern_confidence' => 0.60,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'date_of_birth',
        'wrong_pattern' => '13/03/2001',
        'corrected_value' => '2001-03-13',
        'pattern_confidence' => 0.60,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],

    // Phone Normalization (common OCR errors)
    [
        'field_key' => 'primary_contact_number',
        'wrong_pattern' => '+91 98765 43210',
        'corrected_value' => '9876543210',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'primary_contact_number',
        'wrong_pattern' => '98765-43210',
        'corrected_value' => '9876543210',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],

    // Gender Normalization
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'male',
        'corrected_value' => 'Male',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'MALE',
        'corrected_value' => 'Male',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'M',
        'corrected_value' => 'Male',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'à¤ªà¥à¤°à¥à¤·',
        'corrected_value' => 'Male',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'female',
        'corrected_value' => 'Female',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'FEMALE',
        'corrected_value' => 'Female',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'F',
        'corrected_value' => 'Female',
        'pattern_confidence' => 0.70,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
    [
        'field_key' => 'gender',
        'wrong_pattern' => 'à¤¸à¥à¤¤à¥à¤°à¥€',
        'corrected_value' => 'Female',
        'pattern_confidence' => 0.75,
        'source' => 'frequency_rule',
        'is_active' => true,
    ],
];

