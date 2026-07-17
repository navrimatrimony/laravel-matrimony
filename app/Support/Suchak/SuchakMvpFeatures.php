<?php

namespace App\Support\Suchak;

class SuchakMvpFeatures
{
    public static function navSectionVisible(string $section): bool
    {
        return (bool) config("suchak_mvp.nav.{$section}", true);
    }

    public static function navSubitemVisible(string $key): bool
    {
        return (bool) config("suchak_mvp.nav_subitems.{$key}", true);
    }

    public static function dashboardTabVisible(string $tab): bool
    {
        return (bool) config("suchak_mvp.dashboard_tabs.{$tab}", true);
    }

    /**
     * @return list<string>
     */
    public static function visibleDashboardTabs(): array
    {
        return collect(config('suchak_mvp.dashboard_tabs', []))
            ->filter(fn (mixed $visible): bool => (bool) $visible)
            ->keys()
            ->values()
            ->all();
    }

    public static function dashboardPanelVisible(string $panel): bool
    {
        return (bool) config("suchak_mvp.dashboard_panels.{$panel}", true);
    }

    public static function adminLinkVisible(string $link): bool
    {
        return (bool) config("suchak_mvp.admin_links.{$link}", true);
    }
}
