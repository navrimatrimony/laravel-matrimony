<?php

namespace App\Services\Gunamilan;

final class GunamilanReportTemplateRegistry
{
    public const DEFAULT = 'traditional';

    /**
     * @return array<int, array<string, string>>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'summary',
                'label' => __('profile.gunamilan_format_summary'),
                'description' => __('profile.gunamilan_format_summary_desc'),
                'view' => 'matrimony.profile.gunamilan-report-summary-a4',
            ],
            [
                'key' => self::DEFAULT,
                'label' => __('profile.gunamilan_format_traditional'),
                'description' => __('profile.gunamilan_format_traditional_desc'),
                'view' => 'matrimony.profile.gunamilan-report-a4',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function resolve(?string $key): array
    {
        $key = trim((string) $key) ?: self::DEFAULT;

        foreach ($this->all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return $this->resolve(self::DEFAULT);
    }
}
