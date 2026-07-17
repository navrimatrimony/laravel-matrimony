<?php

namespace Tests\Feature\Suchak;

use App\Support\Suchak\SuchakMvpFeatures;
use Tests\TestCase;

class SuchakMvpFeaturesTest extends TestCase
{
    public function test_mvp_config_hides_future_nav_sections_and_sharing_tab(): void
    {
        $this->assertFalse(SuchakMvpFeatures::navSectionVisible('network'));
        $this->assertFalse(SuchakMvpFeatures::navSectionVisible('tools'));
        $this->assertTrue(SuchakMvpFeatures::navSectionVisible('work'));
        $this->assertFalse(SuchakMvpFeatures::dashboardTabVisible('sharing'));
        $this->assertTrue(SuchakMvpFeatures::dashboardTabVisible('work'));
        $this->assertFalse(SuchakMvpFeatures::adminLinkVisible('retention'));
        $this->assertFalse(SuchakMvpFeatures::adminLinkVisible('academy'));
        $this->assertContains('work', SuchakMvpFeatures::visibleDashboardTabs());
        $this->assertNotContains('sharing', SuchakMvpFeatures::visibleDashboardTabs());
    }
}
