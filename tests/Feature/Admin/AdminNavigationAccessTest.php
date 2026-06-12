<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Admin\AdminNavigationAccess;
use App\Support\Admin\AdminNavigationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNavigationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_every_major_admin_section(): void
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $response = $this->actingAs($superAdmin)->get(route('admin.dashboard'));

        $response->assertOk();

        $nav = $this->navHtml($response->getContent());

        $this->assertStringContainsString('Command Center', $nav);
        $this->assertStringContainsString('Members', $nav);
        $this->assertStringContainsString('Suchak Network', $nav);
        $this->assertStringContainsString('Showcase Engine', $nav);
        $this->assertStringContainsString('Master Data', $nav);
        $this->assertStringContainsString('System &amp; Access', $nav);
    }

    public function test_non_super_admin_only_sees_allowed_navigation_sections(): void
    {
        $admin = User::factory()->create([
            'is_admin' => false,
            'admin_role' => 'data_admin',
        ]);

        DB::table('admin_capabilities')->insert([
            'admin_id' => $admin->id,
            'can_manage_verification_tags' => false,
            'can_manage_serious_intents' => false,
            'can_access_command_center' => true,
            'can_access_members' => false,
            'can_access_intake_ocr' => true,
            'can_access_trust_safety' => false,
            'can_access_matching_discovery' => false,
            'can_access_showcase_engine' => false,
            'can_access_suchak_network' => false,
            'can_access_commerce' => false,
            'can_access_data_governance' => false,
            'can_access_master_data' => false,
            'can_access_site_experience' => false,
            'can_access_system_access' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();

        $nav = $this->navHtml($response->getContent());

        $this->assertStringContainsString('Command Center', $nav);
        $this->assertStringContainsString('Intake &amp; OCR', $nav);
        $this->assertStringNotContainsString('Members', $nav);
        $this->assertStringNotContainsString('Suchak Network', $nav);
        $this->assertStringNotContainsString('Showcase Engine', $nav);
        $this->assertStringNotContainsString('Master Data', $nav);
        $this->assertStringNotContainsString('Matching &amp; Discovery', $nav);
        $this->assertStringNotContainsString('Trust &amp; Safety', $nav);
    }

    public function test_super_admin_can_update_non_super_admin_navigation_access(): void
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        $admin = User::factory()->create([
            'is_admin' => false,
            'admin_role' => 'auditor',
        ]);

        $this->actingAs($superAdmin)
            ->post(route('admin.admin-capabilities.update', $admin), [
                'can_access_command_center' => '1',
                'can_access_trust_safety' => '1',
                'can_access_system_access' => '1',
            ])
            ->assertRedirect(route('admin.admin-capabilities.index'));

        $this->assertDatabaseHas('admin_capabilities', [
            'admin_id' => $admin->id,
            'can_access_command_center' => true,
            'can_access_trust_safety' => true,
            'can_access_system_access' => true,
            'can_access_suchak_network' => false,
        ]);
    }

    public function test_admin_capabilities_page_renders_section_presets_for_super_admin(): void
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);
        User::factory()->create([
            'is_admin' => false,
            'admin_role' => 'data_admin',
        ]);

        $response = $this->actingAs($superAdmin)->get(route('admin.admin-capabilities.index'));

        $response->assertOk();
        $response->assertSee('data-admin-capability-editor="editable"', false);
        $response->assertSee('data-admin-section-count', false);
        $response->assertSee('data-admin-section-presets', false);
        $response->assertSee('data-admin-section-preset="role-default"', false);
        $response->assertSee('data-admin-section-preset="data-admin"', false);
        $response->assertSee('data-admin-section-preset="auditor"', false);
        $response->assertSee('data-admin-section-preset="command-center-only"', false);
        $response->assertSee('data-admin-section-preset="all"', false);
        $response->assertSee('Hidden rule: admins only see selected sections.', false);
    }

    public function test_admin_page_routes_are_mapped_to_navigation_catalog(): void
    {
        $routeNames = collect(Route::getRoutes())
            ->filter(fn ($route): bool => in_array('GET', $route->methods(), true))
            ->filter(fn ($route): bool => Str::startsWith((string) $route->uri(), 'admin'))
            ->map(fn ($route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => is_string($name) && Str::startsWith($name, 'admin.') && $name !== 'admin.')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $unmapped = AdminNavigationCatalog::unmappedRouteNames($routeNames, [
            'admin.biodata-intakes.parse-status',
            'admin.data-engine.download',
            'admin.payments.invoice.pdf',
            'admin.photo-moderation.panel',
            'admin.photo-moderation.preview',
            'admin.profiles.kyc.file',
            'admin.referrals.export',
            'admin.suchak.accounts.verification-records.document',
        ]);

        $this->assertSame([], $unmapped, 'Unmapped admin GET routes: '.implode(', ', $unmapped));
    }

    public function test_admin_layout_renders_module_tabs_for_current_section(): void
    {
        $superAdmin = User::factory()->create([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ]);

        $response = $this->actingAs($superAdmin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('data-admin-sidebar-toggle', false);
        $response->assertSee('data-admin-mobile-topbar', false);
        $response->assertSee('data-admin-command-toggle', false);
        $response->assertSee('data-admin-command-palette', false);
        $response->assertSee('data-admin-section-sidebar', false);
        $response->assertSee('data-admin-command-items', false);
        $response->assertSee('aria-label="Admin module tabs"', false);
        $response->assertSee('Command Center', false);
        $response->assertSee('Monitoring', false);
        $response->assertSee('2 tools', false);
    }

    public function test_catalog_builds_sidebar_sections_and_command_items_for_allowed_tools(): void
    {
        $access = array_fill_keys(array_keys(AdminNavigationAccess::sections()), false);
        $access[AdminNavigationAccess::COMMAND_CENTER] = true;
        $access[AdminNavigationAccess::SUCHAK_NETWORK] = true;
        $access[AdminNavigationAccess::SYSTEM_ACCESS] = true;

        $sections = AdminNavigationCatalog::navigationSections($access, [
            'is_super_admin' => false,
        ], 'admin.dashboard');
        $labels = array_column($sections, 'label');

        $this->assertContains('Command Center', $labels);
        $this->assertContains('Suchak Network', $labels);
        $this->assertNotContains('System & Access', $labels);

        $commands = AdminNavigationCatalog::commandItems($access, [
            'is_super_admin' => false,
        ]);
        $commandLabels = array_column($commands, 'label');

        $this->assertContains('Suchak Network / Dashboard', $commandLabels);
        $this->assertNotContains('System & Access / Admin Capabilities', $commandLabels);
    }

    public function test_module_tabs_respect_section_visibility(): void
    {
        $access = array_fill_keys(array_keys(AdminNavigationAccess::sections()), false);
        $access[AdminNavigationAccess::COMMAND_CENTER] = true;

        $module = AdminNavigationCatalog::forRouteName('admin.matching-engine.fields', $access);

        $this->assertNull($module);
    }

    public function test_catalog_resolves_route_section_for_route_level_enforcement(): void
    {
        $this->assertSame(
            AdminNavigationAccess::MATCHING_DISCOVERY,
            AdminNavigationCatalog::sectionForRouteName('admin.matching-engine.fields.save')
        );
        $this->assertSame(
            AdminNavigationAccess::SUCHAK_NETWORK,
            AdminNavigationCatalog::sectionForRouteName('admin.suchak.accounts.approve')
        );
        $this->assertNull(AdminNavigationCatalog::sectionForRouteName('admin.unmapped.example'));
    }

    public function test_route_level_section_enforcement_blocks_hidden_sections(): void
    {
        $admin = User::factory()->create([
            'is_admin' => false,
            'admin_role' => 'data_admin',
        ]);

        DB::table('admin_capabilities')->insert($this->capabilityRow($admin, [
            AdminNavigationAccess::COMMAND_CENTER => true,
        ]));

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.matching-engine.overview'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.suchak.dashboard'))->assertForbidden();
    }

    public function test_route_level_section_enforcement_allows_visible_section_direct_url(): void
    {
        $admin = User::factory()->create([
            'is_admin' => false,
            'admin_role' => 'data_admin',
        ]);

        DB::table('admin_capabilities')->insert($this->capabilityRow($admin, [
            AdminNavigationAccess::COMMAND_CENTER => true,
            AdminNavigationAccess::MATCHING_DISCOVERY => true,
        ]));

        $this->actingAs($admin)->get(route('admin.matching-engine.overview'))->assertOk();
    }

    public function test_module_tabs_respect_fine_grained_capabilities(): void
    {
        $access = array_fill_keys(array_keys(AdminNavigationAccess::sections()), true);

        $withoutCapability = AdminNavigationCatalog::forRouteName('admin.verification-tags.index', $access, [
            'can_manage_verification_tags' => false,
            'can_manage_serious_intents' => false,
        ]);
        $withoutLabels = array_column($withoutCapability['tabs'] ?? [], 'label');

        $this->assertNotContains('Verification Tags', $withoutLabels);
        $this->assertNotContains('Serious Intents', $withoutLabels);

        $withCapability = AdminNavigationCatalog::forRouteName('admin.verification-tags.index', $access, [
            'can_manage_verification_tags' => true,
            'can_manage_serious_intents' => false,
        ]);
        $withLabels = array_column($withCapability['tabs'] ?? [], 'label');

        $this->assertContains('Verification Tags', $withLabels);
        $this->assertNotContains('Serious Intents', $withLabels);
    }

    private function navHtml(string $html): string
    {
        return Str::between($html, '<nav', '</nav>');
    }

    /**
     * @param  array<string, bool>  $overrides
     * @return array<string, mixed>
     */
    private function capabilityRow(User $admin, array $overrides = []): array
    {
        $row = [
            'admin_id' => $admin->id,
            'can_manage_verification_tags' => false,
            'can_manage_serious_intents' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach (AdminNavigationAccess::columns() as $section => $column) {
            $row[$column] = (bool) ($overrides[$section] ?? false);
        }

        return $row;
    }
}
