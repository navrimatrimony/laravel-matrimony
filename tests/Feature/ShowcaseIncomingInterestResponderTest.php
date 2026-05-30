<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Services\Showcase\ShowcaseIncomingInterestResponderService;
use App\Services\Showcase\ShowcaseOutgoingInterestSenderService;
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

    public function test_outgoing_command_uses_admin_batch_when_option_omitted(): void
    {
        AdminSetting::setValue('showcase_interest_outgoing_auto_batch_per_run', '200');

        $mock = $this->mock(ShowcaseOutgoingInterestSenderService::class);
        $mock->shouldReceive('run')->once()->with(200)->andReturn(['created' => 0, 'skipped' => 0]);

        $this->artisan('showcase:send-outgoing-interests')->assertSuccessful();
    }

    public function test_outgoing_command_cli_batch_overrides_admin(): void
    {
        AdminSetting::setValue('showcase_interest_outgoing_auto_batch_per_run', '200');

        $mock = $this->mock(ShowcaseOutgoingInterestSenderService::class);
        $mock->shouldReceive('run')->once()->with(10)->andReturn(['created' => 0, 'skipped' => 0]);

        $this->artisan('showcase:send-outgoing-interests', ['--batch' => 10])->assertSuccessful();
    }
}
