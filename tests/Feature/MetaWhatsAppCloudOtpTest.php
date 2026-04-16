<?php

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('live mobile verification sends otp template via meta graph api', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200),
    ]);

    config([
        'whatsapp.access_token' => 'test-token',
        'whatsapp.phone_number_id' => 'PHONE_ID_X',
        'whatsapp.otp_template_name' => 'otp_verify',
        'whatsapp.otp_template_language' => 'en_US',
        'whatsapp.default_country_code' => '91',
        'whatsapp.graph_version' => 'v22.0',
    ]);

    AdminSetting::setValue('mobile_verification_mode', 'live');

    $user = User::factory()->create([
        'mobile' => '9876543210',
    ]);

    $response = $this->actingAs($user)->post(route('mobile.verify.send'), [
        'mobile' => '9876543210',
    ]);

    $response->assertRedirect(route('mobile.verify'));
    $response->assertSessionHas('status', __('otp.otp_sent_via_whatsapp'));

    Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
        return str_contains($request->url(), 'graph.facebook.com')
            && str_contains($request->url(), 'PHONE_ID_X')
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer test-token');
    });
});

test('meta whatsapp webhook verify echoes challenge', function () {
    config(['whatsapp.verify_token' => 'my_verify_secret']);

    $query = http_build_query([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'my_verify_secret',
        'hub_challenge' => 'CHALLENGE123',
    ]);

    $response = $this->get('/api/webhooks/whatsapp?'.$query);

    $response->assertOk();
    $response->assertSee('CHALLENGE123', false);
});
