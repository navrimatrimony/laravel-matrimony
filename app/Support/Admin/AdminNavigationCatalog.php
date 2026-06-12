<?php

namespace App\Support\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class AdminNavigationCatalog
{
    /**
     * @return array<string, array{label: string, description: string, tabs: array<int, array<string, mixed>>, covered_patterns?: array<int, string>}>
     */
    public static function modules(): array
    {
        return [
            AdminNavigationAccess::COMMAND_CENTER => [
                'label' => 'Command Center',
                'description' => 'Dashboard, live queues, risk, activity, and operations health.',
                'tabs' => [
                    self::tab('Overview', 'admin.dashboard', ['admin.dashboard', 'admin.dashboard-metrics.*']),
                    self::tab('Monitoring', 'admin.monitoring.index', ['admin.monitoring.*']),
                ],
            ],
            AdminNavigationAccess::MEMBERS => [
                'label' => 'Members',
                'description' => 'Member records, profile actions, duplicate phones, plans, and KYC context.',
                'tabs' => [
                    self::tab('All Profiles', 'admin.profiles.index', ['admin.profiles.*', 'admin.profile-photos.*', 'admin.profiles.kyc.*']),
                    self::tab('Duplicate Phones', 'admin.duplicate-phones.index', ['admin.duplicate-phones.*']),
                ],
                'covered_patterns' => [
                    'admin.users.plan',
                ],
            ],
            AdminNavigationAccess::INTAKE_OCR => [
                'label' => 'Intake & OCR',
                'description' => 'Biodata entry, intake review, OCR simulation, parser rules, and intake settings.',
                'tabs' => [
                    self::tab('Biodata Intake', 'admin.biodata-intakes.index', ['admin.biodata-intakes.*', 'admin.suggestions.review*']),
                    self::tab('Review Queue', 'admin.intake.index', ['admin.intake.*']),
                    self::tab('OCR Simulation', 'admin.ocr-simulation.index', ['admin.ocr-simulation.*']),
                    self::tab('OCR Rules', 'admin.ocr-patterns.index', ['admin.ocr-patterns.*']),
                    self::tab('Intake Settings', 'admin.intake-settings.index', ['admin.intake-settings.*']),
                ],
            ],
            AdminNavigationAccess::TRUST_SAFETY => [
                'label' => 'Trust & Safety',
                'description' => 'Moderation, reports, conflicts, communication controls, and support tickets.',
                'tabs' => [
                    self::tab('Photo Moderation', 'admin.photo-moderation.index', ['admin.photo-moderation.*']),
                    self::tab('Abuse Reports', 'admin.abuse-reports.index', ['admin.abuse-reports.*']),
                    self::tab('Conflict Records', 'admin.conflict-records.index', ['admin.conflict-records.*']),
                    self::tab('Moderation Learning', 'admin.moderation-learning.index', ['admin.moderation-learning.*']),
                    self::tab('Photo Rules', 'admin.photo-approval-settings.index', ['admin.photo-approval-settings.*']),
                    self::tab('Moderation Engine', 'admin.moderation-engine-settings.index', ['admin.moderation-engine-settings.*']),
                    self::tab('Communication Policy', 'admin.communication-policy.index', ['admin.communication-policy.*']),
                    self::tab('WhatsApp Response', 'admin.whatsapp-response.index', ['admin.whatsapp-response.*']),
                    self::tab('Help Tickets', 'admin.help-centre.tickets.index', ['admin.help-centre.tickets.*']),
                ],
            ],
            AdminNavigationAccess::MATCHING_DISCOVERY => [
                'label' => 'Matching & Discovery',
                'description' => 'Matching rules, scoring, filters, AI suggestions, previews, and boosts.',
                'tabs' => [
                    self::tab('Overview', 'admin.matching-engine.overview', ['admin.matching-engine.overview']),
                    self::tab('Fields & Scoring', 'admin.matching-engine.fields', ['admin.matching-engine.fields*']),
                    self::tab('Hard Filters', 'admin.matching-engine.filters', ['admin.matching-engine.filters*']),
                    self::tab('Behavior', 'admin.matching-engine.behavior', ['admin.matching-engine.behavior*']),
                    self::tab('Boost Rules', 'admin.matching-engine.boosts', ['admin.matching-engine.boosts*']),
                    self::tab('AI Suggestions', 'admin.matching-engine.ai', ['admin.matching-engine.ai']),
                    self::tab('Live Preview', 'admin.matching-engine.preview', ['admin.matching-engine.preview']),
                    self::tab('Audit Log', 'admin.matching-engine.audit', ['admin.matching-engine.audit*']),
                    self::tab('Match Boost', 'admin.match-boost.edit', ['admin.match-boost.*']),
                ],
            ],
            AdminNavigationAccess::SHOWCASE_ENGINE => [
                'label' => 'Showcase Engine',
                'description' => 'Showcase automation, conversations, photo pool, rules, and bulk profiles.',
                'tabs' => [
                    self::tab('Activity', 'admin.showcase-dashboard.index', ['admin.showcase.index', 'admin.showcase-dashboard.*']),
                    self::tab('Member Search', 'admin.showcase-search-settings.index', ['admin.showcase-search-settings.*']),
                    self::tab('View-back', 'admin.view-back-settings.index', ['admin.view-back-settings.*']),
                    self::tab('Interest Rules', 'admin.showcase-interest-settings.index', ['admin.showcase-interest-settings.*']),
                    self::tab('Chat Automation', 'admin.showcase-chat-settings.index', ['admin.showcase-chat-settings.*']),
                    self::tab('Conversations', 'admin.showcase-conversations.index', ['admin.showcase-conversations.*', 'admin.showcase-chat.debug']),
                    self::tab('Photo Pool', 'admin.showcase-photo-pool.index', ['admin.showcase-photo-pool.*']),
                    self::tab('Bulk Profiles', 'admin.showcase-profile.bulk-create', ['admin.showcase-profile.*']),
                    self::tab('Auto-showcase', 'admin.auto-showcase-settings.edit', ['admin.auto-showcase-settings.*']),
                ],
            ],
            AdminNavigationAccess::SUCHAK_NETWORK => [
                'label' => 'Suchak Network',
                'description' => 'Suchak accounts, safety, plans, payouts, retention, academy, and settings.',
                'tabs' => [
                    self::tab('Dashboard', 'admin.suchak.dashboard', ['admin.suchak.dashboard']),
                    self::tab('Accounts', 'admin.suchak.accounts.index', ['admin.suchak.accounts.*']),
                    self::tab('Safety', 'admin.suchak.safety.index', ['admin.suchak.safety.*']),
                    self::tab('Plans', 'admin.suchak.plans.index', ['admin.suchak.plans.*']),
                    self::tab('Payouts', 'admin.suchak.payouts.index', ['admin.suchak.payouts.*']),
                    self::tab('Retention', 'admin.suchak.retention.index', ['admin.suchak.retention.*']),
                    self::tab('Academy', 'admin.suchak.academy.index', ['admin.suchak.academy.*']),
                    self::tab('Settings', 'admin.suchak.settings.index', ['admin.suchak.settings.*']),
                ],
            ],
            AdminNavigationAccess::COMMERCE => [
                'label' => 'Commerce',
                'description' => 'Revenue, plans, coupons, wallets, boosts, referrals, payments, and overrides.',
                'tabs' => [
                    self::tab('Revenue', 'admin.revenue.index', ['admin.revenue.*']),
                    self::tab('Plans', 'admin.plans.index', ['admin.plans.*']),
                    self::tab('Coupons', 'admin.coupons.index', ['admin.coupons.*']),
                    self::tab('Commerce Coupons', 'admin.commerce.coupons.index', ['admin.commerce.coupons.*']),
                    self::tab('Wallets', 'admin.wallets.index', ['admin.wallets.*']),
                    self::tab('Boost Purchases', 'admin.boosts.index', ['admin.boosts.*']),
                    self::tab('Referrals', 'admin.referrals.index', ['admin.referrals.*']),
                    self::tab('Payments', 'admin.payments.index', ['admin.payments.*', 'admin.disputes.*', 'admin.payment-disputes.*']),
                    self::tab('Analytics', 'admin.commerce.analytics.index', ['admin.commerce.analytics.*']),
                    self::tab('Overrides', 'admin.commerce.overrides.index', ['admin.commerce.overrides.*']),
                ],
            ],
            AdminNavigationAccess::DATA_GOVERNANCE => [
                'label' => 'Data Governance',
                'description' => 'Governance dashboard, data engine, field registry, rollback, and profile governance.',
                'tabs' => [
                    self::tab('Governance Dashboard', 'admin.governance-dashboard', ['admin.governance-dashboard']),
                    self::tab('Data Engine', 'admin.data-engine.index', ['admin.data-engine.index', 'admin.data-engine.status', 'admin.data-engine.live-status', 'admin.data-engine.show', 'admin.data-engine.download']),
                    self::tab('Comparisons', 'admin.data-engine.comparisons', ['admin.data-engine.comparisons']),
                    self::tab('Issues', 'admin.data-engine.issues', ['admin.data-engine.issues']),
                    self::tab('Workflows', 'admin.data-engine.workflows', ['admin.data-engine.workflows']),
                    self::tab('Audit Trail', 'admin.data-engine.audit', ['admin.data-engine.audit']),
                    self::tab('Rollback Center', 'admin.data-engine.rollback', ['admin.data-engine.rollback']),
                    self::tab('System Health', 'admin.data-engine.system-health', ['admin.data-engine.system-health']),
                    self::tab('Data Lineage', 'admin.data-engine.data-lineage', ['admin.data-engine.data-lineage']),
                    self::tab('Data Integrity', 'admin.data-engine.data-integrity', ['admin.data-engine.data-integrity']),
                    self::tab('Marathi Reports', 'admin.data-engine.marathi-columns', ['admin.data-engine.marathi-columns', 'admin.data-engine.mr-fill.*']),
                    self::tab('Profile Governance', 'admin.governance-dashboard', ['admin.data-engine.profiles.show', 'admin.governance.profiles.*']),
                    self::tab('Profile Fields', 'admin.profile-field-config.index', ['admin.profile-field-config.*']),
                    self::tab('Field Registry', 'admin.field-registry.index', ['admin.field-registry.index']),
                    self::tab('Extended Fields', 'admin.field-registry.extended.index', ['admin.field-registry.extended.*']),
                    self::tab('Verification Tags', 'admin.verification-tags.index', ['admin.verification-tags.*'], 'can_manage_verification_tags'),
                    self::tab('Serious Intents', 'admin.serious-intents.index', ['admin.serious-intents.*'], 'can_manage_serious_intents'),
                ],
            ],
            AdminNavigationAccess::MASTER_DATA => [
                'label' => 'Master Data',
                'description' => 'Religions, castes, education, occupation, canonical locations, and suggestions.',
                'tabs' => [
                    self::tab('Religions', 'admin.master.religions.index', ['admin.master.religions.*']),
                    self::tab('Castes', 'admin.master.castes.index', ['admin.master.castes.*']),
                    self::tab('Sub-castes', 'admin.master.sub-castes.index', ['admin.master.sub-castes.*']),
                    self::tab('Education', 'admin.master.education.index', ['admin.master.education.*']),
                    self::tab('Occupation', 'admin.master.occupation.index', ['admin.master.occupation.*']),
                    self::tab('Education & Occupation', 'admin.master.education-occupation.index', ['admin.master.education-occupation.*']),
                    self::tab('Locations', 'admin.locations.index', ['admin.locations.*']),
                    self::tab('Location Suggestions', 'admin.location-suggestions.index', ['admin.location-suggestions.*']),
                    self::tab('Open Place Suggestions', 'admin.open-place-suggestions.index', ['admin.open-place-suggestions.*']),
                ],
            ],
            AdminNavigationAccess::SITE_EXPERIENCE => [
                'label' => 'Site & Experience',
                'description' => 'Branding, app settings, homepage, notifications, verification, teasers, and translations.',
                'tabs' => [
                    self::tab('App Settings', 'admin.app-settings.index', ['admin.app-settings.*'], null, [], ['tab' => 'notifications']),
                    self::tab('Notifications', 'admin.app-settings.index', ['admin.app-settings.*'], null, ['tab' => 'notifications']),
                    self::tab('Notification Debug', 'admin.notifications.index', ['admin.notifications.*']),
                    self::tab('Teaser Cards', 'admin.teaser-settings.index', ['admin.teaser-settings.*', 'admin.who-viewed-teaser-settings.*']),
                    self::tab('Mobile Verification', 'admin.mobile-verification-settings.index', ['admin.mobile-verification-settings.*']),
                    self::tab('Homepage', 'admin.homepage-settings.index', ['admin.homepage-settings.*', 'admin.homepage-images.*']),
                    self::tab('Translations', 'admin.translations.index', ['admin.translations.*']),
                ],
            ],
            AdminNavigationAccess::SYSTEM_ACCESS => [
                'label' => 'System & Access',
                'description' => 'Admin access controls and system administration surfaces.',
                'tabs' => [
                    self::tab('Admin Capabilities', 'admin.admin-capabilities.index', ['admin.admin-capabilities.*'], 'is_super_admin'),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, bool>  $access
     * @param  array<string, bool>  $abilities
     * @return array{section_key: string, section_label: string, section_description: string, active_tab_label: string|null, tabs: array<int, array<string, mixed>>}|null
     */
    public static function forRequest(Request $request, array $access, array $abilities = []): ?array
    {
        return self::forRouteName(
            (string) ($request->route()?->getName() ?? ''),
            $access,
            $abilities,
            $request->query()
        );
    }

    /**
     * @param  array<string, bool>  $access
     * @param  array<string, bool>  $abilities
     * @param  array<string, mixed>  $query
     * @return array{section_key: string, section_label: string, section_description: string, active_tab_label: string|null, tabs: array<int, array<string, mixed>>}|null
     */
    public static function forRouteName(string $routeName, array $access, array $abilities = [], array $query = []): ?array
    {
        if ($routeName === '') {
            return null;
        }

        foreach (self::modules() as $sectionKey => $module) {
            if (! (bool) ($access[$sectionKey] ?? false)) {
                continue;
            }

            if (! self::moduleCoversRoute($module, $routeName)) {
                continue;
            }

            $tabs = self::visibleTabs($module['tabs'], $abilities, $routeName, $query);

            return [
                'section_key' => $sectionKey,
                'section_label' => $module['label'],
                'section_description' => $module['description'],
                'active_tab_label' => collect($tabs)->firstWhere('active', true)['label'] ?? null,
                'tabs' => $tabs,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, bool>  $access
     * @param  array<string, bool>  $abilities
     * @param  array<string, mixed>  $query
     * @return array<int, array{section_key: string, label: string, description: string, href: string, active: bool, tool_count: int}>
     */
    public static function navigationSections(array $access, array $abilities = [], string $routeName = '', array $query = []): array
    {
        $sections = [];

        foreach (self::modules() as $sectionKey => $module) {
            if (! (bool) ($access[$sectionKey] ?? false)) {
                continue;
            }

            $tabs = self::visibleTabs($module['tabs'], $abilities, $routeName, $query);
            if ($tabs === []) {
                continue;
            }

            $sections[] = [
                'section_key' => $sectionKey,
                'label' => $module['label'],
                'description' => $module['description'],
                'href' => $tabs[0]['href'],
                'active' => $routeName !== '' && self::moduleCoversRoute($module, $routeName),
                'tool_count' => count($tabs),
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string, bool>  $access
     * @param  array<string, bool>  $abilities
     * @return array<int, array{label: string, href: string, group: string}>
     */
    public static function commandItems(array $access, array $abilities = []): array
    {
        $items = [];
        $seen = [];

        foreach (self::modules() as $sectionKey => $module) {
            if (! (bool) ($access[$sectionKey] ?? false)) {
                continue;
            }

            foreach (self::visibleTabs($module['tabs'], $abilities, '', []) as $tab) {
                if (isset($seen[$tab['href']])) {
                    continue;
                }

                $seen[$tab['href']] = true;
                $items[] = [
                    'label' => $module['label'].' / '.$tab['label'],
                    'href' => $tab['href'],
                    'group' => $module['label'],
                ];
            }
        }

        return $items;
    }

    public static function sectionForRouteName(string $routeName): ?string
    {
        if ($routeName === '') {
            return null;
        }

        foreach (self::modules() as $sectionKey => $module) {
            if (self::moduleCoversRoute($module, $routeName)) {
                return $sectionKey;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $routeNames
     * @param  array<int, string>  $ignoredPatterns
     * @return array<int, string>
     */
    public static function unmappedRouteNames(array $routeNames, array $ignoredPatterns = []): array
    {
        $coveredPatterns = self::coveredRoutePatterns();

        return collect($routeNames)
            ->reject(fn (string $routeName): bool => self::matchesAny($routeName, $ignoredPatterns))
            ->reject(fn (string $routeName): bool => self::matchesAny($routeName, $coveredPatterns))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function coveredRoutePatterns(): array
    {
        $patterns = [];

        foreach (self::modules() as $module) {
            foreach ($module['tabs'] as $tab) {
                foreach ($tab['patterns'] as $pattern) {
                    $patterns[] = $pattern;
                }
            }

            foreach ($module['covered_patterns'] ?? [] as $pattern) {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }

    /**
     * @param  array<string, mixed>  $module
     */
    private static function moduleCoversRoute(array $module, string $routeName): bool
    {
        $patterns = $module['covered_patterns'] ?? [];

        foreach ($module['tabs'] as $tab) {
            $patterns = array_merge($patterns, $tab['patterns']);
        }

        return self::matchesAny($routeName, $patterns);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tabs
     * @param  array<string, bool>  $abilities
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private static function visibleTabs(array $tabs, array $abilities, string $routeName, array $query): array
    {
        return collect($tabs)
            ->filter(fn (array $tab): bool => self::tabIsAllowed($tab, $abilities))
            ->filter(fn (array $tab): bool => is_string($tab['route']) && Route::has($tab['route']))
            ->map(function (array $tab) use ($routeName, $query): array {
                $href = route($tab['route'], $tab['parameters'] ?? []);
                if (! empty($tab['query'])) {
                    $href .= '?'.http_build_query($tab['query']);
                }

                return [
                    'label' => $tab['label'],
                    'href' => $href,
                    'active' => self::tabIsActive($tab, $routeName, $query),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $tab
     * @param  array<string, bool>  $abilities
     */
    private static function tabIsAllowed(array $tab, array $abilities): bool
    {
        $ability = $tab['ability'] ?? null;

        return ! is_string($ability) || (bool) ($abilities[$ability] ?? false);
    }

    /**
     * @param  array<string, mixed>  $tab
     * @param  array<string, mixed>  $query
     */
    private static function tabIsActive(array $tab, string $routeName, array $query): bool
    {
        if (! self::matchesAny($routeName, $tab['patterns'])) {
            return false;
        }

        foreach ($tab['active_query'] ?? [] as $key => $value) {
            if ((string) ($query[$key] ?? '') !== (string) $value) {
                return false;
            }
        }

        foreach ($tab['inactive_query'] ?? [] as $key => $value) {
            if ((string) ($query[$key] ?? '') === (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private static function matchesAny(string $routeName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $activeQuery
     * @param  array<string, mixed>  $inactiveQuery
     * @return array<string, mixed>
     */
    private static function tab(string $label, string $route, array $patterns, ?string $ability = null, array $activeQuery = [], array $inactiveQuery = []): array
    {
        $tab = [
            'label' => $label,
            'route' => $route,
            'patterns' => $patterns,
        ];

        if ($ability !== null) {
            $tab['ability'] = $ability;
        }

        if ($activeQuery !== []) {
            $tab['query'] = $activeQuery;
            $tab['active_query'] = $activeQuery;
        }

        if ($inactiveQuery !== []) {
            $tab['inactive_query'] = $inactiveQuery;
        }

        return $tab;
    }
}
