<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppResponseRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_whatsapp_response_route_names_resolve(): void
    {
        $this->assertSame(url('/admin/whatsapp-response'), route('admin.whatsapp-response.index'));
        $this->assertSame(url('/admin/whatsapp-response/settings'), route('admin.whatsapp-response.settings.update'));
        $this->assertSame(url('/admin/whatsapp-response/pipeline-update'), route('admin.whatsapp-response.run-pipeline-update'));
        $this->assertSame(url('/admin/whatsapp-response/123/action'), route('admin.whatsapp-response.action', ['mediation_request' => 123]));
    }

    public function test_admin_can_open_whatsapp_response_page_without_live_sending(): void
    {
        config([
            'whatsapp.response_live_enabled' => false,
            'whatsapp.response_provider' => 'null',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp-response.index'))
            ->assertOk()
            ->assertSee('WhatsApp Response Requests', false)
            ->assertSee('No WhatsApp API messages are sent from this page.', false)
            ->assertSee('WhatsApp Response', false);
    }
}
