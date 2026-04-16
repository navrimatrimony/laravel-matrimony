<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Services\Showcase\ShowcaseIncomingInterestResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseIncomingInterestResponderTest extends TestCase
{
    use RefreshDatabase;

    public function test_responder_noop_when_incoming_auto_disabled(): void
    {
        AdminSetting::setValue('showcase_interest_incoming_auto_respond_enabled', '0');

        $svc = app(ShowcaseIncomingInterestResponderService::class);
        $r = $svc->processPending(20);
        $this->assertSame(0, $r['accepted']);
        $this->assertSame(0, $r['rejected']);
    }

    public function test_artisan_command_runs(): void
    {
        $this->artisan('showcase:respond-incoming-interests')->assertSuccessful();
    }

    public function test_outgoing_artisan_command_runs(): void
    {
        $this->artisan('showcase:send-outgoing-interests')->assertSuccessful();
    }
}
