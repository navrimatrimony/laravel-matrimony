<?php

namespace App\Support\Admin;

use App\Models\User;
use Illuminate\Http\Request;

final class AdminNavigationAccess
{
    public const COMMAND_CENTER = 'command_center';
    public const MEMBERS = 'members';
    public const INTAKE_OCR = 'intake_ocr';
    public const TRUST_SAFETY = 'trust_safety';
    public const MATCHING_DISCOVERY = 'matching_discovery';
    public const SHOWCASE_ENGINE = 'showcase_engine';
    public const SUCHAK_NETWORK = 'suchak_network';
    public const COMMERCE = 'commerce';
    public const DATA_GOVERNANCE = 'data_governance';
    public const MASTER_DATA = 'master_data';
    public const SITE_EXPERIENCE = 'site_experience';
    public const SYSTEM_ACCESS = 'system_access';

    /**
     * @return array<string, array{column: string, label: string, description: string}>
     */
    public static function sections(): array
    {
        return [
            self::COMMAND_CENTER => [
                'column' => 'can_access_command_center',
                'label' => 'Command Center',
                'description' => 'Dashboard, today queues, alerts, activity, monitoring.',
            ],
            self::MEMBERS => [
                'column' => 'can_access_members',
                'label' => 'Members',
                'description' => 'Profiles, profile actions, duplicate phones, KYC/photo context.',
            ],
            self::INTAKE_OCR => [
                'column' => 'can_access_intake_ocr',
                'label' => 'Intake & OCR',
                'description' => 'Biodata intake, parse review, OCR rules, intake settings.',
            ],
            self::TRUST_SAFETY => [
                'column' => 'can_access_trust_safety',
                'label' => 'Trust & Safety',
                'description' => 'Moderation, reports, conflicts, communication controls.',
            ],
            self::MATCHING_DISCOVERY => [
                'column' => 'can_access_matching_discovery',
                'label' => 'Matching & Discovery',
                'description' => 'Matching engine, filters, boosts, AI suggestions, preview.',
            ],
            self::SHOWCASE_ENGINE => [
                'column' => 'can_access_showcase_engine',
                'label' => 'Showcase Engine',
                'description' => 'Showcase activity, automation, photo pool, bulk profiles.',
            ],
            self::SUCHAK_NETWORK => [
                'column' => 'can_access_suchak_network',
                'label' => 'Suchak Network',
                'description' => 'Suchak accounts, safety, plans, payouts, academy, settings.',
            ],
            self::COMMERCE => [
                'column' => 'can_access_commerce',
                'label' => 'Commerce',
                'description' => 'Plans, coupons, wallets, referrals, payments, disputes.',
            ],
            self::DATA_GOVERNANCE => [
                'column' => 'can_access_data_governance',
                'label' => 'Data Governance',
                'description' => 'Governance dashboard, data engine, field registry, rollback.',
            ],
            self::MASTER_DATA => [
                'column' => 'can_access_master_data',
                'label' => 'Master Data',
                'description' => 'Religions, castes, education, occupation, locations.',
            ],
            self::SITE_EXPERIENCE => [
                'column' => 'can_access_site_experience',
                'label' => 'Site & Experience',
                'description' => 'App settings, homepage, notifications, translations.',
            ],
            self::SYSTEM_ACCESS => [
                'column' => 'can_access_system_access',
                'label' => 'System & Access',
                'description' => 'Admin capabilities, monitoring, access administration.',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function columns(): array
    {
        return collect(self::sections())
            ->mapWithKeys(fn (array $section, string $key): array => [$key => $section['column']])
            ->all();
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultAccessFor(?User $admin): array
    {
        $access = array_fill_keys(array_keys(self::sections()), false);

        if (! $admin || ! $admin->isAnyAdmin()) {
            return $access;
        }

        $access[self::COMMAND_CENTER] = true;

        if ($admin->isSuperAdmin()) {
            return array_fill_keys(array_keys(self::sections()), true);
        }

        if ($admin->isDataAdmin()) {
            foreach ([
                self::MEMBERS,
                self::INTAKE_OCR,
                self::TRUST_SAFETY,
                self::MATCHING_DISCOVERY,
                self::DATA_GOVERNANCE,
                self::MASTER_DATA,
            ] as $section) {
                $access[$section] = true;
            }
        }

        if ($admin->isAuditor()) {
            foreach ([self::TRUST_SAFETY, self::DATA_GOVERNANCE] as $section) {
                $access[$section] = true;
            }
        }

        if ($admin->admin_role === null && $admin->is_admin === true) {
            $access[self::SYSTEM_ACCESS] = true;
        }

        return $access;
    }

    /**
     * @return array<string, bool>
     */
    public static function accessFor(?User $admin, ?object $capabilities): array
    {
        if (! $admin || ! $admin->isAnyAdmin()) {
            return self::defaultAccessFor(null);
        }

        if ($admin->isSuperAdmin()) {
            return self::defaultAccessFor($admin);
        }

        $access = self::defaultAccessFor($admin);
        foreach (self::columns() as $section => $column) {
            if ($capabilities && property_exists($capabilities, $column)) {
                $access[$section] = (bool) $capabilities->{$column};
            }
        }

        return $access;
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultCapabilityAttributesFor(User $admin): array
    {
        $defaults = [];
        $access = self::defaultAccessFor($admin);

        foreach (self::columns() as $section => $column) {
            $defaults[$column] = (bool) ($access[$section] ?? false);
        }

        return $defaults;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function requestRules(): array
    {
        $rules = [];
        foreach (self::columns() as $column) {
            $rules[$column] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, bool>
     */
    public static function requestAttributes(Request $request): array
    {
        $attributes = [];
        foreach (self::columns() as $column) {
            $attributes[$column] = $request->boolean($column);
        }

        return $attributes;
    }
}
