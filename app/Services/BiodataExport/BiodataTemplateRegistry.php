<?php

namespace App\Services\BiodataExport;

final class BiodataTemplateRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'classic_portrait_photo' => [
                'key' => 'classic_portrait_photo',
                'label' => 'Classic Portrait',
                'description' => 'A4 portrait with photo and clean border.',
                'orientation' => 'portrait',
                'border' => 'classic',
                'with_photo' => true,
                'premium' => false,
                'view' => 'biodata.templates.a4',
            ],
            'classic_portrait_no_photo' => [
                'key' => 'classic_portrait_no_photo',
                'label' => 'Classic No Photo',
                'description' => 'A4 portrait without photo.',
                'orientation' => 'portrait',
                'border' => 'classic',
                'with_photo' => false,
                'premium' => false,
                'view' => 'biodata.templates.a4',
            ],
            'parichay_patra_photo' => [
                'key' => 'parichay_patra_photo',
                'label' => 'Parichay Patra',
                'description' => 'Traditional A4 परिचय पत्र with decorative border and photo.',
                'orientation' => 'portrait',
                'border' => 'parichay',
                'with_photo' => true,
                'premium' => false,
                'view' => 'biodata.templates.parichay-patra',
            ],
            'photo_side_biodata' => [
                'key' => 'photo_side_biodata',
                'label' => 'Full Photo Side Biodata',
                'description' => 'A4 landscape biodata with a full-height photo side and compact details side.',
                'orientation' => 'landscape',
                'border' => 'photo-side',
                'with_photo' => true,
                'premium' => false,
                'view' => 'biodata.templates.photo-side',
            ],
            'simple_landscape_no_photo' => [
                'key' => 'simple_landscape_no_photo',
                'label' => 'Simple Landscape',
                'description' => 'A4 landscape without photo.',
                'orientation' => 'landscape',
                'border' => 'simple',
                'with_photo' => false,
                'premium' => false,
                'view' => 'biodata.templates.a4',
            ],
            'double_portrait_photo' => [
                'key' => 'double_portrait_photo',
                'label' => 'Double Border Portrait',
                'description' => 'A4 portrait with photo and double border.',
                'orientation' => 'portrait',
                'border' => 'double',
                'with_photo' => true,
                'premium' => true,
                'view' => 'biodata.templates.a4',
            ],
            'royal_landscape_photo' => [
                'key' => 'royal_landscape_photo',
                'label' => 'Royal Landscape',
                'description' => 'A4 landscape with photo and premium border.',
                'orientation' => 'landscape',
                'border' => 'royal',
                'with_photo' => true,
                'premium' => true,
                'view' => 'biodata.templates.a4',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $templates = $this->all();

        return $templates[$key] ?? null;
    }
}
