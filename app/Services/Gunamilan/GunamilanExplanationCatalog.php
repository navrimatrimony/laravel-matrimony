<?php

namespace App\Services\Gunamilan;

final class GunamilanExplanationCatalog
{
    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function enrich(array $result): array
    {
        $sections = array_map(
            fn (array $section): array => array_merge($section, $this->sectionCopy($section)),
            $result['sections'] ?? []
        );

        $total = (float) ($result['total_points'] ?? 0.0);
        $observations = $this->observations($sections, ! empty($result['missing_fields']));

        return [
            'score_band' => $this->scoreBand($total),
            'sections' => $sections,
            'observations' => $observations,
            'has_key_observations' => $observations !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, string>
     */
    private function sectionCopy(array $section): array
    {
        $key = (string) ($section['key'] ?? '');
        $ratio = $this->ratio($section);
        $status = (string) ($section['status'] ?? 'partial');

        return [
            'focus' => __('profile.gunamilan_focus_'.$key),
            'result_meaning' => $status === 'missing'
                ? __('profile.gunamilan_result_missing')
                : __('profile.gunamilan_result_'.$this->level($ratio)),
            'report_line' => $status === 'missing'
                ? __('profile.gunamilan_result_missing_short')
                : __('profile.gunamilan_report_'.$this->level($ratio)),
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function ratio(array $section): float
    {
        $max = (float) ($section['max_points'] ?? 0.0);
        if ($max <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) ($section['points'] ?? 0.0) / $max));
    }

    private function level(float $ratio): string
    {
        if ($ratio >= 0.9) {
            return 'high';
        }

        if ($ratio >= 0.45) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array<string, string>
     */
    private function scoreBand(float $total): array
    {
        if ($total >= 32.0) {
            return [
                'label' => __('profile.gunamilan_score_excellent'),
                'summary' => __('profile.gunamilan_score_excellent_summary'),
            ];
        }

        if ($total >= 24.0) {
            return [
                'label' => __('profile.gunamilan_score_good'),
                'summary' => __('profile.gunamilan_score_good_summary'),
            ];
        }

        if ($total >= 18.0) {
            return [
                'label' => __('profile.gunamilan_score_average'),
                'summary' => __('profile.gunamilan_score_average_summary'),
            ];
        }

        return [
            'label' => __('profile.gunamilan_score_review'),
            'summary' => __('profile.gunamilan_score_review_summary'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    private function observations(array $sections, bool $hasMissing): array
    {
        $out = [];

        foreach ($sections as $section) {
            $key = (string) ($section['key'] ?? '');
            $points = (float) ($section['points'] ?? 0.0);
            $status = (string) ($section['status'] ?? '');

            if ($status === 'missing') {
                continue;
            }

            if ($key === 'nadi' && $points <= 0.0) {
                $out[] = __('profile.gunamilan_observation_nadi');
            }

            if ($key === 'bhakoot' && $points <= 0.0) {
                $out[] = __('profile.gunamilan_observation_bhakoot');
            }

            if ($key === 'gana' && $points <= 1.0) {
                $out[] = __('profile.gunamilan_observation_gana');
            }
        }

        if ($hasMissing) {
            $out[] = __('profile.gunamilan_observation_missing');
        }

        return array_values(array_unique($out));
    }
}
