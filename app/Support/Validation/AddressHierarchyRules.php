<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Illuminate\Validation\Rule;

/**
 * Validation rules for rows in {@code addresses} (geo SSOT). Hierarchy links use {@code parent_id} only.
 */
final class AddressHierarchyRules
{
    public static function existsCountryId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('hierarchy', 'country');
    }

    public static function existsStateId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('hierarchy', 'state');
    }

    public static function existsDistrictId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('hierarchy', 'district');
    }

    public static function existsTalukaId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('hierarchy', 'taluka');
    }

    public static function existsCityId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')
            ->where('hierarchy', 'village')
            ->where('tag', 'city');
    }

    /**
     * Canonical place leaf id from geo SSOT. Used where UI stores any leaf hierarchy
     * (city/town/village) under legacy-named fields like {@code work_city_id}.
     */
    public static function existsLocationLeafId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id');
    }
}
