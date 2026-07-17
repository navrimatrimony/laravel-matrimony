<?php

namespace Tests\Feature\Suchak\Support;

use App\Models\User;

trait CreatesSuchakAdmin
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createSuchakSuperAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'is_admin' => true,
            'admin_role' => 'super_admin',
        ], $overrides));
    }

    /**
     * Restore pre-MVP navigation and dashboard surfaces for legacy regression tests.
     */
    protected function enableFullSuchakUiSurfaces(): void
    {
        config([
            'suchak_mvp.nav.network' => true,
            'suchak_mvp.nav.tools' => true,
            'suchak_mvp.nav_subitems.offline_camps' => true,
            'suchak_mvp.nav_subitems.export_retention' => true,
            'suchak_mvp.nav_subitems.training_academy' => true,
            'suchak_mvp.dashboard_tabs.sharing' => true,
            'suchak_mvp.dashboard_panels.workflow_whatsapp_templates' => true,
            'suchak_mvp.dashboard_panels.white_label_kit' => true,
            'suchak_mvp.admin_links.retention' => true,
            'suchak_mvp.admin_links.academy' => true,
        ]);
    }
}
