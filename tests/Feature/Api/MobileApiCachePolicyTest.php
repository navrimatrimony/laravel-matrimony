<?php

namespace Tests\Feature\Api;

use App\Models\MasterGender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileApiCachePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_mobile_lookup_returns_cache_headers_and_supports_etag_revalidation(): void
    {
        MasterGender::query()->updateOrCreate(
            ['key' => 'male'],
            ['label' => 'Male', 'is_active' => true]
        );

        $response = $this->getJson('/api/v1/genders')->assertOk();

        $response->assertHeader('X-Mobile-Cache-Policy', 'public')
            ->assertHeader('X-Mobile-Cache-TTL', '43200')
            ->assertHeader('X-Mobile-Cache-Tags', 'master-genders');

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=43200', (string) $response->headers->get('Cache-Control'));

        $etag = (string) $response->headers->get('ETag');
        $this->assertStringStartsWith('W/"mobile-api-cache-v1-', $etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/v1/genders')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
    }

    public function test_authenticated_mobile_lookup_returns_private_cache_headers(): void
    {
        Sanctum::actingAs(User::factory()->create());

        DB::table('master_income_currencies')->updateOrInsert(
            ['code' => 'INR'],
            [
                'symbol' => 'Rs',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $response = $this->getJson('/api/v1/onboarding/lookups/income-options')->assertOk();

        $response->assertHeader('X-Mobile-Cache-Policy', 'private')
            ->assertHeader('X-Mobile-Cache-TTL', '43200')
            ->assertHeader('X-Mobile-Cache-Tags', 'onboarding-income-options');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=43200', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Authorization', (string) $response->headers->get('Vary'));
    }
}
