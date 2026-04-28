<?php

/**
 * Canonical religion labels (English + Marathi). Keys are stable slugs.
 *
 * Source of truth: keep aligned with admin `/admin/master/religions` after edits.
 * {@see ReligionCasteSubCasteSeeder} applies this after the caste hierarchy seed.
 * {@see App\Services\MasterData\MasterDataTranslationImportService} prepends these
 * rows on JSON import (JSON religion entries are ignored).
 * {@see App\Services\MasterData\ReligionCasteSubcasteTranslationJsonGenerator} uses
 * this for religion rows when regenerating translation JSON.
 *
 * @return array<string, array{label_en: string, label_mr: ?string}>
 */
return [
    'bahai' => ['label_en' => 'Bahai', 'label_mr' => 'बहाई'],
    'buddhist' => ['label_en' => 'Buddhist', 'label_mr' => 'बौद्ध'],
    'christian' => ['label_en' => 'Christian', 'label_mr' => 'ख्रिश्चन'],
    'hindu' => ['label_en' => 'Hindu', 'label_mr' => 'हिंदू'],
    'jain' => ['label_en' => 'Jain', 'label_mr' => 'जैन'],
    'jewish' => ['label_en' => 'Jewish', 'label_mr' => 'ज्यू'],
    'muslim' => ['label_en' => 'Muslim', 'label_mr' => 'मुस्लिम'],
    'no-religion' => ['label_en' => 'No Religion', 'label_mr' => 'कोणताही धर्म नाही'],
    'parsi' => ['label_en' => 'Parsi', 'label_mr' => 'पारशी'],
    'sikh' => ['label_en' => 'Sikh', 'label_mr' => 'शीख'],
    'spiritual-not-religious' => ['label_en' => 'Spiritual - not religious', 'label_mr' => 'आध्यात्मिक (धार्मिक नाही)'],
    'tribal' => ['label_en' => 'Tribal', 'label_mr' => 'आदिवासी'],
    'zoroastrian' => ['label_en' => 'Zoroastrian', 'label_mr' => 'झोरोस्ट्रियन'],
];
