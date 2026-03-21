<?php

namespace App\Services;

use App\Models\City;

/**
 * Normalizes wizard / full-form career_history[] rows: location text + optional city_id hierarchy.
 */
class CareerHistoryRowNormalizer
{
    /**
     * Skip rows the user never filled (wizard may render an empty template row).
     * Rows with an existing DB id are never treated as blank so updates/clears still apply.
     *
     * @param  array<string, mixed>  $row
     */
    public static function isBlankRequestRow(array $row): bool
    {
        if (! empty($row['id'])) {
            return false;
        }
        $d = trim((string) ($row['designation'] ?? ''));
        $c = trim((string) ($row['company'] ?? ''));
        $loc = trim((string) ($row['location'] ?? ''));
        $cityId = $row['city_id'] ?? null;
        $hasCity = $cityId !== null && $cityId !== '' && is_numeric($cityId) && (int) $cityId > 0;
        $sy = $row['start_year'] ?? '';
        $ey = $row['end_year'] ?? '';
        $hasYear = ($sy !== '' && $sy !== null) || ($ey !== '' && $ey !== null);
        $current = isset($row['is_current']) && (string) $row['is_current'] === '1';

        return $d === '' && $c === '' && $loc === '' && ! $hasCity && ! $hasYear && ! $current;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    public static function fromRequestRowOrNull(array $row): ?array
    {
        if (self::isBlankRequestRow($row)) {
            return null;
        }

        return self::fromRequestRow($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function fromRequestRow(array $row): array
    {
        $cityId = ! empty($row['city_id']) && is_numeric($row['city_id']) ? (int) $row['city_id'] : null;
        $location = trim((string) ($row['location'] ?? ''));
        if ($location === '' && $cityId !== null) {
            $location = self::lineForCityId($cityId) ?? '';
        }

        return [
            'id' => ! empty($row['id']) ? (int) $row['id'] : null,
            'designation' => trim((string) ($row['designation'] ?? '')),
            'company' => trim((string) ($row['company'] ?? '')),
            'location' => $location !== '' ? $location : null,
            'city_id' => $cityId,
            'start_year' => ! empty($row['start_year']) && is_numeric($row['start_year']) ? (int) $row['start_year'] : null,
            'end_year' => ! empty($row['end_year']) && is_numeric($row['end_year']) ? (int) $row['end_year'] : null,
            'is_current' => isset($row['is_current']) && (string) $row['is_current'] === '1',
        ];
    }

    public static function lineForCityId(int $cityId): ?string
    {
        if ($cityId <= 0) {
            return null;
        }
        $city = City::query()->with(['taluka.district.state'])->find($cityId);
        if (! $city) {
            return null;
        }
        $parts = array_filter([
            $city->name,
            $city->taluka?->name,
            $city->taluka?->district?->name,
            $city->taluka?->district?->state?->name,
        ]);

        return $parts !== [] ? implode(', ', $parts) : $city->name;
    }
}
