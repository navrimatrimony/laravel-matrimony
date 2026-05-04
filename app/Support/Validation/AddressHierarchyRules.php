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
        return Rule::exists('addresses', 'id')->where('type', 'country');
    }

    public static function existsStateId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('type', 'state');
    }

    public static function existsDistrictId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('type', 'district');
    }

    public static function existsTalukaId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('type', 'taluka');
    }

    public static function existsCityId(): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('addresses', 'id')->where('type', 'city');
    }
}
